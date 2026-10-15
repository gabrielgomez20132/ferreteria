<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashService;
use App\Services\SaleService;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Presupuestos: listado por estado, ficha con acciones (convertir, editar, duplicar, rechazar,
 * reabrir, extender validez), PDF A4 y envío por WhatsApp con link público.
 */
class QuoteController extends Controller
{
    public const REJECT_REASONS = ['Precio', 'Lo compró en otro lado', 'Sin stock / demora de entrega', 'El cliente no respondió', 'Cambió de proyecto', 'Otro'];

    public function __construct(private TenantContext $ctx) {}

    private function ensureQuote(Sale $quote): void
    {
        abort_unless($quote->type === 'quote', 404);
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'from', 'to', 'state', 'user_id', 'branch_id']);
        $state = $filters['state'] ?? 'open';
        $today = now()->toDateString();
        $branch = $filters['branch_id'] ?? 'all';

        $base = fn () => Sale::where('type', 'quote')
            ->when($branch !== 'all', fn ($q) => $q->where('branch_id', $branch))
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', ltrim($s, '#0') ?: $s)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))));

        $counts = collect(['open', 'pending', 'expired', 'converted', 'rejected'])
            ->mapWithKeys(fn ($s) => [$s => $base()->quoteState($s)->count()])
            ->put('all', $base()->count());

        $quotes = $base()->quoteState($state === 'all' ? null : $state)
            ->with('customer:id,name,phone', 'user:id,name', 'branch:id,name', 'convertedSale:id,number,total')
            // abiertos: primero los vigentes que vencen antes, después los vencidos más recientes
            ->when(in_array($state, ['pending', 'open'], true),
                fn ($q2) => $q2->orderByRaw('valid_until < ?', [$today])->orderByRaw('CASE WHEN valid_until >= ? THEN valid_until END', [$today])->orderByDesc('valid_until'),
                fn ($q2) => $q2->orderByDesc('created_at'))
            ->orderByDesc('id')
            ->paginate(30)->withQueryString();

        $since = now()->subDays(90);
        $last90 = Sale::where('type', 'quote')->where('created_at', '>=', $since)
            ->when($branch !== 'all', fn ($q) => $q->where('branch_id', $branch))
            ->selectRaw("COUNT(*) c, SUM(status = 'converted') conv, COALESCE(SUM(total),0) t, COALESCE(SUM(CASE WHEN status = 'converted' THEN total ELSE 0 END),0) tc")->first();

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes,
            'counts' => $counts,
            'filters' => array_merge($filters, ['state' => $state, 'branch_id' => $branch]),
            'kpis' => [
                'pending_amount' => (float) $base()->quoteState('pending')->sum('total'),
                'expiring' => $base()->quoteState('pending')->where('valid_until', '<=', now()->addDays(3)->toDateString())->count(),
                'conversion' => (int) $last90->c ? round($last90->conv / $last90->c * 100, 1) : null,
                'conversion_amount' => (float) $last90->t ? round($last90->tc / $last90->t * 100, 1) : null,
            ],
            'users' => User::where('tenant_id', $this->ctx->id())->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'states' => Sale::QUOTE_STATES,
            'today' => $today,
        ]);
    }

    public function show(Sale $quote)
    {
        $this->ensureQuote($quote);

        return Inertia::render('Quotes/Show', [
            'quote' => $quote->load('customer', 'user:id,name', 'branch', 'items', 'priceList:id,name', 'convertedSale:id,number,total,created_at'),
            'publicUrl' => $quote->publicUrl(),
            'company' => $this->ctx->tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone', 'email', 'tax_condition']),
            'settings' => $this->ctx->tenant->allSettings(),
            'rejectReasons' => self::REJECT_REASONS,
            'states' => Sale::QUOTE_STATES,
        ]);
    }

    /** Guardar cambios hechos desde el punto de venta (modo edición) */
    public function update(Request $request, Sale $quote, SaleService $sales)
    {
        $this->ensureQuote($quote);
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $this->ctx->id())],
            'price_list_id' => ['nullable', Rule::exists('price_lists', 'id')->where('tenant_id', $this->ctx->id())],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $sales->updateQuote($quote, $data, $request->user());

        return redirect()->route('quotes.show', $quote)->with('success', "Presupuesto #{$quote->number} actualizado.");
    }

    /** Validez, condiciones y observaciones */
    public function details(Request $request, Sale $quote)
    {
        $this->ensureQuote($quote);
        $data = $request->validate([
            'valid_until' => ['required', 'date'],
            'terms' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if ($quote->status !== 'quote' && $data['valid_until'] !== $quote->valid_until?->toDateString()) {
            throw new BusinessException('El presupuesto ya está cerrado: reabrilo para cambiar la validez.');
        }
        $quote->update($data);

        return back()->with('success', 'Presupuesto actualizado.');
    }

    public function reject(Request $request, Sale $quote)
    {
        $this->ensureQuote($quote);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        if ($quote->status !== 'quote') {
            throw new BusinessException('Solo se pueden rechazar presupuestos abiertos.');
        }
        $quote->update(['status' => 'rejected', 'rejected_at' => now(), 'reject_reason' => $data['reason']]);

        return back()->with('success', "Presupuesto #{$quote->number} marcado como rechazado.");
    }

    public function reopen(Sale $quote)
    {
        $this->ensureQuote($quote);
        if ($quote->status === 'converted') {
            throw new BusinessException('El presupuesto ya se convirtió en venta.');
        }
        $validUntil = $quote->valid_until && $quote->valid_until->toDateString() >= now()->toDateString()
            ? $quote->valid_until->toDateString()
            : now()->addDays((int) $this->ctx->setting('quote_validity_days', 7))->toDateString();
        $quote->update(['status' => 'quote', 'rejected_at' => null, 'reject_reason' => null, 'valid_until' => $validUntil]);

        return back()->with('success', 'Presupuesto reabierto, válido hasta el '.date('d/m/Y', strtotime($validUntil)).'.');
    }

    /** Se marca al enviarlo por WhatsApp */
    public function sent(Request $request, Sale $quote)
    {
        $this->ensureQuote($quote);
        $quote->update(['sent_at' => now()]);

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true]) : back();
    }

    /** Datos para el PDF A4 (lo usa la ficha y el listado) */
    public function pdfData(Sale $quote)
    {
        $this->ensureQuote($quote);

        return response()->json([
            'quote' => $quote->load('customer', 'user:id,name', 'branch', 'items'),
            'company' => $this->ctx->tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone', 'email', 'tax_condition']),
            'settings' => $this->ctx->tenant->allSettings(),
            'publicUrl' => $quote->publicUrl(),
        ]);
    }

    /** Link público: el cliente ve el presupuesto, lo descarga y responde por WhatsApp */
    public function public(Request $request, string $token)
    {
        abort_unless(strlen($token) >= 30, 404);
        $quote = Sale::withoutGlobalScopes()->where('type', 'quote')->where('public_token', $token)->first();
        abort_unless($quote, 404);
        $tenant = $quote->tenant;
        if (! $tenant || $tenant->trashed() || in_array($tenant->status, ['suspended', 'cancelled'], true)) {
            abort(404);
        }
        $this->ctx->set($tenant);
        if ($request->user()?->tenant_id !== $quote->tenant_id) {
            $quote->forceFill(['viewed_at' => now()])->saveQuietly();
        }

        $quote->load('customer:id,name,document_type,document_number,tax_condition,address,city', 'user:id,name', 'branch:id,name,address,phone', 'items');

        return Inertia::render('Quotes/Public', [
            'quote' => $quote->forCustomer(),
            'company' => $tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone', 'email', 'tax_condition']),
            'settings' => $tenant->allSettings(),
            'publicUrl' => route('quote.public', $token),
        ])->withViewData(['noindex' => true]);
    }

    public static function methods(): array
    {
        return CashService::METHODS;
    }
}
