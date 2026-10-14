<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceUpdateItem extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_cost' => 'decimal:4',
            'new_cost' => 'decimal:4',
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'old_markup' => 'decimal:2',
            'new_markup' => 'decimal:2',
        ];
    }

    public function priceUpdate(): BelongsTo
    {
        return $this->belongsTo(PriceUpdate::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
