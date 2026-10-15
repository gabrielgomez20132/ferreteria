<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    /** El token del link público no viaja en los listados: se expone con publicUrl() */
    protected $hidden = ['public_token'];

    /** Estado del presupuesto: pending, expired, converted, rejected (null en ventas) */
    protected $appends = ['quote_state'];

    public const QUOTE_STATES = ['pending' => 'Pendiente', 'expired' => 'Vencido', 'converted' => 'Convertido', 'rejected' => 'Rechazado'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'on_account_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'valid_until' => 'date:Y-m-d',
            'converted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function getQuoteStateAttribute(): ?string
    {
        if ($this->type !== 'quote') {
            return null;
        }

        return match ($this->status) {
            'converted' => 'converted',
            'rejected' => 'rejected',
            default => $this->valid_until && $this->valid_until->toDateString() < now()->toDateString() ? 'expired' : 'pending',
        };
    }

    /** Presupuesto del que salió esta venta */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'quote_id');
    }

    /** Venta generada a partir de este presupuesto */
    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    /** Versión para mostrar al cliente (portal / link público): sin costos ni datos internos */
    public function forCustomer(): static
    {
        $this->loadMissing('items');
        $this->items->each->makeHidden(['unit_cost', 'returned_quantity', 'product_id']);

        return $this->makeHidden(['cost_total', 'cash_session_id', 'price_list_id', 'notes_internal', 'tenant_id', 'cancelled_by', 'cancel_reason', 'reject_reason', 'sent_at', 'viewed_at', 'quote_id', 'converted_sale_id']);
    }

    public function publicUrl(): ?string
    {
        if ($this->type !== 'quote') {
            return null;
        }
        if (! $this->public_token) {
            $this->forceFill(['public_token' => \Illuminate\Support\Str::random(40)])->saveQuietly();
        }

        return route('quote.public', $this->public_token);
    }

    /** Scope por estado de presupuesto (los vencidos se calculan por fecha) */
    public function scopeQuoteState($q, ?string $state)
    {
        $today = now()->toDateString();

        return match ($state) {
            'pending' => $q->where('status', 'quote')->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', $today)),
            'expired' => $q->where('status', 'quote')->where('valid_until', '<', $today),
            'open' => $q->where('status', 'quote'),
            'converted' => $q->where('status', 'converted'),
            'rejected' => $q->where('status', 'rejected'),
            default => $q,
        };
    }
}
