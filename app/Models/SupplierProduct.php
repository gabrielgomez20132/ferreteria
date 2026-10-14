<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProduct extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:4',
            'discount_1' => 'decimal:2',
            'discount_2' => 'decimal:2',
            'discount_3' => 'decimal:2',
            'pack_quantity' => 'decimal:4',
            'cost' => 'decimal:4',
            'is_preferred' => 'boolean',
            'last_price_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
