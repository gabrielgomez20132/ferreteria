<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessException;

class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private AccountService $accounts,
        private PricingService $pricing,
        private PriceUpdateService $updates,
        private TenantContext $context,
    ) {}

    /**
     * $data = [supplier_id, voucher_type, voucher_number, date, due_date?, other_taxes, extra_costs,
     *          update_costs, price_action, purchase_order_id?, notes,
     *          items: [{product_id, quantity, unit_factor, unit_cost, discount_percent, vat_rate, purchase_order_item_id?}]]
     */
    public function create(array $data): Purchase
    {
        $branchId = $this->context->branchId();

        return DB::transaction(function () use ($data, $branchId) {
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

            $lines = [];
            $subtotal = 0;
            $vat = 0;
            foreach ($data['items'] as $i) {
                $product = $products->get($i['product_id']) ?? throw new BusinessException('Producto inexistente');
                $qty = (float) $i['quantity'];
                $factor = (float) ($i['unit_factor'] ?? 1) ?: 1;
                if ($qty <= 0) {
                    throw new BusinessException("Cantidad inválida para {$product->name}");
                }
                $net = round($qty * (float) $i['unit_cost'] * (1 - (float) ($i['discount_percent'] ?? 0) / 100), 2);
                $subtotal += $net;
                $vat += $net * (float) ($i['vat_rate'] ?? $product->vat_rate) / 100;
                $lines[] = ['product' => $product, 'qty' => $qty, 'factor' => $factor, 'net' => $net, 'raw' => $i];
            }

            $extra = (float) ($data['extra_costs'] ?? 0);
            $otherTaxes = (float) ($data['other_taxes'] ?? 0);
            $vat = round($vat, 2);
            $total = round($subtotal + $vat + $otherTaxes + $extra, 2);

            $purchase = Purchase::create([
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'user_id' => auth()->id(),
                'voucher_type' => $data['voucher_type'] ?? 'FA',
                'voucher_number' => $data['voucher_number'] ?? null,
                'date' => $data['date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? now()->addDays($supplier->payment_term_days)->toDateString(),
                'subtotal' => round($subtotal, 2),
                'vat_amount' => $vat,
                'other_taxes' => $otherTaxes,
                'extra_costs' => $extra,
                'total' => $total,
                'status' => 'confirmed',
                'update_costs' => (bool) ($data['update_costs'] ?? true),
                'price_action' => $data['price_action'] ?? 'review',
                'notes' => $data['notes'] ?? null,
            ]);

            $changes = collect();
            foreach ($lines as $l) {
                /** @var Product $product */
                $product = $l['product'];
                $stockQty = round($l['qty'] * $l['factor'], 3);
                $share = $subtotal > 0 ? $extra * ($l['net'] / $subtotal) : 0;  // prorrateo de fletes/gastos
                $newCost = $stockQty > 0 ? round(($l['net'] + $share) / $stockQty, 4) : 0;
                $previous = (float) $product->cost;

                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id' => $product->id,
                    'purchase_order_item_id' => $l['raw']['purchase_order_item_id'] ?? null,
                    'description' => $product->name,
                    'quantity' => $l['qty'],
                    'unit_factor' => $l['factor'],
                    'stock_quantity' => $stockQty,
                    'unit_cost' => $l['raw']['unit_cost'],
                    'discount_percent' => $l['raw']['discount_percent'] ?? 0,
                    'vat_rate' => $l['raw']['vat_rate'] ?? $product->vat_rate,
                    'subtotal' => $l['net'],
                    'previous_cost' => $previous,
                    'new_cost' => $newCost,
                ]);

                // Costo promedio ponderado sobre el stock total de todas las sucursales
                $q0 = max($this->stock->totalQuantity($product->id), 0);
                $avg0 = (float) $product->average_cost ?: $previous;
                $avg = ($q0 + $stockQty) > 0 ? ($q0 * $avg0 + $stockQty * $newCost) / ($q0 + $stockQty) : $newCost;

                $this->stock->move($product, $branchId, $stockQty, 'purchase', $purchase, $newCost, "Compra {$purchase->voucher_type} {$purchase->voucher_number}");

                Product::whereKey($product->id)->update([
                    'average_cost' => round($avg, 4),
                    'last_purchase_cost' => $newCost,
                    'supplier_id' => $product->supplier_id ?? $supplier->id,
                ]);

                $sp = SupplierProduct::firstOrNew(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
                $sp->fill(['tenant_id' => $product->tenant_id, 'cost' => $newCost, 'last_price_at' => now()]);
                if (! $sp->exists) {
                    $sp->list_price = $l['raw']['unit_cost'];
                    $sp->pack_quantity = $l['factor'];
                }
                $sp->save();

                if (! empty($l['raw']['purchase_order_item_id'])) {
                    $poi = PurchaseOrderItem::find($l['raw']['purchase_order_item_id']);
                    $poi?->update(['received_quantity' => (float) $poi->received_quantity + $stockQty]); // los pedidos se expresan en unidades de stock
                }

                if ($purchase->update_costs && abs($newCost - $previous) > 0.0001) {
                    $r = $this->pricing->resolvePrice($product, $newCost, $purchase->price_action);
                    $changes->push(['product' => $product, 'new_cost' => $newCost, 'new_price' => $r['price'], 'new_markup' => $r['markup']]);
                }
            }

            if ($changes->isNotEmpty()) {
                $this->updates->applyChanges('purchase', "Compra {$purchase->voucher_type} {$purchase->voucher_number} - {$supplier->name}",
                    $changes, ['price_action' => $purchase->price_action], $supplier->id, $purchase->id);
            }

            $this->accounts->chargeSupplier($supplier, $total, 'purchase', "Compra {$purchase->voucher_type} {$purchase->voucher_number}", $purchase, $purchase->due_date?->toDateString());

            if ($purchase->purchase_order_id) {
                $this->refreshOrderStatus(PurchaseOrder::find($purchase->purchase_order_id));
            }

            return $purchase;
        });
    }

    public function cancel(Purchase $purchase): Purchase
    {
        if ($purchase->status !== 'confirmed') {
            throw new BusinessException('La compra ya está anulada.');
        }

        return DB::transaction(function () use ($purchase) {
            foreach ($purchase->items()->with('product')->get() as $item) {
                $this->stock->move($item->product, $purchase->branch_id, -(float) $item->stock_quantity, 'purchase_cancel', $purchase, (float) $item->new_cost, 'Anulación de compra');
                if ($item->purchase_order_item_id && ($poi = PurchaseOrderItem::find($item->purchase_order_item_id))) {
                    $poi->update(['received_quantity' => max(0, (float) $poi->received_quantity - (float) $item->stock_quantity)]);
                }
            }
            $this->accounts->chargeSupplier($purchase->supplier, -(float) $purchase->total, 'cancel', "Anulación compra {$purchase->voucher_type} {$purchase->voucher_number}", $purchase);
            $purchase->update(['status' => 'cancelled']);
            if ($purchase->purchase_order_id) {
                $this->refreshOrderStatus(PurchaseOrder::find($purchase->purchase_order_id));
            }

            return $purchase;
        });
    }

    public function refreshOrderStatus(?PurchaseOrder $order): void
    {
        if (! $order || $order->status === 'cancelled') {
            return;
        }
        $items = $order->items()->get();
        $received = $items->sum(fn ($i) => min((float) $i->received_quantity, (float) $i->quantity));
        $ordered = $items->sum(fn ($i) => (float) $i->quantity);
        $status = $received <= 0 ? ($order->status === 'draft' ? 'draft' : 'sent') : ($received + 0.0001 >= $ordered ? 'received' : 'partial');
        $order->update(['status' => $status]);
    }

    /**
     * Sugerencias de compra: productos bajo mínimo en la sucursal, con su proveedor y costo más conveniente.
     */
    public function suggestions(int $branchId, ?int $supplierId = null)
    {
        $since = now()->subDays(30);

        return Product::query()
            ->where('products.is_active', true)->where('track_stock', true)
            ->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branchId))
            ->when($supplierId, fn ($q) => $q->where(fn ($w) => $w->where('products.supplier_id', $supplierId)
                ->orWhereExists(fn ($e) => $e->selectRaw(1)->from('supplier_products')->whereColumn('supplier_products.product_id', 'products.id')->where('supplier_products.supplier_id', $supplierId))))
            ->whereRaw('COALESCE(ps.quantity,0) <= COALESCE(ps.min_stock, products.min_stock)')
            ->whereRaw('COALESCE(ps.min_stock, products.min_stock) > 0')
            ->select('products.id', 'products.code', 'products.name', 'products.cost', 'products.supplier_id', 'products.purchase_unit_name', 'products.purchase_unit_factor')
            ->selectRaw('COALESCE(ps.quantity,0) as stock, COALESCE(ps.min_stock, products.min_stock) as min_stock, ps.max_stock')
            ->selectSub(fn ($q) => $q->from('stock_movements')->whereColumn('stock_movements.product_id', 'products.id')
                ->where('stock_movements.branch_id', $branchId)->where('type', 'sale')->where('created_at', '>=', $since)
                ->selectRaw('COALESCE(-SUM(quantity),0)'), 'sold_30d')
            ->with(['supplier:id,name', 'supplierProducts' => fn ($q) => $q->with('supplier:id,name')->orderBy('cost')])
            ->orderBy('products.name')
            ->limit(500)
            ->get()
            ->map(function ($p) {
                $target = max((float) ($p->max_stock ?? 0), (float) $p->min_stock * 2, (float) $p->sold_30d);
                $p->suggested_quantity = max(0, round($target - (float) $p->stock, 3));
                $best = $p->supplierProducts->firstWhere('cost', '>', 0);
                $p->best_supplier = $best ? ['id' => $best->supplier_id, 'name' => $best->supplier?->name, 'cost' => (float) $best->cost] : null;

                return $p;
            });
    }
}
