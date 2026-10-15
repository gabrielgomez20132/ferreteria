<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Numbering;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\BusinessException;

class SaleService
{
    public function __construct(
        private StockService $stock,
        private CashService $cash,
        private AccountService $accounts,
        private PricingService $pricing,
        private TenantContext $context,
    ) {}

    /**
     * Registra una venta (o presupuesto) desde el punto de venta.
     *
     * $data = [
     *   type: sale|quote, customer_id?, price_list_id?, discount_percent?, notes?,
     *   quote_id? (venta que convierte un presupuesto), valid_until?, terms? (presupuestos),
     *   items: [{product_id, quantity, unit_price?, discount_percent?}],
     *   payments: [{method, amount, reference?}]
     * ]
     */
    public function create(array $data, User $user): Sale
    {
        $branchId = $this->context->branchId();
        $type = $data['type'] ?? 'sale';
        if ($this->context->branch?->type === 'warehouse' && $type === 'sale') {
            throw new BusinessException('Estás trabajando en un depósito: cambiá a una sucursal para vender.');
        }
        $isQuote = $type === 'quote';

        return DB::transaction(function () use ($data, $user, $branchId, $type, $isQuote) {
            $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
            $priceList = ! empty($data['price_list_id']) ? PriceList::find($data['price_list_id'])
                : ($customer?->priceList ?? PriceList::where('is_default', true)->first());

            // Venta que convierte un presupuesto
            $quote = null;
            if (! $isQuote && ! empty($data['quote_id'])) {
                $quote = Sale::where('type', 'quote')->lockForUpdate()->with('items')->findOrFail($data['quote_id']);
                if ($quote->status === 'converted') {
                    throw new BusinessException("El presupuesto #{$quote->number} ya se convirtió en venta.");
                }
            }

            $session = null;
            if (! $isQuote) {
                $session = $this->cash->openSessionFor($user, $branchId);
                if (! $session && $this->context->setting('require_cash_session', true)) {
                    throw new BusinessException('Debe abrir la caja antes de vender.');
                }
            }

            $c = $this->computeLines($data, $user, $priceList, $quote);
            ['lines' => $lines, 'subtotal' => $subtotal, 'costTotal' => $costTotal, 'globalDisc' => $globalDisc, 'discountAmount' => $discountAmount, 'total' => $total] = $c;

            // ---- Pagos ----
            $payments = collect($data['payments'] ?? [])->filter(fn ($p) => (float) $p['amount'] > 0)->values();
            $onAccount = (float) $payments->where('method', 'account')->sum('amount');
            $paid = (float) $payments->sum('amount');
            $change = 0;
            if (! $isQuote) {
                if ($paid + 0.001 < $total) {
                    throw new BusinessException('El pago es menor al total de la venta.');
                }
                $change = round($paid - $total, 2);
                $cashPaid = (float) $payments->where('method', 'cash')->sum('amount');
                if ($change > $cashPaid + 0.001) {
                    throw new BusinessException('Solo puede haber vuelto sobre pagos en efectivo.');
                }
                if ($onAccount > 0) {
                    if (! $customer) {
                        throw new BusinessException('Seleccione un cliente para vender en cuenta corriente.');
                    }
                    if (! $customer->allow_credit) {
                        throw new BusinessException('El cliente no tiene habilitada la cuenta corriente.');
                    }
                    if ((float) $customer->credit_limit > 0 && (float) $customer->balance + $onAccount > (float) $customer->credit_limit) {
                        throw new BusinessException('Supera el límite de crédito del cliente (saldo actual $'.number_format((float) $customer->balance, 2, ',', '.').').');
                    }
                }
            }

            $sale = Sale::create([
                'branch_id' => $branchId,
                'type' => $type,
                'number' => Numbering::next('sales', ['tenant_id' => $this->context->id(), 'branch_id' => $branchId, 'type' => $type]),
                'customer_id' => $customer?->id,
                'user_id' => $user->id,
                'cash_session_id' => $session?->id,
                'price_list_id' => $priceList?->id,
                'quote_id' => $quote?->id,
                'status' => $isQuote ? 'quote' : 'completed',
                'voucher_type' => $isQuote ? 'presupuesto' : ($data['voucher_type'] ?? 'ticket'),
                'valid_until' => $isQuote ? ($data['valid_until'] ?? now()->addDays((int) $this->context->setting('quote_validity_days', 7))->toDateString()) : null,
                'terms' => $isQuote ? ($data['terms'] ?? $this->context->setting('quote_terms')) : null,
                'public_token' => $isQuote ? Str::random(40) : null,
                'subtotal' => round($subtotal, 2),
                'discount_percent' => $globalDisc,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'cost_total' => round($costTotal, 2),
                'paid_amount' => $isQuote ? 0 : $paid,
                'change_amount' => $change,
                'on_account_amount' => $onAccount,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->createItems($sale, $lines);
            if (! $isQuote) {
                foreach ($lines as $l) {
                    $this->stock->moveProduct($l['product'], $branchId, -$l['qty'], 'sale', $sale, null, "Venta #{$sale->number}", true);
                }
            }

            if ($isQuote) {
                return $sale;
            }

            foreach ($payments as $p) {
                $sale->payments()->create(['method' => $p['method'], 'amount' => $p['amount'], 'reference' => $p['reference'] ?? null]);
                if ($session) {
                    $amount = (float) $p['amount'] - ($p['method'] === 'cash' ? $change : 0);
                    $this->cash->record($session, 'sale', 'in', $p['method'], $amount, "Venta #{$sale->number}", $sale);
                }
            }
            if ($onAccount > 0) {
                $this->accounts->chargeCustomer($customer, $onAccount, 'sale', "Venta #{$sale->number}", $sale);
            }

            if ($quote) {
                $quote->update(['status' => 'converted', 'converted_sale_id' => $sale->id, 'converted_at' => now(), 'rejected_at' => null, 'reject_reason' => null]);
            }

            return $sale;
        });
    }

    /** Modifica los ítems, el cliente y las condiciones de un presupuesto abierto (conserva número y link) */
    public function updateQuote(Sale $quote, array $data, User $user): Sale
    {
        if ($quote->type !== 'quote' || $quote->status !== 'quote') {
            throw new BusinessException('Solo se pueden modificar presupuestos pendientes o vencidos.');
        }

        return DB::transaction(function () use ($quote, $data, $user) {
            $quote->load('items');
            $customer = ! empty($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : null;
            $priceList = ! empty($data['price_list_id']) ? PriceList::find($data['price_list_id'])
                : ($customer?->priceList ?? PriceList::where('is_default', true)->first());
            $c = $this->computeLines($data, $user, $priceList, $quote);

            $quote->items()->delete();
            $this->createItems($quote, $c['lines']);
            $quote->update([
                'customer_id' => $customer?->id,
                'price_list_id' => $priceList?->id,
                'subtotal' => round($c['subtotal'], 2),
                'discount_percent' => $c['globalDisc'],
                'discount_amount' => $c['discountAmount'],
                'total' => $c['total'],
                'cost_total' => round($c['costTotal'], 2),
                'notes' => $data['notes'] ?? $quote->notes,
                // al modificarlo se renueva la validez si estaba vencido
                'valid_until' => $data['valid_until'] ?? ($quote->quote_state === 'expired'
                    ? now()->addDays((int) $this->context->setting('quote_validity_days', 7))->toDateString()
                    : $quote->valid_until?->toDateString()),
            ]);

            return $quote->fresh();
        });
    }

    /**
     * Calcula líneas y totales validando precios y descuentos.
     * Si viene de un presupuesto vigente, se respetan sus precios y descuentos aunque el usuario
     * no tenga permiso para cambiarlos (el precio quedó comprometido con el cliente).
     */
    private function computeLines(array $data, User $user, ?PriceList $priceList, ?Sale $quote = null): array
    {
        $honor = $quote && $quote->quote_state === 'pending';
        $quoted = $honor ? $quote->items->keyBy('product_id') : collect();
        $maxDiscount = $user->maxDiscount();
        $canOverride = $user->hasPermission('sales.price_override');
        $products = Product::with('prices', 'components.component')->whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

        $lines = [];
        $subtotal = 0;
        $costTotal = 0;
        foreach ($data['items'] as $item) {
            $product = $products->get($item['product_id']) ?? throw new BusinessException('Producto inexistente.');
            if ($product->has_variants) {
                throw new BusinessException("\"{$product->name}\" tiene variantes: elegí la medida/color concreta.");
            }
            $qty = round((float) $item['quantity'], 3);
            if ($qty <= 0) {
                throw new BusinessException("Cantidad inválida para \"{$product->name}\".");
            }
            if (! $product->allow_fraction && floor($qty) != $qty) {
                throw new BusinessException("\"{$product->name}\" no se vende fraccionado.");
            }
            $q = $quoted->get($product->id);
            $listPrice = $this->pricing->priceFor($product, $priceList);
            $unitPrice = $listPrice;
            if (isset($item['unit_price']) && abs((float) $item['unit_price'] - $listPrice) > 0.001) {
                $isQuotedPrice = $q && abs((float) $item['unit_price'] - (float) $q->unit_price) < 0.001;
                if (! $canOverride && ! $isQuotedPrice) {
                    throw new BusinessException('No tiene permiso para modificar precios.');
                }
                $unitPrice = round((float) $item['unit_price'], 2);
            }
            $disc = (float) ($item['discount_percent'] ?? 0);
            $isQuotedDisc = $q && abs($disc - (float) $q->discount_percent) < 0.001;
            if ($disc < 0 || ($disc > $maxDiscount && ! $isQuotedDisc)) {
                throw new BusinessException('Descuento no autorizado (máximo '.$maxDiscount.'%).');
            }
            $lineTotal = round($qty * $unitPrice * (1 - $disc / 100), 2);
            $subtotal += $lineTotal;
            $costTotal += $qty * (float) $product->cost;
            $lines[] = compact('product', 'qty', 'unitPrice', 'disc', 'lineTotal');
        }

        $globalDisc = (float) ($data['discount_percent'] ?? 0);
        $isQuotedGlobal = $honor && abs($globalDisc - (float) $quote->discount_percent) < 0.001;
        if ($globalDisc < 0 || ($globalDisc > $maxDiscount && ! $isQuotedGlobal)) {
            throw new BusinessException('Descuento no autorizado (máximo '.$maxDiscount.'%).');
        }
        $discountAmount = round($subtotal * $globalDisc / 100, 2);

        return [
            'lines' => $lines, 'subtotal' => $subtotal, 'costTotal' => $costTotal, 'globalDisc' => $globalDisc,
            'discountAmount' => $discountAmount, 'total' => round($subtotal - $discountAmount, 2),
        ];
    }

    private function createItems(Sale $sale, array $lines): void
    {
        foreach ($lines as $l) {
            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $l['product']->id,
                'code' => $l['product']->code,
                'description' => $l['product']->name,
                'quantity' => $l['qty'],
                'unit_price' => $l['unitPrice'],
                'discount_percent' => $l['disc'],
                'subtotal' => $l['lineTotal'],
                'unit_cost' => $l['product']->cost,
                'vat_rate' => $l['product']->vat_rate,
            ]);
        }
    }

    /** Anulación total con reversión de stock, caja y cuenta corriente */
    public function cancel(Sale $sale, User $user, string $reason): Sale
    {
        if ($sale->status !== 'completed') {
            throw new BusinessException('Solo se pueden anular ventas confirmadas.');
        }
        if ($sale->returns()->exists()) {
            throw new BusinessException('La venta tiene devoluciones registradas; no se puede anular.');
        }

        return DB::transaction(function () use ($sale, $user, $reason) {
            $session = $this->cash->openSessionFor($user, $sale->branch_id);
            $cashMethods = $sale->payments->where('method', '!=', 'account');
            if ($cashMethods->isNotEmpty() && ! $session) {
                throw new BusinessException('Debe tener una caja abierta para registrar la devolución del dinero.');
            }

            foreach ($sale->items as $item) {
                if ($item->product) {
                    $this->stock->moveProduct($item->product, $sale->branch_id, (float) $item->quantity, 'cancel', $sale, $item->product->type === 'kit' ? null : (float) $item->unit_cost, "Anulación venta #{$sale->number}");
                }
            }
            foreach ($cashMethods as $p) {
                $amount = (float) $p->amount - ($p->method === 'cash' ? (float) $sale->change_amount : 0);
                $this->cash->record($session, 'cancel', 'out', $p->method, $amount, "Anulación venta #{$sale->number}", $sale);
            }
            if ((float) $sale->on_account_amount > 0 && $sale->customer) {
                $this->accounts->creditCustomer($sale->customer, (float) $sale->on_account_amount, 'cancel', "Anulación venta #{$sale->number}", $sale);
            }

            $sale->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason]);
            // Si la venta venía de un presupuesto, el presupuesto vuelve a quedar abierto
            if ($sale->quote_id) {
                Sale::where('id', $sale->quote_id)->where('converted_sale_id', $sale->id)
                    ->update(['status' => 'quote', 'converted_sale_id' => null, 'converted_at' => null]);
            }

            return $sale;
        });
    }

    /**
     * Devolución parcial: $items = [sale_item_id => quantity], $restock indica si vuelve al stock.
     */
    public function returnItems(Sale $sale, array $items, string $refundMethod, User $user, ?string $reason = null, bool $restock = true): SaleReturn
    {
        if ($sale->status !== 'completed') {
            throw new BusinessException('Solo se pueden devolver ítems de ventas confirmadas.');
        }

        return DB::transaction(function () use ($sale, $items, $refundMethod, $user, $reason, $restock) {
            $total = 0;
            $lines = [];
            foreach ($sale->items as $item) {
                $qty = round((float) ($items[$item->id] ?? 0), 3);
                if ($qty <= 0) {
                    continue;
                }
                $available = (float) $item->quantity - (float) $item->returned_quantity;
                if ($qty > $available + 0.0001) {
                    throw new BusinessException("No puede devolver más de {$available} de \"{$item->description}\".");
                }
                $unit = (float) $item->subtotal / (float) $item->quantity * (1 - (float) $sale->discount_percent / 100);
                $sub = round($unit * $qty, 2);
                $total += $sub;
                $lines[] = [$item, $qty, round($unit, 2), $sub];
            }
            if (! $lines) {
                throw new BusinessException('Indique al menos un ítem a devolver.');
            }

            if ($refundMethod === 'account' && ! $sale->customer) {
                throw new BusinessException('Para acreditar en cuenta corriente la venta debe tener cliente.');
            }
            $session = $refundMethod === 'account' ? null : $this->cash->openSessionFor($user, $sale->branch_id);
            if ($refundMethod !== 'account' && ! $session) {
                throw new BusinessException('Debe tener una caja abierta para devolver dinero.');
            }

            $return = SaleReturn::create([
                'branch_id' => $sale->branch_id,
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                'total' => round($total, 2),
                'refund_method' => $refundMethod,
                'reason' => $reason,
            ]);

            foreach ($lines as [$item, $qty, $unit, $sub]) {
                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'subtotal' => $sub,
                    'restock' => $restock,
                ]);
                $item->update(['returned_quantity' => (float) $item->returned_quantity + $qty]);
                if ($restock && $item->product) {
                    $this->stock->moveProduct($item->product, $sale->branch_id, $qty, 'return', $return, $item->product->type === 'kit' ? null : (float) $item->unit_cost, "Devolución venta #{$sale->number}");
                }
            }

            if ($refundMethod === 'account') {
                $this->accounts->creditCustomer($sale->customer, $total, 'return', "Devolución venta #{$sale->number}", $return);
            } else {
                $this->cash->record($session, 'refund', 'out', $refundMethod, $total, "Devolución venta #{$sale->number}", $return);
            }
            $sale->update(['returned_amount' => (float) $sale->returned_amount + $total]);

            return $return;
        });
    }
}
