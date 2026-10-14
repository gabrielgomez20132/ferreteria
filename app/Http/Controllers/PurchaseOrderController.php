<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseService;
use App\Support\Numbering;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PurchaseOrderController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index(Request $request)
    {
        return Inertia::render('Purchases/Orders/Index', [
            'orders' => PurchaseOrder::with('supplier:id,name', 'branch:id,name', 'user:id,name')->withCount('items')
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(30)->withQueryString(),
            'filters' => $request->only('status'),
        ]);
    }

    public function create(Request $request)
    {
        // Se puede precargar desde "Sugerencias de compra": ?items[product_id]=cantidad&supplier_id=
        $prefill = [];
        if ($request->filled('items') && is_array($request->items)) {
            $supplierId = $request->integer('supplier_id');
            $products = Product::with(['supplierProducts' => fn ($q) => $q->where('supplier_id', $supplierId)])
                ->whereIn('id', array_keys($request->items))->get();
            foreach ($products as $p) {
                $sp = $p->supplierProducts->first();
                $prefill[] = [
                    'product_id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                    'supplier_code' => $sp?->supplier_code, 'quantity' => (float) $request->items[$p->id],
                    'unit_cost' => (float) ($sp?->cost ?: $p->cost),
                ];
            }
        }

        return Inertia::render('Purchases/Orders/Create', [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'prefill' => $prefill,
            'supplierId' => $request->integer('supplier_id') ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $this->ctx->id())],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.supplier_code' => ['nullable', 'string', 'max:60'],
        ]);
        $order = DB::transaction(function () use ($data) {
            $order = PurchaseOrder::create([
                'branch_id' => $this->ctx->branchId(),
                'supplier_id' => $data['supplier_id'],
                'user_id' => auth()->id(),
                'number' => Numbering::next('purchase_orders', ['tenant_id' => $this->ctx->id()]),
                'status' => 'draft',
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');
            $total = 0;
            foreach ($data['items'] as $i) {
                $p = $products->get($i['product_id']);
                if (! $p) {
                    continue;
                }
                $order->items()->create([
                    'product_id' => $p->id, 'supplier_code' => $i['supplier_code'] ?? null, 'description' => $p->name,
                    'quantity' => $i['quantity'], 'unit_cost' => $i['unit_cost'] ?? $p->cost,
                ]);
                $total += (float) $i['quantity'] * (float) ($i['unit_cost'] ?? $p->cost);
            }
            $order->update(['total' => round($total, 2)]);

            return $order;
        });

        return redirect()->route('purchase-orders.show', $order)->with('success', "Pedido #{$order->number} creado.");
    }

    public function show(PurchaseOrder $order)
    {
        return Inertia::render('Purchases/Orders/Show', [
            'order' => $order->load('supplier', 'branch:id,name', 'user:id,name', 'items.product:id,code,name', 'purchases:id,purchase_order_id,voucher_type,voucher_number,date,total,status'),
        ]);
    }

    public function print(PurchaseOrder $order)
    {
        return Inertia::render('Purchases/Orders/Print', [
            'order' => $order->load('supplier', 'branch', 'items'),
            'company' => $this->ctx->tenant->only(['name', 'cuit', 'address', 'phone', 'email']),
        ]);
    }

    public function status(Request $request, PurchaseOrder $order, PurchaseService $service)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['sent', 'cancelled', 'received'])]]);
        if ($data['status'] === 'received') {
            // Cierre manual de un pedido recibido parcialmente
            $order->update(['status' => 'received']);
        } else {
            $order->update(['status' => $data['status']]);
        }

        return back()->with('success', 'Estado del pedido actualizado.');
    }
}
