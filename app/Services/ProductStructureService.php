<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductStock;
use App\Models\SupplierProduct;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kits (combos que se venden y descuentan sus componentes), productos fabricados (con insumos)
 * y variantes (un "modelo" con varias medidas/colores, cada una con su código, stock y precio).
 */
class ProductStructureService
{
    public function __construct(private PricingService $pricing) {}

    /**
     * Guarda los componentes de un kit o producto fabricado.
     *
     * @param  array<int, array{component_id:int, quantity:float}>  $components
     */
    public function syncComponents(Product $product, array $components): void
    {
        if (! in_array($product->type, ['kit', 'manufactured'], true)) {
            $product->components()->delete();

            return;
        }

        $ids = collect($components)->pluck('component_id')->map(fn ($v) => (int) $v);
        if ($ids->contains($product->id)) {
            throw new BusinessException('Un producto no puede ser componente de sí mismo.');
        }
        $found = Product::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($found as $c) {
            if ($c->type === 'kit') {
                throw new BusinessException("\"{$c->name}\" es un kit: no puede ser componente de otro producto.");
            }
            if ($c->has_variants) {
                throw new BusinessException("\"{$c->name}\" agrupa variantes: elegí la variante concreta.");
            }
        }

        DB::transaction(function () use ($product, $components, $found) {
            $product->components()->delete();
            foreach ($components as $c) {
                if (! $found->has((int) $c['component_id']) || (float) $c['quantity'] <= 0) {
                    continue;
                }
                ProductComponent::create([
                    'product_id' => $product->id,
                    'component_id' => (int) $c['component_id'],
                    'quantity' => $c['quantity'],
                ]);
            }
            if ($product->type === 'kit') {
                // el kit no tiene stock propio: se descuenta el de sus componentes
                Product::whereKey($product->id)->update(['track_stock' => false]);
            }
        });
    }

    /** Costo de un kit / producto fabricado = suma de (costo del insumo × cantidad) */
    public function componentsCost(Product $product): float
    {
        $product->loadMissing('components.component:id,cost');

        return round($product->components->sum(fn ($c) => (float) $c->quantity * (float) ($c->component?->cost ?? 0)), 4);
    }

    /** Stock disponible de un kit en una sucursal (cuántos kits se pueden armar) */
    public function kitAvailable(Product $kit, int $branchId): float
    {
        $kit->loadMissing('components.component');
        if ($kit->components->isEmpty()) {
            return 0;
        }
        $stocks = ProductStock::where('branch_id', $branchId)->whereIn('product_id', $kit->components->pluck('component_id'))->pluck('quantity', 'product_id');
        $min = null;
        foreach ($kit->components as $c) {
            if (! ($c->component?->track_stock ?? true)) {
                continue;
            }
            $possible = floor((float) ($stocks[$c->component_id] ?? 0) / (float) $c->quantity);
            $min = $min === null ? $possible : min($min, $possible);
        }

        return max(0, (float) ($min ?? 0));
    }

    /**
     * Recalcula el costo de los kits que usan alguno de estos productos.
     * El precio no se toca: si el margen queda desfasado, el kit aparece en "Precios a revisar".
     */
    public function refreshKitCosts(array $componentIds): void
    {
        if (! $componentIds) {
            return;
        }
        $kitIds = ProductComponent::whereIn('component_id', $componentIds)->pluck('product_id')->unique();
        foreach (Product::whereIn('id', $kitIds)->where('type', 'kit')->get() as $kit) {
            $cost = $this->componentsCost($kit);
            if (abs($cost - (float) $kit->cost) > 0.0001) {
                Product::whereKey($kit->id)->update(['cost' => $cost, 'average_cost' => $cost, 'cost_updated_at' => now()]);
            }
        }
    }

    /**
     * Genera las variantes de un producto "modelo" combinando atributos.
     * Ej.: ["Medida" => ["6x40", "8x50"], "Terminación" => ["Zincado", "Negro"]] → 4 variantes.
     *
     * @return int cantidad de variantes nuevas
     */
    public function generateVariants(Product $parent, array $attributes, array $options = []): int
    {
        if ($parent->variant_of_id) {
            throw new BusinessException('Una variante no puede tener sus propias variantes.');
        }
        $attributes = collect($attributes)
            ->mapWithKeys(fn ($values, $name) => [trim($name) => collect($values)->map(fn ($v) => trim($v))->filter()->unique()->values()->all()])
            ->filter(fn ($values, $name) => $name !== '' && $values);
        if ($attributes->isEmpty()) {
            throw new BusinessException('Indicá al menos un atributo con sus valores (ej.: Medida: 6x40, 8x50).');
        }

        // producto cartesiano de los valores
        $combos = [[]];
        foreach ($attributes as $name => $values) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($values as $v) {
                    $next[] = $combo + [$name => $v];
                }
            }
            $combos = $next;
        }
        if (count($combos) > 200) {
            throw new BusinessException('Demasiadas combinaciones ('.count($combos).'). El máximo es 200.');
        }

        return DB::transaction(function () use ($parent, $combos, $options) {
            $existing = $parent->variants()->get()->map(fn ($v) => json_encode($v->variant_attributes))->all();
            $parentSp = SupplierProduct::where('product_id', $parent->id)->get();
            $created = 0;
            foreach ($combos as $combo) {
                if (in_array(json_encode($combo), $existing, true)) {
                    continue;
                }
                $suffix = collect($combo)->map(fn ($v) => Str::upper(Str::slug($v, '')))->implode('-');
                $code = $this->uniqueCode(Str::limit($parent->code.'-'.$suffix, 50, ''));
                $variant = Product::create([
                    'type' => $parent->type === 'kit' ? 'simple' : $parent->type,
                    'variant_of_id' => $parent->id,
                    'variant_attributes' => $combo,
                    'code' => $code,
                    'name' => $parent->name.' '.implode(' / ', $combo),
                    'description' => $parent->description,
                    'category_id' => $parent->category_id,
                    'brand_id' => $parent->brand_id,
                    'unit_id' => $parent->unit_id,
                    'supplier_id' => $parent->supplier_id,
                    'cost' => $options['cost'] ?? $parent->cost,
                    'average_cost' => $options['cost'] ?? $parent->cost,
                    'vat_rate' => $parent->vat_rate,
                    'markup' => $parent->markup,
                    'price' => $options['price'] ?? $parent->price,
                    'allow_fraction' => $parent->allow_fraction,
                    'purchase_unit_name' => $parent->purchase_unit_name,
                    'purchase_unit_factor' => $parent->purchase_unit_factor,
                    'min_stock' => $parent->min_stock,
                    'location' => $parent->location,
                    'track_stock' => true,
                    'cost_updated_at' => now(),
                    'price_updated_at' => now(),
                ]);
                // hereda el proveedor (sin código: cada variante suele tener su propio código de proveedor)
                foreach ($parentSp as $sp) {
                    SupplierProduct::create([
                        'supplier_id' => $sp->supplier_id, 'product_id' => $variant->id, 'list_price' => $sp->list_price,
                        'pack_quantity' => $sp->pack_quantity, 'cost' => $sp->cost, 'is_preferred' => $sp->is_preferred,
                    ]);
                }
                $created++;
            }
            // el modelo deja de venderse y de llevar stock: lo hacen sus variantes
            $parent->update(['has_variants' => true, 'track_stock' => false]);

            return $created;
        });
    }

    private function uniqueCode(string $code): string
    {
        $base = $code;
        $i = 2;
        while (Product::withTrashed()->where('code', $code)->exists()) {
            $code = Str::limit($base, 46, '').'-'.$i++;
        }

        return $code;
    }
}
