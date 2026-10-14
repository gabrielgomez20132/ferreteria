<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /** Valores por defecto (coinciden con la base de datos) */
    protected $attributes = ['is_active' => true, 'is_default' => false, 'adjustment_percent' => 0];

    protected function casts(): array
    {
        return [
            'adjustment_percent' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
