<?php

namespace App\Services;

use App\Models\PriceUpdate;
use App\Models\PriceUpdateItem;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductPriceHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessException;

/**
 * Aplica cambios de costo/precio en lotes auditados y reversibles.
 */
class PriceUpdateService
{
    public function __construct(private PricingService $pricing) {}

    /**
     * @param  Collection<int, array{product: Product, new_cost: float, new_price: float, new_markup: float}>  $changes
     */
    public function applyChanges(
        string $source,
        string $description,
        Collection $changes,
        array $options = [],
        ?int $supplierId = null,
        ?int $sourceId = null,
    ): ?PriceUpdate {
        $changes = $changes->filter(function ($c) {
            $p = $c['product'];

            return abs((float) $p->cost - $c['new_cost']) > 0.00001
                || abs((float) $p->price - $c['new_price']) > 0.001
                || abs((float) $p->markup - $c['new_markup']) > 0.001;
        });

        if ($changes->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($source, $description, $changes, $options, $supplierId, $sourceId) {
            $update = PriceUpdate::create([
                'user_id' => auth()->id(),
                'supplier_id' => $supplierId,
                'source' => $source,
                'source_id' => $sourceId,
                'description' => $description,
                'options' => $options,
                'products_count' => $changes->count(),
            ]);

            $costVariations = [];
            $priceVariations = [];
            $now = now();
            $items = [];
            $histories = [];

            foreach ($changes as $c) {
                /** @var Product $product */
                $product = $c['product'];
                $oldCost = (float) $product->cost;
                $oldPrice = (float) $product->price;
                $oldMarkup = (float) $product->markup;

                if ($oldCost > 0) {
                    $costVariations[] = ($c['new_cost'] - $oldCost) / $oldCost * 100;
                }
                if ($oldPrice > 0) {
                    $priceVariations[] = ($c['new_price'] - $oldPrice) / $oldPrice * 100;
                }

                $items[] = [
                    'price_update_id' => $update->id,
                    'product_id' => $product->id,
                    'old_cost' => $oldCost,
                    'new_cost' => $c['new_cost'],
                    'old_price' => $oldPrice,
                    'new_price' => $c['new_price'],
                    'old_markup' => $oldMarkup,
                    'new_markup' => $c['new_markup'],
                ];
                $histories[] = [
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'supplier_id' => $supplierId,
                    'price_update_id' => $update->id,
                    'reason' => $source,
                    'old_cost' => $oldCost,
                    'new_cost' => $c['new_cost'],
                    'old_price' => $oldPrice,
                    'new_price' => $c['new_price'],
                    'created_at' => $now,
                ];

                $this->writeProduct($product, $c['new_cost'], $c['new_price'], $c['new_markup'], $now);
            }

            foreach (array_chunk($items, 500) as $chunk) {
                PriceUpdateItem::insert($chunk);
            }
            foreach (array_chunk($histories, 500) as $chunk) {
                ProductPriceHistory::insert($chunk);
            }

            $update->update([
                'avg_cost_variation' => $costVariations ? round(array_sum($costVariations) / count($costVariations), 2) : null,
                'avg_price_variation' => $priceVariations ? round(array_sum($priceVariations) / count($priceVariations), 2) : null,
            ]);

            return $update;
        });
    }

    /** Escribe costo/precio en el producto y escala los precios especiales de otras listas en la misma proporción */
    private function writeProduct(Product $product, float $newCost, float $newPrice, float $newMarkup, $now): void
    {
        $oldPrice = (float) $product->price;
        $data = ['markup' => $newMarkup];
        if (abs((float) $product->cost - $newCost) > 0.00001) {
            $data['cost'] = $newCost;
            $data['cost_updated_at'] = $now;
        }
        if (abs($oldPrice - $newPrice) > 0.001) {
            $data['price'] = $newPrice;
            $data['price_updated_at'] = $now;
            if ($oldPrice > 0) {
                $ratio = $newPrice / $oldPrice;
                foreach (ProductPrice::where('product_id', $product->id)->get() as $pp) {
                    $pp->update(['price' => $this->pricing->roundPrice((float) $pp->price * $ratio)]);
                }
            }
        }
        // Escritura directa (sin eventos de auditoría por fila: el lote ya queda auditado en price_updates)
        Product::withoutGlobalScopes()->whereKey($product->id)->update($data);
        $product->forceFill($data);
    }

    /**
     * Aumento / baja masiva por filtros.
     *
     * @param  array{target: string, percent: float, price_action?: string, custom_markup?: float}  $rule
     * @return Collection<int, array>
     */
    public function previewMass(Builder $query, array $rule): Collection
    {
        $percent = (float) $rule['percent'];
        $action = $rule['price_action'] ?? 'keep_markup';

        return $query->get()->map(function (Product $p) use ($rule, $percent, $action) {
            if ($rule['target'] === 'price') {
                $newCost = (float) $p->cost;
                $newPrice = $this->pricing->roundPrice((float) $p->price * (1 + $percent / 100));
                $newMarkup = $this->pricing->markupFromPrice($newCost, $newPrice, (float) $p->vat_rate);
            } elseif ($rule['target'] === 'markup') {
                $newCost = (float) $p->cost;
                $newMarkup = $percent; // en este modo "percent" es el margen a fijar
                $newPrice = $this->pricing->priceFromCost($newCost, $newMarkup, (float) $p->vat_rate);
            } else { // cost
                $newCost = round((float) $p->cost * (1 + $percent / 100), 4);
                $r = $this->pricing->resolvePrice($p, $newCost, $action, $rule['custom_markup'] ?? null);
                $newPrice = $r['price'];
                $newMarkup = $r['markup'];
            }

            return [
                'product' => $p,
                'new_cost' => $newCost,
                'new_price' => $newPrice,
                'new_markup' => $newMarkup,
            ];
        });
    }

    /**
     * Revierte un lote. Solo se permite si ningún producto del lote fue modificado por un lote posterior.
     */
    public function revert(PriceUpdate $update): int
    {
        if ($update->status !== 'applied') {
            throw new BusinessException('La actualización ya fue revertida.');
        }

        $productIds = $update->items()->pluck('product_id');
        $conflicts = PriceUpdateItem::whereIn('product_id', $productIds)
            ->whereHas('priceUpdate', fn ($q) => $q->where('status', 'applied')->where('id', '>', $update->id))
            ->distinct()->count('product_id');

        if ($conflicts > 0) {
            throw new BusinessException("No se puede revertir: {$conflicts} producto(s) tuvieron cambios de precio posteriores. Revierta primero las actualizaciones más recientes.");
        }

        return DB::transaction(function () use ($update) {
            $now = now();
            $count = 0;
            $products = Product::whereIn('id', $update->items()->pluck('product_id'))->get()->keyBy('id');
            foreach ($update->items as $item) {
                $product = $products->get($item->product_id);
                if (! $product) {
                    continue;
                }
                ProductPriceHistory::create([
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'price_update_id' => $update->id,
                    'reason' => 'revert',
                    'old_cost' => $product->cost,
                    'new_cost' => $item->old_cost,
                    'old_price' => $product->price,
                    'new_price' => $item->old_price,
                    'created_at' => $now,
                ]);
                $this->writeProduct($product, (float) $item->old_cost, (float) $item->old_price, (float) $item->old_markup, $now);
                $count++;
            }
            $update->update(['status' => 'reverted', 'reverted_at' => $now, 'reverted_by' => auth()->id()]);

            return $count;
        });
    }
}
