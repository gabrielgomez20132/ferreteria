<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToTenant, SoftDeletes, Auditable;

    protected $guarded = ['id'];

    /** El token del portal no viaja en listados ni búsquedas: solo se expone con portalUrl() */
    protected $hidden = ['portal_token'];

    /** Valores por defecto (coinciden con la base de datos) */
    protected $attributes = ['is_active' => true, 'allow_credit' => false, 'credit_limit' => 0, 'balance' => 0, 'document_type' => 'DNI', 'tax_condition' => 'Consumidor Final'];

    protected function casts(): array
    {
        return [
            'allow_credit' => 'boolean',
            'credit_limit' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'portal_last_seen_at' => 'datetime',
        ];
    }

    /** Link público del portal del cliente (null si no está habilitado) */
    public function portalUrl(): ?string
    {
        return $this->portal_token ? route('portal.show', $this->portal_token) : null;
    }

    /** Genera (o regenera, invalidando el anterior) el link del portal */
    public function regeneratePortalToken(): string
    {
        $this->forceFill(['portal_token' => \Illuminate\Support\Str::random(40)])->save();

        return $this->portal_token;
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CustomerMovement::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
