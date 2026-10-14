<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPriceImportRow extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:4',
            'new_cost' => 'decimal:4',
            'current_cost' => 'decimal:4',
            'variation_percent' => 'decimal:2',
            'current_price' => 'decimal:2',
            'suggested_price' => 'decimal:2',
            'is_alert' => 'boolean',
            'selected' => 'boolean',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SupplierPriceImport::class, 'supplier_price_import_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }
}
