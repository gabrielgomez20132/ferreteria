<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use App\Support\BusinessException;

/**
 * Toda variación de stock pasa por aquí y deja un movimiento con el saldo resultante.
 */
class StockService
{
    public const TYPES = [
        'initial' => 'Stock inicial',
        'purchase' => 'Compra',
        'sale' => 'Venta',
        'return' => 'Devolución de cliente',
        'cancel' => 'Anulación',
        'adjustment' => 'Ajuste',
        'transfer_in' => 'Transferencia entrante',
        'transfer_out' => 'Transferencia saliente',
        'inventory' => 'Inventario físico',
        'purchase_cancel' => 'Anulación de compra',
        'production_in' => 'Producción (ingreso)',
        'production_out' => 'Producción (consumo de insumos)',
    ];

    public function __construct(private TenantContext $context) {}

    public function move(
        Product $product,
        int $branchId,
        float $quantity,
        string $type,
        ?Model $reference = null,
        ?float $unitCost = null,
        ?string $notes = null,
        bool $checkAvailability = false,
    ): ?StockMovement {
        if (! ($product->track_stock ?? true) || abs($quantity) < 0.0000001) {
            return null;
        }

        $stock = ProductStock::where('product_id', $product->id)->where('branch_id', $branchId)->lockForUpdate()->first();
        if (! $stock) {
            $stock = ProductStock::create([
                'tenant_id' => $product->tenant_id,
                'product_id' => $product->id,
                'branch_id' => $branchId,
                'quantity' => 0,
            ]);
        }

        $newQty = round((float) $stock->quantity + $quantity, 3);
        if ($checkAvailability && $quantity < 0 && $newQty < 0 && ! $this->context->setting('allow_negative_stock', false)) {
            throw new BusinessException("Stock insuficiente de \"{$product->name}\". Disponible: ".rtrim(rtrim(number_format((float) $stock->quantity, 3, ',', '.'), '0'), ','));
        }

        $stock->update(['quantity' => $newQty]);

        return StockMovement::create([
            'tenant_id' => $product->tenant_id,
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $newQty,
            'unit_cost' => $unitCost ?? (float) $product->cost,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    /**
     * Igual que move(), pero si el producto es un kit mueve el stock de cada componente.
     */
    public function moveProduct(
        Product $product,
        int $branchId,
        float $quantity,
        string $type,
        ?Model $reference = null,
        ?float $unitCost = null,
        ?string $notes = null,
        bool $checkAvailability = false,
    ): void {
        if ($product->type !== 'kit') {
            $this->move($product, $branchId, $quantity, $type, $reference, $unitCost, $notes, $checkAvailability);

            return;
        }
        $product->loadMissing('components.component');
        if ($product->components->isEmpty()) {
            throw new BusinessException("El kit \"{$product->name}\" no tiene componentes cargados.");
        }
        foreach ($product->components as $c) {
            if ($c->component) {
                $this->move($c->component, $branchId, round($quantity * (float) $c->quantity, 3), $type, $reference, null,
                    trim(($notes ?? '').' (kit '.$product->code.')'), $checkAvailability);
            }
        }
    }

    /** Fija el stock a una cantidad (ajustes e inventario) */
    public function setQuantity(Product $product, int $branchId, float $target, string $type, ?Model $reference = null, ?string $notes = null): ?StockMovement
    {
        $current = (float) (ProductStock::where('product_id', $product->id)->where('branch_id', $branchId)->value('quantity') ?? 0);

        return $this->move($product, $branchId, round($target - $current, 3), $type, $reference, null, $notes);
    }

    public function quantity(int $productId, int $branchId): float
    {
        return (float) (ProductStock::where('product_id', $productId)->where('branch_id', $branchId)->value('quantity') ?? 0);
    }

    public function totalQuantity(int $productId): float
    {
        return (float) ProductStock::where('product_id', $productId)->sum('quantity');
    }
}
