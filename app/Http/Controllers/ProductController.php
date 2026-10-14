<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductCode;
use App\Models\ProductPrice;
use App\Models\ProductPriceHistory;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Unit;
use App\Services\PriceUpdateService;
use App\Services\PricingService;
use App\Services\ProductImportService;
use App\Services\StockService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function __construct(private TenantContext $ctx, private PricingService $pricing) {}

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'category_id', 'brand_id', 'supplier_id', 'status', 'stock', 'sort']);
        $branchId = $this->ctx->branchId();

        $products = $this->filtered($filters)
            ->with(['category:id,name', 'brand:id,name', 'unit:id,abbreviation', 'supplier:id,name'])
            ->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branchId))
            ->select('products.*', DB::raw('COALESCE(ps.quantity,0) as branch_stock'), DB::raw('COALESCE(ps.min_stock, products.min_stock) as branch_min'))
            ->when(($filters['stock'] ?? null) === 'low', fn ($q) => $q->whereRaw('COALESCE(ps.quantity,0) <= COALESCE(ps.min_stock, products.min_stock)')->whereRaw('COALESCE(ps.min_stock, products.min_stock) > 0'))
            ->when(($filters['stock'] ?? null) === 'zero', fn ($q) => $q->whereRaw('COALESCE(ps.quantity,0) <= 0'))
            ->when(($filters['stock'] ?? null) === 'outdated', fn ($q) => $q->whereRaw(PricingService::outdatedSql()))
            ->tap(fn ($q) => match ($filters['sort'] ?? 'name') {
                'code' => $q->orderBy('products.code'),
                'price_desc' => $q->orderByDesc('products.price'),
                'price_asc' => $q->orderBy('products.price'),
                'updated' => $q->orderByDesc('products.price_updated_at'),
                'stock' => $q->orderBy('branch_stock'),
                default => $q->orderBy('products.name'),
            })
            ->paginate(25)->withQueryString()
            ->through(fn ($p) => $this->present($p));

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function filtered(array $filters)
    {
        return Product::query()
            ->search($filters['search'] ?? null)
            ->when($filters['category_id'] ?? null, function ($q, $id) {
                $ids = Category::where('parent_id', $id)->pluck('id')->push((int) $id);
                $q->whereIn('products.category_id', $ids);
            })
            ->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('products.brand_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('products.supplier_id', $id)->orWhereHas('supplierProducts', fn ($s) => $s->where('supplier_id', $id))))
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('products.is_active', ($filters['status'] ?? 'active') === 'active'));
    }

    private function present(Product $p): array
    {
        $canCost = auth()->user()->hasPermission('products.view_cost');
        $suggested = $this->pricing->priceFromCost((float) $p->cost, (float) $p->markup, (float) $p->vat_rate);

        return [
            'id' => $p->id,
            'code' => $p->code,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'category' => $p->category?->name,
            'brand' => $p->brand?->name,
            'supplier' => $p->supplier?->name,
            'unit' => $p->unit?->abbreviation,
            'price' => (float) $p->price,
            'cost' => $canCost ? (float) $p->cost : null,
            'markup' => $canCost ? (float) $p->markup : null,
            'margin' => $canCost ? $p->currentMargin() : null,
            'suggested_price' => $canCost ? $suggested : null,
            'outdated' => $canCost && (float) $p->cost > 0 && (float) $p->price < $suggested * 0.98,
            'stock' => (float) ($p->branch_stock ?? 0),
            'min_stock' => (float) ($p->branch_min ?? $p->min_stock),
            'is_active' => $p->is_active,
            'price_updated_at' => $p->price_updated_at?->toDateString(),
            'allow_fraction' => $p->allow_fraction,
        ];
    }

    /** Búsqueda rápida (POS, compras, transferencias). Prioriza coincidencia exacta de código/código de barras. */
    public function search(Request $request)
    {
        $term = trim((string) $request->input('q'));
        $branchId = $this->ctx->branchId();
        $list = $request->filled('price_list_id') ? PriceList::find($request->integer('price_list_id')) : null;
        $supplierId = $request->integer('supplier_id') ?: null;

        $exact = Product::where('is_active', true)->where(fn ($q) => $q->where('code', $term)->orWhere('barcode', $term)
            ->orWhereHas('codes', fn ($c) => $c->where('code', $term))
            ->when($supplierId, fn ($w) => $w->orWhereHas('supplierProducts', fn ($s) => $s->where('supplier_id', $supplierId)->where('supplier_code', $term))))
            ->limit(1)->get();

        $results = $exact->isNotEmpty() ? $exact : Product::where('is_active', true)->search($term)->orderBy('name')->limit(25)->get();
        $results->load(['unit:id,abbreviation,allows_decimals', 'prices', 'stocks' => fn ($q) => $q->where('branch_id', $branchId)]);
        if ($supplierId) {
            $results->load(['supplierProducts' => fn ($q) => $q->where('supplier_id', $supplierId)]);
        }
        $canCost = $request->user()->hasPermission('products.view_cost') || $request->user()->hasPermission('purchases.manage');

        return response()->json([
            'exact' => $exact->isNotEmpty(),
            'items' => $results->map(fn (Product $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'price' => $this->pricing->priceFor($p, $list),
                'base_price' => (float) $p->price,
                'cost' => $canCost ? (float) $p->cost : null,
                'vat_rate' => (float) $p->vat_rate,
                'unit' => $p->unit?->abbreviation ?? 'un',
                'allow_fraction' => $p->allow_fraction,
                'stock' => (float) ($p->stocks->first()?->quantity ?? 0),
                'track_stock' => $p->track_stock,
                'purchase_unit_name' => $p->purchase_unit_name,
                'purchase_unit_factor' => (float) $p->purchase_unit_factor,
                'supplier_code' => $supplierId ? $p->supplierProducts->first()?->supplier_code : null,
                'supplier_cost' => $supplierId ? (float) ($p->supplierProducts->first()?->cost ?? 0) : null,
                'location' => $p->location,
            ]),
        ]);
    }

    public function show(Product $product)
    {
        $canCost = auth()->user()->hasPermission('products.view_cost');
        $product->load(['category', 'brand', 'unit', 'supplier', 'codes', 'prices.priceList', 'stocks.branch',
            'supplierProducts' => fn ($q) => $q->with('supplier:id,name,discount_1,discount_2,discount_3')->orderBy('cost')]);

        $lists = PriceList::where('is_active', true)->orderByDesc('is_default')->get()->map(fn ($l) => [
            'id' => $l->id, 'name' => $l->name, 'price' => $this->pricing->priceFor($product, $l),
            'override' => (bool) $product->prices->firstWhere('price_list_id', $l->id),
        ]);

        $sales = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.product_id', $product->id)->where('sales.status', 'completed')->where('sales.type', 'sale')
            ->where('sales.created_at', '>=', now()->subDays(90))
            ->selectRaw('COALESCE(SUM(sale_items.quantity),0) qty, COALESCE(SUM(sale_items.subtotal),0) total, MAX(sales.created_at) last_sale')->first();

        return Inertia::render('Products/Show', [
            'product' => array_merge($product->toArray(), [
                'margin' => $product->currentMargin(),
                'suggested_price' => $this->pricing->priceFromCost((float) $product->cost, (float) $product->markup, (float) $product->vat_rate),
                'cost' => $canCost ? $product->cost : null,
                'average_cost' => $canCost ? $product->average_cost : null,
                'last_purchase_cost' => $canCost ? $product->last_purchase_cost : null,
            ]),
            'canCost' => $canCost,
            'lists' => $lists,
            'branches' => Branch::where('is_active', true)->get(['id', 'name']),
            'history' => $canCost ? ProductPriceHistory::where('product_id', $product->id)->with('user:id,name', 'supplier:id,name')->latest('created_at')->limit(50)->get() : [],
            'movements' => $product->movements()->with('branch:id,name', 'user:id,name')->latest('created_at')->limit(50)->get(),
            'sales90' => $sales,
            'supplierProducts' => $canCost ? $product->supplierProducts : [],
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Products/Form', $this->formData(new Product([
            'vat_rate' => $this->ctx->setting('default_vat', 21),
            'markup' => $this->ctx->setting('default_markup', 40),
            'is_active' => true, 'track_stock' => true, 'purchase_unit_factor' => 1,
            'code' => $this->nextCode(),
        ])));
    }

    public function edit(Product $product)
    {
        $product->load(['codes', 'prices', 'supplierProducts.supplier:id,name']);

        return Inertia::render('Products/Form', $this->formData($product));
    }

    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => Category::orderBy('name')->get(['id', 'name', 'parent_id', 'default_markup']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'abbreviation', 'allows_decimals']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'discount_1', 'discount_2', 'discount_3', 'prices_include_vat']),
            'priceLists' => PriceList::where('is_default', false)->where('is_active', true)->get(['id', 'name', 'adjustment_percent']),
            'rounding' => ['step' => (float) $this->ctx->setting('price_rounding'), 'mode' => $this->ctx->setting('rounding_mode')],
            'initialStock' => ! $product->exists,
        ];
    }

    private function nextCode(): string
    {
        $max = (int) Product::withTrashed()->whereRaw("code REGEXP '^[0-9]+$'")->max(DB::raw('CAST(code AS UNSIGNED)'));

        return str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    private function rules(?Product $product = null): array
    {
        $tenantId = $this->ctx->id();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('products')->where('tenant_id', $tenantId)->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $tenantId)],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('tenant_id', $tenantId)],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('tenant_id', $tenantId)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'cost' => ['required', 'numeric', 'min:0'],
            'vat_rate' => ['required', 'numeric', Rule::in([0, 2.5, 5, 10.5, 21, 27])],
            'markup' => ['required', 'numeric', 'min:-100', 'max:10000'],
            'price' => ['required', 'numeric', 'min:0'],
            'allow_fraction' => ['boolean'],
            'purchase_unit_name' => ['nullable', 'string', 'max:30'],
            'purchase_unit_factor' => ['required', 'numeric', 'gt:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:60'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'codes' => ['array'],
            'codes.*.code' => ['required', 'string', 'max:60'],
            'codes.*.type' => ['required', Rule::in(['barcode', 'alternative'])],
            'list_prices' => ['array'],
            'list_prices.*' => ['nullable', 'numeric', 'min:0'],
            'supplier_products' => ['array'],
            'supplier_products.*.supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId)],
            'supplier_products.*.supplier_code' => ['nullable', 'string', 'max:60'],
            'supplier_products.*.list_price' => ['nullable', 'numeric', 'min:0'],
            'supplier_products.*.pack_quantity' => ['nullable', 'numeric', 'gt:0'],
            'initial_stock' => ['nullable', 'numeric'],
        ];
    }

    public function store(Request $request, StockService $stock)
    {
        $data = $request->validate($this->rules());
        $product = DB::transaction(function () use ($data, $stock) {
            $product = Product::create($this->productAttributes($data) + ['average_cost' => $data['cost'], 'cost_updated_at' => now(), 'price_updated_at' => now()]);
            $this->syncRelations($product, $data);
            if (! empty($data['initial_stock'])) {
                $stock->move($product, $this->ctx->branchId(), (float) $data['initial_stock'], 'initial', null, (float) $data['cost'], 'Alta de producto');
            }

            return $product;
        });

        return redirect()->route('products.show', $product)->with('success', 'Producto creado.');
    }

    public function update(Request $request, Product $product, PriceUpdateService $updates)
    {
        $data = $request->validate($this->rules($product));
        DB::transaction(function () use ($product, $data, $updates) {
            $attrs = $this->productAttributes($data);
            // Los cambios de costo/precio se registran en el historial como actualización manual
            $updates->applyChanges('manual', "Edición manual: {$product->name}", collect([[
                'product' => $product, 'new_cost' => (float) $attrs['cost'], 'new_price' => (float) $attrs['price'], 'new_markup' => (float) $attrs['markup'],
            ]]));
            unset($attrs['cost'], $attrs['price'], $attrs['markup']);
            $product->update($attrs);
            $this->syncRelations($product, $data);
        });

        return redirect()->route('products.show', $product)->with('success', 'Producto actualizado.');
    }

    private function productAttributes(array $data): array
    {
        return collect($data)->only([
            'code', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'unit_id', 'supplier_id', 'cost', 'vat_rate', 'markup',
            'price', 'allow_fraction', 'purchase_unit_name', 'purchase_unit_factor', 'min_stock', 'location', 'track_stock', 'is_active', 'notes',
        ])->map(fn ($v, $k) => $k === 'min_stock' ? ($v ?? 0) : $v)->all();
    }

    private function syncRelations(Product $product, array $data): void
    {
        if (array_key_exists('codes', $data)) {
            $product->codes()->delete();
            foreach ($data['codes'] as $c) {
                ProductCode::create(['product_id' => $product->id, 'code' => $c['code'], 'type' => $c['type']]);
            }
        }
        if (array_key_exists('list_prices', $data)) {
            foreach ($data['list_prices'] as $listId => $price) {
                if ($price === null || $price === '') {
                    ProductPrice::where('product_id', $product->id)->where('price_list_id', $listId)->delete();
                } elseif (PriceList::whereKey($listId)->exists()) {
                    ProductPrice::updateOrCreate(['product_id' => $product->id, 'price_list_id' => $listId], ['price' => $price]);
                }
            }
        }
        if (array_key_exists('supplier_products', $data)) {
            $keep = [];
            foreach ($data['supplier_products'] as $sp) {
                $supplier = Supplier::find($sp['supplier_id']);
                $model = SupplierProduct::firstOrNew(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
                $pack = (float) ($sp['pack_quantity'] ?? 1) ?: 1;
                $list = (float) ($sp['list_price'] ?? 0);
                $model->fill([
                    'tenant_id' => $product->tenant_id,
                    'supplier_code' => $sp['supplier_code'] ?? null,
                    'list_price' => $list,
                    'pack_quantity' => $pack,
                    'cost' => $list > 0 ? $this->pricing->supplierCost($list, $supplier->discounts(), $pack, $supplier->prices_include_vat, (float) $product->vat_rate) : (float) $model->cost,
                    'is_preferred' => $product->supplier_id === $supplier->id,
                ])->save();
                $keep[] = $model->id;
            }
            SupplierProduct::where('product_id', $product->id)->whereNotIn('id', $keep)->delete();
        }
    }

    public function destroy(Product $product)
    {
        $product->update(['is_active' => false]);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Producto eliminado.');
    }

    public function export(Request $request, ProductImportService $service)
    {
        $products = $this->filtered($request->only(['search', 'category_id', 'brand_id', 'supplier_id', 'status']))
            ->with(['category', 'brand', 'unit', 'supplier', 'stocks'])->orderBy('name')->get();

        return response()->download($service->export($products), 'productos.xlsx')->deleteFileAfterSend();
    }

    public function importForm()
    {
        return Inertia::render('Products/Import', ['columns' => ProductImportService::COLUMNS]);
    }

    public function template(ProductImportService $service)
    {
        return response()->download($service->template(), 'plantilla-productos.xlsx');
    }

    public function import(Request $request, ProductImportService $service)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt,ods', 'max:20480'], 'update_existing' => ['boolean']]);
        @set_time_limit(600);
        $result = $service->import($request->file('file')->getRealPath(), $request->boolean('update_existing', true));

        return back()->with('success', "Importación finalizada: {$result['created']} creados, {$result['updated']} actualizados.")
            ->with('data', ['errors' => array_slice($result['errors'], 0, 200)]);
    }

    /** Etiquetas de góndola: por selección o por productos con cambio de precio desde una fecha */
    public function labels(Request $request)
    {
        $q = Product::query()->where('is_active', true)->with('unit:id,abbreviation');
        if ($request->filled('ids')) {
            $q->whereIn('id', array_filter(explode(',', $request->input('ids'))));
        } elseif ($request->filled('since')) {
            $q->where('price_updated_at', '>=', $request->date('since')->startOfDay());
        } elseif ($request->filled('price_update_id')) {
            $q->whereIn('id', \App\Models\PriceUpdateItem::where('price_update_id', $request->integer('price_update_id'))->pluck('product_id'));
        } else {
            $q->whereRaw('1=0');
        }

        return Inertia::render('Products/Labels', [
            'products' => $q->orderBy('name')->limit(1000)->get(['id', 'code', 'barcode', 'name', 'price', 'unit_id', 'price_updated_at']),
            'filters' => $request->only(['ids', 'since', 'price_update_id', 'format']),
            'company' => $this->ctx->tenant->name,
        ]);
    }
}
