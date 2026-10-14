<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PricingService;
use App\Services\PurchaseService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PurchaseController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index(Request $request)
    {
        return Inertia::render('Purchases/Index', [
            'purchases' => Purchase::with('supplier:id,name', 'branch:id,name', 'user:id,name')
                ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
                ->when($request->from, fn ($q, $d) => $q->where('date', '>=', $d))
                ->when($request->to, fn ($q, $d) => $q->where('date', '<=', $d))
                ->when($request->search, fn ($q, $s) => $q->where('voucher_number', 'like', "%{$s}%"))
                ->latest('date')->latest('id')->paginate(30)->withQueryString(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['supplier_id', 'from', 'to', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $order = $request->filled('order')
            ? PurchaseOrder::with('items.product:id,code,name,vat_rate,cost,purchase_unit_name,purchase_unit_factor')->find($request->integer('order'))
            : null;

        return Inertia::render('Purchases/Create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'payment_term_days', 'discount_1', 'discount_2', 'discount_3']),
            'order' => $order,
            'priceActions' => PricingService::PRICE_ACTIONS,
            'defaultPriceAction' => $this->ctx->setting('default_price_action', 'keep_markup'),
        ]);
    }

    public function store(Request $request, PurchaseService $service)
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $this->ctx->id())],
            'purchase_order_id' => ['nullable', Rule::exists('purchase_orders', 'id')->where('tenant_id', $this->ctx->id())],
            'voucher_type' => ['required', Rule::in(['FA', 'FB', 'FC', 'REMITO', 'TICKET'])],
            'voucher_number' => ['nullable', 'string', 'max:40'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'other_taxes' => ['nullable', 'numeric', 'min:0'],
            'extra_costs' => ['nullable', 'numeric', 'min:0'],
            'update_costs' => ['boolean'],
            'price_action' => ['required', Rule::in(array_keys(PricingService::PRICE_ACTIONS))],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_factor' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_rate' => ['required', 'numeric'],
            'items.*.purchase_order_item_id' => ['nullable', 'integer'],
        ]);
        $purchase = $service->create($data);

        return redirect()->route('purchases.show', $purchase)->with('success', 'Compra registrada: stock ingresado y costos actualizados.');
    }

    public function show(Purchase $purchase)
    {
        return Inertia::render('Purchases/Show', [
            'purchase' => $purchase->load('supplier', 'branch:id,name', 'user:id,name', 'items.product:id,code,name,price', 'purchaseOrder:id,number'),
            'priceUpdate' => \App\Models\PriceUpdate::where('source', 'purchase')->where('source_id', $purchase->id)->first(['id', 'products_count', 'status']),
        ]);
    }

    public function cancel(Purchase $purchase, PurchaseService $service)
    {
        $service->cancel($purchase);

        return back()->with('success', 'Compra anulada: se descontó el stock y se ajustó la cuenta del proveedor.');
    }

    /** Genera pedidos en borrador por proveedor con todo lo que está bajo mínimo */
    public function autoOrders(Request $request, PurchaseService $service)
    {
        $orders = $service->generateAutoOrders($this->ctx->branchId(), $request->integer('supplier_id') ?: null);
        if ($orders->isEmpty()) {
            return back()->with('error', 'No hay faltantes sin pedir: todo lo que está bajo mínimo ya tiene un pedido abierto.');
        }

        return redirect()->route('purchase-orders.index', ['status' => 'draft'])
            ->with('success', "Se generaron {$orders->count()} pedidos en borrador. Revisalos y marcá \"Enviado\" cuando se los pases al proveedor.");
    }

    public function suggestions(Request $request, PurchaseService $service)
    {
        return Inertia::render('Purchases/Suggestions', [
            'items' => $service->suggestions($this->ctx->branchId(), $request->integer('supplier_id') ?: null),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('supplier_id'),
        ]);
    }
}
