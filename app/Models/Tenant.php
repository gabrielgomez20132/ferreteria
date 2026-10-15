<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /** Configuración por defecto de cada empresa */
    public const DEFAULT_SETTINGS = [
        'default_markup' => 40,
        'default_vat' => 21,
        'price_rounding' => 10,          // 0 = sin redondeo, 1, 5, 10, 50, 100
        'rounding_mode' => 'up',         // up | nearest
        'price_alert_threshold' => 15,   // % de variación que dispara alerta en importaciones
        'allow_negative_stock' => false,
        'require_cash_session' => true,
        'ticket_header' => '',
        'ticket_footer' => '¡Gracias por su compra!',
        'cost_update_policy' => 'always', // always | main_supplier | if_cheaper
        'default_price_action' => 'keep_markup',
        'quote_validity_days' => 7,
        'quote_terms' => "Precios con IVA incluido. Una vez vencido el presupuesto, los precios pueden variar sin previo aviso.\nForma de pago: a convenir.",
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function isOperational(): bool
    {
        if ($this->status === 'active') {
            return ! $this->subscription_ends_at || $this->subscription_ends_at->isFuture();
        }

        return $this->status === 'trial' && (! $this->trial_ends_at || $this->trial_ends_at->isFuture());
    }
}
