<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierDiscountRule;
use App\Models\SupplierProduct;
use Illuminate\Support\Collection;

/**
 * Resuelve qué bonificaciones aplica un proveedor a un artículo. Prioridad:
 *   1. Bonificación cargada en el artículo del proveedor (supplier_products.discount_x)
 *   2. Regla por marca + categoría  →  3. por marca  →  4. por categoría
 *   5. Bonificación general del proveedor
 */
class SupplierDiscounts
{
    /** @var array<int, Collection> */
    private array $rules = [];

    public function forProduct(Supplier $supplier, ?SupplierProduct $sp, ?int $brandId, ?int $categoryId): array
    {
        if ($sp && ($sp->discount_1 !== null || $sp->discount_2 !== null || $sp->discount_3 !== null)) {
            return [(float) $sp->discount_1, (float) $sp->discount_2, (float) $sp->discount_3];
        }

        $rules = $this->rules[$supplier->id] ??= SupplierDiscountRule::where('supplier_id', $supplier->id)->get();
        if ($rules->isNotEmpty() && ($brandId || $categoryId)) {
            $match = $rules->first(fn ($r) => $r->brand_id && $r->category_id && $r->brand_id === $brandId && $r->category_id === $categoryId)
                ?? $rules->first(fn ($r) => $r->brand_id && ! $r->category_id && $r->brand_id === $brandId)
                ?? $rules->first(fn ($r) => $r->category_id && ! $r->brand_id && $r->category_id === $categoryId);
            if ($match) {
                return $match->discounts();
            }
        }

        return $supplier->discounts();
    }
}
