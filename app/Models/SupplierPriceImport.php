<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPriceImport extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'options' => 'array',
            'avg_variation' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceUpdate(): BelongsTo
    {
        return $this->belongsTo(PriceUpdate::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(SupplierPriceImportRow::class);
    }
}
