<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\AccountService;
use App\Services\CashService;
use App\Services\PricingService;
use App\Support\BusinessException;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SupplierController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    public function index(Request $request)
    {
        return Inertia::render('Suppliers/Index', [
            'suppliers' => Supplier::withCount('supplierProducts')
                ->withMax('supplierProducts as last_price_at', 'last_price_at')
                ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('cuit', 'like', "{$s}%")))
                ->when($request->debt, fn ($q) => $q->where('balance', '>', 0))
                ->orderBy('name')->paginate(30)->withQueryString(),
            'filters' => $request->only(['search', 'debt']),
            'totalDebt' => (float) Supplier::where('balance', '>', 0)->sum('balance'),
        ]);
    }

    public function show(Request $request, Supplier $supplier)
    {
        $products = $supplier->supplierProducts()->with('product:id,code,name,cost,price,supplier_id,is_active')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('supplier_code', 'like', "%{$s}%")->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('code', $s))))
            ->orderBy('supplier_code')->paginate(30, ['*'], 'products_page')->withQueryString();

        return Inertia::render('Suppliers/Show', [
            'supplier' => $supplier,
            'products' => $products,
            'movements' => $supplier->movements()->with('user:id,name')->latest()->latest('id')->paginate(20, ['*'], 'mov_page')->withQueryString(),
            'imports' => $supplier->priceImports()->latest()->limit(10)->get(['id', 'file_name', 'status', 'matched_rows', 'avg_variation', 'created_at', 'applied_at']),
            'purchases' => $supplier->purchases()->latest('date')->limit(10)->get(['id', 'voucher_type', 'voucher_number', 'date', 'total', 'status']),
            'methods' => collect(CashService::METHODS)->except('account'),
            'filters' => $request->only('search'),
            'rules' => $supplier->discountRules()->with('brand:id,name', 'category:id,name')->get(),
            'brands' => \App\Models\Brand::orderBy('name')->get(['id', 'name']),
            'categories' => \App\Models\Category::orderBy('name')->get(['id', 'name']),
            'pendingPurchases' => $supplier->purchases()->where('status', 'confirmed')->whereColumn('paid_amount', '<', 'total')
                ->orderBy('due_date')->get(['id', 'voucher_type', 'voucher_number', 'date', 'due_date', 'total', 'paid_amount']),
            'paymentOrders' => \App\Models\PaymentOrder::where('supplier_id', $supplier->id)->latest('date')->limit(10)->get(['id', 'number', 'date', 'total', 'status']),
        ]);
    }

    private function rules(?Supplier $s = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'tax_condition' => ['nullable', 'string', 'max:40'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:200'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_1' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'discount_2' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'discount_3' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'prices_include_vat' => ['boolean'],
            'default_markup' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    private function normalize(array $data): array
    {
        foreach (['discount_1', 'discount_2', 'discount_3', 'payment_term_days'] as $k) {
            $data[$k] = $data[$k] ?? 0;
        }

        return $data;
    }

    public function store(Request $request)
    {
        $s = Supplier::create($this->normalize($request->validate($this->rules())));

        return redirect()->route('suppliers.show', $s)->with('success', 'Proveedor creado.');
    }

    public function update(Request $request, Supplier $supplier, PricingService $pricing)
    {
        $data = $this->normalize($request->validate($this->rules($supplier)));
        $discountsChanged = collect(['discount_1', 'discount_2', 'discount_3', 'prices_include_vat'])
            ->contains(fn ($k) => (string) $supplier->{$k} !== (string) ($data[$k] ?? null));
        $supplier->update($data);

        // Si cambian las bonificaciones, se recalcula el costo de cada artículo del proveedor (no cambia precios de venta)
        if ($discountsChanged && $request->boolean('recalculate_costs')) {
            $discounts = app(\App\Services\SupplierDiscounts::class);
            $supplier->supplierProducts()->with('product:id,vat_rate,brand_id,category_id')->where('list_price', '>', 0)->chunkById(500, function ($rows) use ($supplier, $pricing, $discounts) {
                foreach ($rows as $sp) {
                    $sp->update(['cost' => $pricing->supplierCost((float) $sp->list_price,
                        $discounts->forProduct($supplier, $sp, $sp->product?->brand_id, $sp->product?->category_id),
                        (float) $sp->pack_quantity, (bool) $supplier->prices_include_vat, (float) ($sp->product?->vat_rate ?? 21))]);
                }
            });
        }

        return back()->with('success', 'Proveedor actualizado.');
    }

    public function destroy(Supplier $supplier)
    {
        if (abs((float) $supplier->balance) > 0.009) {
            throw new BusinessException('No se puede eliminar un proveedor con saldo pendiente.');
        }
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Proveedor eliminado.');
    }

    /** Pago rápido: genera una orden de pago de un solo medio, imputada automáticamente a las facturas más viejas */
    public function payment(Request $request, Supplier $supplier, \App\Services\PaymentOrderService $orders)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(array_keys(CashService::METHODS)), 'not_in:account'],
            'from_cash' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        $order = $orders->create([
            'supplier_id' => $supplier->id,
            'date' => now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'from_cash' => $request->boolean('from_cash'),
            'lines' => [['method' => $data['method'], 'amount' => $data['amount']]],
        ]);

        return back()->with('success', "Pago registrado (orden de pago #{$order->number}).");
    }

    public function storeRule(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('tenant_id', $this->ctx->id())],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $this->ctx->id())],
            'discount_1' => ['required', 'numeric', 'min:-100', 'max:100'],
            'discount_2' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'discount_3' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
        if (empty($data['brand_id']) && empty($data['category_id'])) {
            throw new BusinessException('Elegí una marca, una categoría o ambas.');
        }
        $attrs = ['brand_id' => $data['brand_id'] ?? null, 'category_id' => $data['category_id'] ?? null,
            'discount_1' => $data['discount_1'], 'discount_2' => $data['discount_2'] ?? 0, 'discount_3' => $data['discount_3'] ?? 0, 'notes' => $data['notes'] ?? null];
        if (! empty($data['id'])) {
            $supplier->discountRules()->whereKey($data['id'])->firstOrFail()->update($attrs);
        } else {
            $supplier->discountRules()->create($attrs);
        }

        return back()->with('success', 'Bonificación guardada. Se aplicará en la próxima lista de precios que importes.');
    }

    public function destroyRule(Supplier $supplier, int $rule)
    {
        $supplier->discountRules()->whereKey($rule)->delete();

        return back()->with('success', 'Bonificación eliminada.');
    }

    public function updateProduct(Request $request, Supplier $supplier, SupplierProduct $supplierProduct, PricingService $pricing)
    {
        abort_unless($supplierProduct->supplier_id === $supplier->id, 404);
        $data = $request->validate([
            'supplier_code' => ['nullable', 'string', 'max:60'],
            'list_price' => ['required', 'numeric', 'min:0'],
            'discount_1' => ['nullable', 'numeric'],
            'discount_2' => ['nullable', 'numeric'],
            'discount_3' => ['nullable', 'numeric'],
            'pack_quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $supplierProduct->fill($data);
        $p = $supplierProduct->product;
        $supplierProduct->cost = $pricing->supplierCost((float) $data['list_price'],
            app(\App\Services\SupplierDiscounts::class)->forProduct($supplier, $supplierProduct, $p?->brand_id, $p?->category_id),
            (float) $data['pack_quantity'], (bool) $supplier->prices_include_vat, (float) ($p?->vat_rate ?? 21));
        $supplierProduct->save();

        return back()->with('success', 'Artículo del proveedor actualizado.');
    }

    public function destroyProduct(Supplier $supplier, SupplierProduct $supplierProduct)
    {
        abort_unless($supplierProduct->supplier_id === $supplier->id, 404);
        $supplierProduct->delete();

        return back()->with('success', 'Vínculo eliminado.');
    }
}
