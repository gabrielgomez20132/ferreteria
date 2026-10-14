<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $guarded = ['id'];

    /** Valores por defecto (coinciden con la base de datos) */
    protected $attributes = ['type' => 'simple', 'has_variants' => false, 'is_active' => true, 'track_stock' => true, 'allow_fraction' => false, 'cost' => 0, 'markup' => 0, 'price' => 0, 'vat_rate' => 21, 'purchase_unit_factor' => 1, 'min_stock' => 0];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'average_cost' => 'decimal:4',
            'last_purchase_cost' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'markup' => 'decimal:2',
            'price' => 'decimal:2',
            'cost_updated_at' => 'datetime',
            'price_updated_at' => 'datetime',
            'allow_fraction' => 'boolean',
            'purchase_unit_factor' => 'decimal:4',
            'min_stock' => 'decimal:3',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'has_variants' => 'boolean',
            'variant_attributes' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Insumos del kit o del producto fabricado */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }

    /** Kits / productos fabricados que usan este producto como insumo */
    public function usedIn(): HasMany
    {
        return $this->hasMany(ProductComponent::class, 'component_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'variant_of_id');
    }

    public function variantOf(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'variant_of_id');
    }

    /** Productos que se pueden vender / comprar (excluye los "modelos" agrupadores de variantes) */
    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('products.is_active', true)->where('products.has_variants', false);
    }

    public function isKit(): bool
    {
        return $this->type === 'kit';
    }

    public function codes(): HasMany
    {
        return $this->hasMany(ProductCode::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function stockAt(): HasOne
    {
        return $this->hasOne(ProductStock::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Búsqueda por código, código de barras, códigos alternativos, código de proveedor o descripción */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('products.code', $term)
                ->orWhere('products.barcode', $term)
                ->orWhereHas('codes', fn ($c) => $c->where('code', $term))
                ->orWhereHas('supplierProducts', fn ($c) => $c->where('supplier_code', $term));
            // Búsqueda por palabras: "tornillo 6x40" encuentra "Tornillo autoperforante 6 x 40"
            $q->orWhere(function (Builder $w) use ($term) {
                foreach (preg_split('/\s+/', $term) as $word) {
                    $w->where(fn ($x) => $x->where('products.name', 'like', "%{$word}%")->orWhere('products.code', 'like', "{$word}%"));
                }
            });
        });
    }

    /** Margen bruto real según precio vigente */
    public function currentMargin(): float
    {
        $net = (float) $this->price / (1 + (float) $this->vat_rate / 100);
        if ($net <= 0) {
            return 0;
        }

        return round(($net - (float) $this->cost) / $net * 100, 2);
    }
}
