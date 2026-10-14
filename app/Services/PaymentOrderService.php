<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\PaymentOrderPurchase;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierMovement;
use App\Support\BusinessException;
use App\Support\Numbering;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Órdenes de pago a proveedores: uno o varios medios de pago (efectivo, transferencia, cheques…)
 * imputados a facturas de compra pendientes.
 */
class PaymentOrderService
{
    public function __construct(private CashService $cash, private TenantContext $context) {}

    /**
     * $data = [supplier_id, date, notes?, from_cash?,
     *          lines: [{method, amount, reference?, bank?, check_date?}],
     *          applications?: [purchase_id => amount]  (vacío = se imputa automáticamente por vencimiento)]
     */
    public function create(array $data): PaymentOrder
    {
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $lines = collect($data['lines'] ?? [])->filter(fn ($l) => (float) $l['amount'] > 0)->values();
        $total = round((float) $lines->sum('amount'), 2);
        if ($total <= 0) {
            throw new BusinessException('Cargá al menos un medio de pago con importe.');
        }

        return DB::transaction(function () use ($supplier, $lines, $total, $data) {
            $session = null;
            if (! empty($data['from_cash'])) {
                $session = $this->cash->openSessionFor(auth()->user(), $this->context->branchId());
                if (! $session) {
                    throw new BusinessException('No hay una caja abierta para registrar la salida del dinero.');
                }
            }

            $order = PaymentOrder::create([
                'supplier_id' => $supplier->id,
                'branch_id' => $this->context->branchId(),
                'user_id' => auth()->id(),
                'cash_session_id' => $session?->id,
                'number' => Numbering::next('payment_orders', ['tenant_id' => $this->context->id()]),
                'date' => $data['date'] ?? now()->toDateString(),
                'total' => $total,
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($lines as $l) {
                $order->lines()->create([
                    'method' => $l['method'],
                    'amount' => $l['amount'],
                    'reference' => $l['reference'] ?? null,
                    'bank' => $l['bank'] ?? null,
                    'check_date' => $l['check_date'] ?? null,
                ]);
                if ($session) {
                    $this->cash->record($session, 'supplier_payment', 'out', $l['method'], (float) $l['amount'], "Orden de pago #{$order->number} - {$supplier->name}", $order);
                }
            }

            // ---- imputación a facturas ----
            $pending = Purchase::where('supplier_id', $supplier->id)->where('status', 'confirmed')
                ->whereColumn('paid_amount', '<', 'total')->orderBy('due_date')->orderBy('date')->lockForUpdate()->get()->keyBy('id');
            $applications = collect($data['applications'] ?? [])->map(fn ($v) => round((float) $v, 2))->filter(fn ($v) => $v > 0);
            if ($applications->isEmpty()) {
                // automática: de la factura más vieja a la más nueva
                $left = $total;
                foreach ($pending as $p) {
                    if ($left <= 0) {
                        break;
                    }
                    $apply = min($left, $p->pendingAmount());
                    $applications[$p->id] = $apply;
                    $left -= $apply;
                }
            }
            if ($applications->sum() > $total + 0.01) {
                throw new BusinessException('Lo imputado a facturas supera el total de la orden de pago.');
            }
            foreach ($applications as $purchaseId => $amount) {
                $p = $pending->get($purchaseId) ?? throw new BusinessException('Una de las facturas no está pendiente de pago.');
                if ($amount > $p->pendingAmount() + 0.01) {
                    throw new BusinessException("El importe imputado a {$p->voucher_type} {$p->voucher_number} supera su saldo.");
                }
                PaymentOrderPurchase::create(['payment_order_id' => $order->id, 'purchase_id' => $p->id, 'amount' => $amount]);
                $p->update(['paid_amount' => round((float) $p->paid_amount + $amount, 2)]);
            }

            // ---- cuenta corriente del proveedor ----
            $supplier = Supplier::lockForUpdate()->find($supplier->id);
            $balance = round((float) $supplier->balance - $total, 2);
            $supplier->update(['balance' => $balance]);
            SupplierMovement::create([
                'supplier_id' => $supplier->id,
                'user_id' => auth()->id(),
                'type' => 'payment',
                'description' => "Orden de pago #{$order->number}",
                'debit' => 0,
                'credit' => $total,
                'balance_after' => $balance,
                'payment_method' => $lines->count() === 1 ? $lines->first()['method'] : 'mixed',
                'reference_type' => PaymentOrder::class,
                'reference_id' => $order->id,
            ]);

            return $order;
        });
    }

    public function cancel(PaymentOrder $order): void
    {
        if ($order->status !== 'confirmed') {
            throw new BusinessException('La orden de pago ya está anulada.');
        }
        DB::transaction(function () use ($order) {
            if ($order->cash_session_id) {
                $session = $this->cash->openSessionFor(auth()->user(), $order->branch_id);
                if (! $session) {
                    throw new BusinessException('El pago salió de caja: abrí una caja para registrar el reingreso del dinero.');
                }
                foreach ($order->lines as $l) {
                    $this->cash->record($session, 'income', 'in', $l->method, (float) $l->amount, "Anulación orden de pago #{$order->number}", $order);
                }
            }
            foreach ($order->applications()->with('purchase')->get() as $a) {
                $a->purchase?->update(['paid_amount' => max(0, round((float) $a->purchase->paid_amount - (float) $a->amount, 2))]);
            }
            $supplier = Supplier::lockForUpdate()->find($order->supplier_id);
            $balance = round((float) $supplier->balance + (float) $order->total, 2);
            $supplier->update(['balance' => $balance]);
            SupplierMovement::create([
                'supplier_id' => $supplier->id, 'user_id' => auth()->id(), 'type' => 'cancel',
                'description' => "Anulación orden de pago #{$order->number}", 'debit' => $order->total, 'credit' => 0,
                'balance_after' => $balance, 'reference_type' => PaymentOrder::class, 'reference_id' => $order->id,
            ]);
            $order->update(['status' => 'cancelled']);
        });
    }
}
