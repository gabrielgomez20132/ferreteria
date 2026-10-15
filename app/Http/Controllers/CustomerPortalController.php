<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMovement;
use App\Models\Sale;
use App\Services\CashService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Portal público del cliente: con el link secreto ve su cuenta corriente, comprobantes,
 * facturas pendientes y pagos, y descarga los PDF. No requiere usuario ni contraseña.
 */
class CustomerPortalController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    /** Busca el cliente por token (sin el scope de empresa: todavía no hay contexto) y fija el contexto */
    private function resolve(string $token): Customer
    {
        abort_unless(strlen($token) >= 30, 404);
        $customer = Customer::withoutGlobalScopes()->whereNull('deleted_at')->where('portal_token', $token)->first();
        abort_unless($customer, 404);

        $tenant = $customer->tenant;
        if (! $tenant || $tenant->trashed() || in_array($tenant->status, ['suspended', 'cancelled'], true)) {
            abort(404);
        }
        $this->ctx->set($tenant);

        return $customer;
    }

    public function show(Request $request, string $token)
    {
        $customer = $this->resolve($token);
        // Si lo abre alguien de la ferretería (sesión iniciada en la misma empresa) no cuenta como visita del cliente
        if ($request->user()?->tenant_id !== $customer->tenant_id) {
            $customer->forceFill(['portal_last_seen_at' => now()])->saveQuietly();
        }
        $today = now()->toDateString();

        // Desde cuándo mostrar los movimientos (por defecto, 12 meses)
        $months = $request->filled('months') && in_array((int) $request->input('months'), [3, 6, 12, 24, 0], true) ? (int) $request->input('months') : 12;
        $from = $months ? now()->subMonths($months)->startOfDay() : null;

        $movQuery = CustomerMovement::where('customer_id', $customer->id);
        $previousBalance = 0.0;
        if ($from) {
            $prev = (clone $movQuery)->where('created_at', '<', $from)->orderByDesc('created_at')->orderByDesc('id')->first();
            $previousBalance = (float) ($prev?->balance_after ?? 0);
        }
        $movements = (clone $movQuery)->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'type', 'description', 'debit', 'credit', 'balance_after', 'pending_amount', 'due_date', 'payment_method', 'reference_type', 'reference_id', 'created_at'])
            ->map(fn ($m) => $this->movementRow($m));

        $pending = CustomerMovement::where('customer_id', $customer->id)->where('pending_amount', '>', 0)
            ->orderBy('due_date')->orderBy('id')->get()
            ->map(fn ($m) => $this->movementRow($m) + ['overdue' => $m->due_date && $m->due_date->toDateString() < $today]);

        $sales = Sale::where('customer_id', $customer->id)->where('type', 'sale')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->latest()->latest('id')->limit(300)
            ->get(['id', 'number', 'voucher_type', 'status', 'total', 'returned_amount', 'on_account_amount', 'created_at', 'branch_id'])
            ->load('branch:id,name');

        $payments = $movements->where('type', 'payment')->sortByDesc('date')->values();

        $tenant = $this->ctx->tenant;

        return Inertia::render('Portal/Show', [
            'token' => $token,
            'company' => $tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone', 'email']),
            'customer' => $customer->only(['name', 'document_type', 'document_number', 'tax_condition', 'address', 'city', 'allow_credit', 'credit_limit', 'balance']),
            'summary' => [
                'balance' => (float) $customer->balance,
                'overdue' => round((float) $pending->where('overdue', true)->sum('pending'), 2),
                'not_due' => round((float) $pending->where('overdue', false)->sum('pending'), 2),
                'next_due' => $pending->where('overdue', false)->first()['due_date'] ?? null,
                'available' => $customer->allow_credit && (float) $customer->credit_limit > 0 ? round((float) $customer->credit_limit - (float) $customer->balance, 2) : null,
            ],
            'movements' => $movements->values(),
            'previousBalance' => $previousBalance,
            'pending' => $pending->values(),
            'sales' => $sales,
            'payments' => $payments,
            'months' => $months,
            'methods' => CashService::METHODS,
            'generatedAt' => now()->toIso8601String(),
        ])->withViewData(['noindex' => true]);
    }

    /** Datos de un comprobante del cliente para verlo y descargarlo en PDF */
    public function sale(string $token, int $sale)
    {
        $customer = $this->resolve($token);
        $sale = Sale::where('customer_id', $customer->id)->where('type', 'sale')->findOrFail($sale);

        return response()->json([
            'sale' => $sale->load('customer', 'user:id,name', 'branch', 'items', 'payments')->forCustomer(),
            'company' => $this->ctx->tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone', 'tax_condition']),
            'settings' => $this->ctx->tenant->allSettings(),
            'methods' => CashService::METHODS,
        ]);
    }

    private function movementRow(CustomerMovement $m): array
    {
        $saleId = $m->reference_type === Sale::class ? $m->reference_id : null;

        return [
            'id' => $m->id,
            'date' => $m->created_at?->toIso8601String(),
            'type' => $m->type,
            'description' => $m->description,
            'debit' => (float) $m->debit,
            'credit' => (float) $m->credit,
            'balance' => (float) $m->balance_after,
            'pending' => (float) $m->pending_amount,
            'due_date' => $m->due_date?->toDateString(),
            'method' => $m->payment_method,
            'sale_id' => $saleId,
        ];
    }
}
