<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToTenant, SoftDeletes, Auditable;

    protected $guarded = ['id'];

    /** Valores por defecto (coinciden con la base de datos) */
    protected $attributes = ['is_active' => true, 'prices_include_vat' => false, 'discount_1' => 0, 'discount_2' => 0, 'discount_3' => 0, 'balance' => 0, 'payment_term_days' => 0];

    protected function casts(): array
    {
        return [
            'discount_1' => 'decimal:2',
            'discount_2' => 'decimal:2',
            'discount_3' => 'decimal:2',
            'prices_include_vat' => 'boolean',
            'default_markup' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'import_mapping' => 'array',
        ];
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(SupplierMovement::class);
    }

    public function priceImports(): HasMany
    {
        return $this->hasMany(SupplierPriceImport::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** Bonificaciones del proveedor como array de porcentajes */
    public function discounts(): array
    {
        return [(float) $this->discount_1, (float) $this->discount_2, (float) $this->discount_3];
    }
}
