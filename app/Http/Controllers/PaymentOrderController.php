<?php

namespace App\Http\Controllers;

use App\Models\PaymentOrder;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\CashService;
use App\Services\PaymentOrderService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PaymentOrderController extends Controller
{
    public function __construct(private PaymentOrderService $service, private TenantContext $ctx) {}

    public function index(Request $request)
    {
        return Inertia::render('PaymentOrders/Index', [
            'orders' => PaymentOrder::with('supplier:id,name', 'user:id,name', 'lines')
                ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
                ->latest('date')->latest('id')->paginate(30)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('supplier_id'),
            'methods' => CashService::METHODS,
        ]);
    }

    public function create(Request $request)
    {
        $supplier = $request->filled('supplier_id') ? Supplier::find($request->integer('supplier_id')) : null;

        return Inertia::render('PaymentOrders/Create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'balance']),
            'supplier' => $supplier?->only(['id', 'name', 'balance', 'cuit']),
            'pending' => $supplier ? Purchase::where('supplier_id', $supplier->id)->where('status', 'confirmed')
                ->whereColumn('paid_amount', '<', 'total')->orderBy('due_date')->orderBy('date')
                ->get(['id', 'voucher_type', 'voucher_number', 'date', 'due_date', 'total', 'paid_amount']) : [],
            'methods' => collect(CashService::METHODS)->except(['account', 'mercadopago', 'debit_card']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $this->ctx->id())],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'from_cash' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.method' => ['required', Rule::in(array_keys(CashService::METHODS)), 'not_in:account'],
            'lines.*.amount' => ['required', 'numeric', 'min:0'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'lines.*.bank' => ['nullable', 'string', 'max:60'],
            'lines.*.check_date' => ['nullable', 'date'],
            'applications' => ['array'],
            'applications.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $order = $this->service->create($data);

        return redirect()->route('payment-orders.show', $order)->with('success', "Orden de pago #{$order->number} registrada.");
    }

    public function show(PaymentOrder $order)
    {
        return Inertia::render('PaymentOrders/Show', [
            'order' => $order->load('supplier', 'user:id,name', 'branch:id,name', 'lines', 'applications.purchase:id,voucher_type,voucher_number,date,total,paid_amount'),
            'company' => $this->ctx->tenant->only(['name', 'legal_name', 'cuit', 'address', 'phone']),
            'methods' => CashService::METHODS,
        ]);
    }

    public function cancel(PaymentOrder $order)
    {
        $this->service->cancel($order);

        return back()->with('success', 'Orden de pago anulada: las facturas vuelven a quedar pendientes.');
    }
}
