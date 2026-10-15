<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PriceList;
use App\Services\AccountService;
use App\Services\CashService;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index(Request $request)
    {
        $customers = Customer::with('priceList:id,name')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('document_number', 'like', "{$s}%")->orWhere('phone', 'like', "%{$s}%")))
            ->when($request->debt, fn ($q) => $q->where('balance', '>', 0))
            ->when($request->overdue, fn ($q) => $q->whereHas('movements', fn ($m) => $m->where('pending_amount', '>', 0)->where('due_date', '<', now()->toDateString())))
            ->orderBy('name')->paginate(30)->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'debt', 'overdue']),
            'priceLists' => PriceList::where('is_active', true)->get(['id', 'name']),
            'totalDebt' => (float) Customer::where('balance', '>', 0)->sum('balance'),
        ]);
    }

    public function search(Request $request)
    {
        $s = trim((string) $request->q);

        return Customer::where('is_active', true)
            ->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('document_number', 'like', "{$s}%")))
            ->orderBy('name')->limit(15)
            ->get(['id', 'name', 'document_type', 'document_number', 'tax_condition', 'price_list_id', 'allow_credit', 'credit_limit', 'balance']);
    }

    public function show(Customer $customer)
    {
        return Inertia::render('Customers/Show', [
            'customer' => $customer->load('priceList:id,name'),
            'portal' => ['url' => $customer->portalUrl(), 'last_seen_at' => $customer->portal_last_seen_at?->toIso8601String()],
            'movements' => $customer->movements()->with('user:id,name')->latest()->latest('id')->paginate(30),
            'sales' => $customer->sales()->where('type', 'sale')->latest()->limit(10)->get(['id', 'number', 'total', 'status', 'created_at', 'branch_id']),
            'overdue' => (float) $customer->movements()->where('pending_amount', '>', 0)->where('due_date', '<', now()->toDateString())->sum('pending_amount'),
            'methods' => collect(CashService::METHODS)->except('account'),
            'priceLists' => PriceList::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    private function rules(?Customer $c = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'document_type' => ['required', Rule::in(['DNI', 'CUIT', 'CUIL', 'PAS'])],
            'document_number' => ['nullable', 'string', 'max:20', Rule::unique('customers')->where('tenant_id', $this->ctx->id())->whereNull('deleted_at')->ignore($c?->id)],
            'tax_condition' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'price_list_id' => ['nullable', Rule::exists('price_lists', 'id')->where('tenant_id', $this->ctx->id())],
            'allow_credit' => ['boolean'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $c = Customer::create(array_merge($data, ['credit_limit' => $data['credit_limit'] ?? 0]));
        // Alta rápida desde el punto de venta (axios)
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json($c->only(['id', 'name', 'document_type', 'document_number', 'tax_condition', 'price_list_id', 'allow_credit', 'balance', 'credit_limit']));
        }

        return back()->with('success', 'Cliente creado.');
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate($this->rules($customer));
        $customer->update(array_merge($data, ['credit_limit' => $data['credit_limit'] ?? 0]));

        return back()->with('success', 'Cliente actualizado.');
    }

    public function destroy(Customer $customer)
    {
        if (abs((float) $customer->balance) > 0.009) {
            throw new BusinessException('No se puede eliminar un cliente con saldo en cuenta corriente.');
        }
        $customer->delete();

        return redirect()->route('customers.index')->with('success', 'Cliente eliminado.');
    }

    public function payment(Request $request, Customer $customer, AccountService $accounts, CashService $cash)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_keys(CashService::METHODS)), 'not_in:account'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        $session = $cash->openSessionFor($request->user(), $this->ctx->branchId());
        if (! $session && $this->ctx->setting('require_cash_session', true)) {
            throw new BusinessException('Debe abrir la caja para registrar cobranzas.');
        }
        $accounts->receiveCustomerPayment($customer, (float) $data['amount'], $data['method'], $session, $data['notes'] ?? null);

        return back()->with('success', 'Pago registrado.');
    }

    public function adjust(Request $request, Customer $customer, AccountService $accounts)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'description' => ['required', 'string', 'max:200'],
        ]);
        DB::transaction(function () use ($data, $customer, $accounts) {
            $amount = (float) $data['amount'];
            $amount > 0
                ? $accounts->chargeCustomer($customer, $amount, 'adjustment', 'Ajuste: '.$data['description'])
                : $accounts->creditCustomer($customer, -$amount, 'adjustment', 'Ajuste: '.$data['description']);
        });

        return back()->with('success', 'Ajuste registrado.');
    }

    /** Habilita el portal del cliente o regenera el link (el anterior deja de funcionar) */
    public function enablePortal(Customer $customer)
    {
        $had = (bool) $customer->portal_token;
        $customer->regeneratePortalToken();

        return back()->with('success', $had ? 'Se generó un link nuevo. El anterior ya no funciona.' : 'Portal habilitado: compartí el link con el cliente.');
    }

    public function disablePortal(Customer $customer)
    {
        $customer->forceFill(['portal_token' => null])->save();

        return back()->with('success', 'Portal deshabilitado: el link ya no funciona.');
    }
}
