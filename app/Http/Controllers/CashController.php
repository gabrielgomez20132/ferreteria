<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\CashService;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CashController extends Controller
{
    public function __construct(private CashService $cash, private TenantContext $ctx) {}

    public function index(Request $request)
    {
        $branchId = $this->ctx->branchId();
        $registers = CashRegister::where('branch_id', $branchId)->where('is_active', true)->get(['id', 'name']);
        $sessions = CashSession::where('branch_id', $branchId)->where('status', 'open')->with('cashRegister:id,name', 'user:id,name')->get();

        $current = $this->cash->openSessionFor($request->user(), $branchId);

        return Inertia::render('Cash/Index', [
            'registers' => $registers->map(fn ($r) => $r->toArray() + ['open' => $sessions->firstWhere('cash_register_id', $r->id)?->only(['id', 'user_id'])]),
            'openSessions' => $sessions,
            'current' => $current ? $this->summary($current) : null,
            'methods' => CashService::METHODS,
            'types' => CashService::TYPES,
        ]);
    }

    private function summary(CashSession $session): array
    {
        $session->load('cashRegister:id,name', 'user:id,name', 'closer:id,name', 'branch:id,name');

        return [
            'session' => $session,
            'totals' => $this->cash->totals($session),
            'expected_cash' => $session->status === 'open' ? $this->cash->expectedCash($session) : (float) $session->expected_cash,
            'by_type' => $session->movements()->selectRaw('type, direction, SUM(amount) total, COUNT(*) c')->groupBy('type', 'direction')->get(),
            'movements' => $session->movements()->with('user:id,name')->latest()->limit(200)->get(),
            'sales_count' => $session->sales()->where('status', 'completed')->count(),
        ];
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'cash_register_id' => ['required', Rule::exists('cash_registers', 'id')->where('tenant_id', $this->ctx->id())->where('branch_id', $this->ctx->branchId())],
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->cash->open(CashRegister::findOrFail($data['cash_register_id']), $request->user(), (float) $data['opening_amount'], $data['notes'] ?? null);

        return back()->with('success', 'Caja abierta.');
    }

    private function authorizeSession(Request $request, CashSession $session): void
    {
        if ($session->user_id !== $request->user()->id && ! $request->user()->hasPermission('cash.manage')) {
            abort(403, 'Solo el responsable de la caja o un supervisor puede operar esta caja.');
        }
    }

    public function close(Request $request, CashSession $session)
    {
        $this->authorizeSession($request, $session);
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500']]);
        $this->cash->close($session, (float) $data['counted_cash'], $data['notes'] ?? null);

        return redirect()->route('cash.show', $session)->with('success', 'Caja cerrada.');
    }

    public function movement(Request $request, CashSession $session)
    {
        $this->authorizeSession($request, $session);
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense', 'withdrawal'])],
            'payment_method' => ['required', Rule::in(array_keys(CashService::METHODS)), 'not_in:account'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        if ($data['type'] !== 'income' && $data['payment_method'] === 'cash' && (float) $data['amount'] > $this->cash->expectedCash($session)) {
            throw new BusinessException('El egreso supera el efectivo disponible en caja.');
        }
        $this->cash->record($session, $data['type'], $data['type'] === 'income' ? 'in' : 'out', $data['payment_method'], (float) $data['amount'], $data['description']);

        return back()->with('success', 'Movimiento registrado.');
    }

    public function history(Request $request)
    {
        return Inertia::render('Cash/History', [
            'sessions' => CashSession::with('cashRegister:id,name', 'user:id,name', 'branch:id,name')
                ->when(! $request->user()->hasPermission('cash.manage'), fn ($q) => $q->where('user_id', $request->user()->id))
                ->when($request->branch_id, fn ($q, $b) => $q->where('branch_id', $b))
                ->latest('opened_at')->paginate(30)->withQueryString(),
        ]);
    }

    public function show(Request $request, CashSession $session)
    {
        $this->authorizeSession($request, $session);

        return Inertia::render('Cash/Show', $this->summary($session) + ['methods' => CashService::METHODS, 'types' => CashService::TYPES]);
    }
}
