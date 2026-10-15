<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashService;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SaleController extends Controller
{
    public function index(Request $request, TenantContext $ctx)
    {
        if ($request->input('type') === 'quote') {
            return redirect()->route('quotes.index');
        }
        $filters = $request->only(['search', 'from', 'to', 'status', 'type', 'user_id', 'branch_id', 'method']);
        $q = Sale::with('customer:id,name', 'user:id,name', 'branch:id,name', 'payments')
            ->when($filters['branch_id'] ?? $ctx->branchId(), fn ($q, $b) => $b === 'all' ? $q : $q->where('branch_id', $b))
            ->where('type', $filters['type'] ?? 'sale')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->whereHas('payments', fn ($p) => $p->where('method', $m)))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', $s)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))));

        $totals = (clone $q)->where('status', 'completed')->selectRaw('COUNT(*) c, COALESCE(SUM(total),0) t, COALESCE(SUM(returned_amount),0) r')->first();

        return Inertia::render('Sales/Index', [
            'sales' => $q->latest()->paginate(30)->withQueryString(),
            'totals' => $totals,
            'filters' => $filters,
            'users' => User::where('tenant_id', $ctx->id())->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'methods' => CashService::METHODS,
        ]);
    }

    public function show(Sale $sale)
    {
        if ($sale->type === 'quote') {
            return redirect()->route('quotes.show', $sale);
        }
        return Inertia::render('Sales/Show', [
            'sale' => $sale->load('customer', 'user:id,name', 'branch:id,name', 'items', 'payments', 'returns.items', 'returns.user:id,name', 'canceller:id,name', 'priceList:id,name', 'quote:id,number'),
            'methods' => CashService::METHODS,
        ]);
    }

    /** Ticket imprimible (página) o sus datos en JSON para el pop-up del punto de venta */
    public function ticket(Request $request, Sale $sale, TenantContext $ctx)
    {
        $data = [
            'sale' => $sale->load('customer', 'user:id,name', 'branch', 'items', 'payments'),
            'company' => $ctx->tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone', 'tax_condition']),
            'settings' => $ctx->tenant->allSettings(),
            'methods' => CashService::METHODS,
        ];

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($data);
        }

        return Inertia::render('Sales/Ticket', $data);
    }

    public function cancel(Request $request, Sale $sale, SaleService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $service->cancel($sale, $request->user(), $data['reason']);

        return back()->with('success', "Venta #{$sale->number} anulada. Se repuso el stock y se registró la devolución del dinero.");
    }

    public function return(Request $request, Sale $sale, SaleService $service)
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
            'refund_method' => ['required', Rule::in(array_keys(CashService::METHODS))],
            'reason' => ['nullable', 'string', 'max:255'],
            'restock' => ['boolean'],
        ]);
        $r = $service->returnItems($sale, $data['items'], $data['refund_method'], $request->user(), $data['reason'] ?? null, $request->boolean('restock', true));

        return back()->with('success', 'Devolución registrada por $ '.number_format((float) $r->total, 2, ',', '.'));
    }
}
