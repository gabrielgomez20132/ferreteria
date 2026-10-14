<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\CashService;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $q = Expense::with('category:id,name', 'user:id,name', 'branch:id,name')
            ->whereBetween('date', [$from, $to])
            ->when($request->category_id, fn ($q, $c) => $q->where('expense_category_id', $c));

        return Inertia::render('Expenses/Index', [
            'expenses' => (clone $q)->latest('date')->latest('id')->paginate(30)->withQueryString(),
            'byCategory' => (clone $q)->selectRaw('expense_category_id, SUM(amount) total')->groupBy('expense_category_id')->with('category:id,name')->get(),
            'total' => (float) (clone $q)->sum('amount'),
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'methods' => collect(CashService::METHODS)->except('account'),
            'filters' => ['from' => $from, 'to' => $to, 'category_id' => $request->category_id],
        ]);
    }

    public function store(Request $request, CashService $cash)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('tenant_id', $this->ctx->id())],
            'new_category' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(array_keys(CashService::METHODS))],
            'from_cash' => ['boolean'],
        ]);
        DB::transaction(function () use ($data, $request, $cash) {
            if (! empty($data['new_category'])) {
                $data['expense_category_id'] = ExpenseCategory::firstOrCreate(['name' => $data['new_category']])->id;
            }
            $session = null;
            if ($request->boolean('from_cash')) {
                $session = $cash->openSessionFor($request->user(), $this->ctx->branchId());
                if (! $session) {
                    throw new BusinessException('No hay caja abierta para registrar el egreso.');
                }
            }
            $expense = Expense::create([
                'branch_id' => $this->ctx->branchId(),
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'user_id' => $request->user()->id,
                'cash_session_id' => $session?->id,
                'date' => $data['date'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
            ]);
            if ($session) {
                $cash->record($session, 'expense', 'out', $data['payment_method'], (float) $data['amount'], 'Gasto: '.$data['description'], $expense);
            }
        });

        return back()->with('success', 'Gasto registrado.');
    }

    public function destroy(Expense $expense)
    {
        if ($expense->cash_session_id) {
            throw new BusinessException('El gasto se pagó desde caja; registre un ingreso de caja para compensarlo.');
        }
        $expense->delete();

        return back()->with('success', 'Gasto eliminado.');
    }
}
