<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductStock;
use App\Support\BusinessException;
use App\Support\Numbering;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Órdenes de producción: un producto "fabricado" se arma con insumos (sus componentes).
 * Al completarse se descuentan los insumos y se ingresa el producto terminado al stock.
 */
class ProductionService
{
    public function __construct(
        private StockService $stock,
        private ProductStructureService $structure,
        private PriceUpdateService $updates,
        private TenantContext $context,
    ) {}

    public function create(Product $product, float $quantity, ?string $dueDate, ?string $notes): ProductionOrder
    {
        if ($product->type !== 'manufactured') {
            throw new BusinessException("\"{$product->name}\" no es un producto fabricado.");
        }
        if (! $product->components()->exists()) {
            throw new BusinessException("Cargá los insumos de \"{$product->name}\" antes de producirlo.");
        }
        if ($quantity <= 0) {
            throw new BusinessException('La cantidad debe ser mayor a cero.');
        }

        return DB::transaction(fn () => ProductionOrder::create([
            'branch_id' => $this->context->branchId(),
            'product_id' => $product->id,
            'user_id' => auth()->id(),
            'number' => Numbering::next('production_orders', ['tenant_id' => $this->context->id()]),
            'quantity' => $quantity,
            'due_date' => $dueDate,
            'notes' => $notes,
            'status' => 'pending',
        ]));
    }

    /**
     * Insumos necesarios para un conjunto de órdenes, comparados con el stock de la sucursal.
     *
     * @return Collection<int, array{id:int, code:string, name:string, unit:?string, required:float, stock:float, missing:float, cost:float}>
     */
    public function requirements(Collection $orders, int $branchId): Collection
    {
        $orders = $orders instanceof \Illuminate\Database\Eloquent\Collection ? $orders : new \Illuminate\Database\Eloquent\Collection($orders->all());
        $orders->loadMissing('product.components.component.unit');
        $need = [];
        foreach ($orders as $o) {
            foreach ($o->product->components as $c) {
                if (! $c->component) {
                    continue;
                }
                $id = $c->component_id;
                $need[$id] ??= ['component' => $c->component, 'required' => 0];
                $need[$id]['required'] += (float) $o->quantity * (float) $c->quantity;
            }
        }
        $stocks = ProductStock::where('branch_id', $branchId)->whereIn('product_id', array_keys($need))->pluck('quantity', 'product_id');

        return collect($need)->map(function ($n, $id) use ($stocks) {
            $stock = (float) ($stocks[$id] ?? 0);
            $req = round($n['required'], 3);

            return [
                'id' => $id,
                'code' => $n['component']->code,
                'name' => $n['component']->name,
                'unit' => $n['component']->unit?->abbreviation,
                'required' => $req,
                'stock' => $stock,
                'missing' => max(0, round($req - $stock, 3)),
                'cost' => (float) $n['component']->cost,
            ];
        })->sortByDesc('missing')->values();
    }

    public function complete(ProductionOrder $order): ProductionOrder
    {
        if ($order->status !== 'pending') {
            throw new BusinessException('La orden ya fue completada o cancelada.');
        }

        return DB::transaction(function () use ($order) {
            $product = Product::with('components.component')->findOrFail($order->product_id);
            $qty = (float) $order->quantity;
            $unitCost = $this->structure->componentsCost($product);

            foreach ($product->components as $c) {
                if ($c->component) {
                    $this->stock->move($c->component, $order->branch_id, -round($qty * (float) $c->quantity, 3), 'production_out', $order, null,
                        "Producción #{$order->number} ({$product->code})", true);
                }
            }
            $this->stock->move($product, $order->branch_id, $qty, 'production_in', $order, $unitCost, "Producción #{$order->number}");

            // costo promedio ponderado del producto terminado
            $q0 = max($this->stock->totalQuantity($product->id) - $qty, 0);
            $avg0 = (float) $product->average_cost ?: (float) $product->cost;
            $avg = ($q0 + $qty) > 0 ? ($q0 * $avg0 + $qty * $unitCost) / ($q0 + $qty) : $unitCost;
            Product::whereKey($product->id)->update(['average_cost' => round($avg, 4), 'last_purchase_cost' => $unitCost]);

            // el costo de reposición pasa a ser el de los insumos; el precio se revisa según el margen objetivo
            if (abs($unitCost - (float) $product->cost) > 0.0001) {
                $this->updates->applyChanges('production', "Producción #{$order->number}: {$product->name}", collect([[
                    'product' => $product, 'new_cost' => $unitCost, 'new_price' => (float) $product->price, 'new_markup' => (float) $product->markup,
                ]]), [], null, $order->id);
            }

            $order->update(['status' => 'completed', 'completed_at' => now(), 'unit_cost' => $unitCost]);

            return $order;
        });
    }

    public function cancel(ProductionOrder $order): void
    {
        if ($order->status !== 'pending') {
            throw new BusinessException('Solo se pueden cancelar órdenes pendientes.');
        }
        $order->update(['status' => 'cancelled']);
    }
}
