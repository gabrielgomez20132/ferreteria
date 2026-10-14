<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceUpdate;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\PriceUpdateService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Historial de actualizaciones (con reversión), aumentos masivos, precios a revisar y comparativa de proveedores.
 */
class PriceUpdateController extends Controller
{
    public function __construct(private PriceUpdateService $updates, private PricingService $pricing) {}

    public function index(Request $request)
    {
        return Inertia::render('Prices/Updates/Index', [
            'updates' => PriceUpdate::with('user:id,name', 'supplier:id,name', 'reverter:id,name')
                ->when($request->source, fn ($q, $s) => $q->where('source', $s))
                ->latest()->paginate(25)->withQueryString(),
            'filters' => $request->only('source'),
        ]);
    }

    public function show(PriceUpdate $priceUpdate)
    {
        return Inertia::render('Prices/Updates/Show', [
            'update' => $priceUpdate->load('user:id,name', 'supplier:id,name', 'reverter:id,name'),
            'items' => $priceUpdate->items()->with('product:id,code,name')->paginate(100),
        ]);
    }

    public function revert(PriceUpdate $priceUpdate)
    {
        $n = $this->updates->revert($priceUpdate);

        return back()->with('success', "Actualización revertida: {$n} productos volvieron a su costo y precio anterior.");
    }

    // ---------------- Aumento / baja masiva ----------------

    public function mass()
    {
        return Inertia::render('Prices/Mass', $this->filterOptions());
    }

    private function filterOptions(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'priceActions' => PricingService::PRICE_ACTIONS,
        ];
    }

    private function massRules(): array
    {
        return [
            'filters' => ['array'],
            'filters.search' => ['nullable', 'string'],
            'filters.category_id' => ['nullable', 'integer'],
            'filters.brand_id' => ['nullable', 'integer'],
            'filters.supplier_id' => ['nullable', 'integer'],
            'target' => ['required', Rule::in(['cost', 'price', 'markup'])],
            'percent' => ['required', 'numeric', 'min:-90', 'max:1000'],
            'price_action' => ['required_if:target,cost', 'nullable', Rule::in(array_keys(PricingService::PRICE_ACTIONS))],
            'custom_markup' => ['nullable', 'numeric'],
            'excluded' => ['array'],
            'excluded.*' => ['integer'],
        ];
    }

    private function massQuery(array $f)
    {
        return Product::query()->where('is_active', true)
            ->search($f['search'] ?? null)
            ->when($f['category_id'] ?? null, fn ($q, $id) => $q->whereIn('category_id', Category::where('parent_id', $id)->pluck('id')->push((int) $id)))
            ->when($f['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id))
            ->when($f['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->orderBy('name');
    }

    public function massPreview(Request $request)
    {
        $data = $request->validate($this->massRules());
        $q = $this->massQuery($data['filters'] ?? []);
        $total = (clone $q)->count();
        $preview = $this->updates->previewMass($q->limit(300), $data)->map(fn ($c) => [
            'id' => $c['product']->id,
            'code' => $c['product']->code,
            'name' => $c['product']->name,
            'old_cost' => (float) $c['product']->cost,
            'new_cost' => $c['new_cost'],
            'old_price' => (float) $c['product']->price,
            'new_price' => $c['new_price'],
            'old_markup' => (float) $c['product']->markup,
            'new_markup' => $c['new_markup'],
        ]);

        return Inertia::render('Prices/Mass', array_merge($this->filterOptions(), [
            'preview' => $preview,
            'total' => $total,
            'form' => $data,
        ]));
    }

    public function massApply(Request $request)
    {
        $data = $request->validate($this->massRules());
        @set_time_limit(600);
        $q = $this->massQuery($data['filters'] ?? [])->whereNotIn('id', $data['excluded'] ?? []);
        $changes = $this->updates->previewMass($q, $data);
        $label = match ($data['target']) {
            'cost' => 'Costo', 'price' => 'Precio', 'markup' => 'Margen fijo',
        };
        $desc = "{$label} ".($data['target'] === 'markup' ? "{$data['percent']}%" : (($data['percent'] > 0 ? '+' : '')."{$data['percent']}%"));
        $f = array_filter($data['filters'] ?? []);
        if ($f) {
            $desc .= ' · '.collect([
                isset($f['category_id']) ? 'Cat: '.Category::find($f['category_id'])?->name : null,
                isset($f['brand_id']) ? 'Marca: '.Brand::find($f['brand_id'])?->name : null,
                isset($f['supplier_id']) ? 'Prov: '.Supplier::find($f['supplier_id'])?->name : null,
                isset($f['search']) ? "\"{$f['search']}\"" : null,
            ])->filter()->implode(', ');
        }
        $update = $this->updates->applyChanges('mass_update', 'Actualización masiva: '.$desc, $changes, $data, $f['supplier_id'] ?? null);

        return redirect()->route('prices.updates')->with('success', $update ? "Se actualizaron {$update->products_count} productos." : 'No hubo cambios para aplicar.');
    }

    // ---------------- Precios a revisar (margen real por debajo del objetivo) ----------------

    public function review(Request $request)
    {
        $tolerance = (float) $request->input('tolerance', 2);
        $products = Product::query()->where('is_active', true)
            ->whereRaw(PricingService::outdatedSql($tolerance))
            ->with('supplier:id,name', 'category:id,name')
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->orderByRaw('price / '.PricingService::suggestedPriceSql().' ASC')
            ->paginate(50)->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                'supplier' => $p->supplier?->name, 'category' => $p->category?->name,
                'cost' => (float) $p->cost, 'markup' => (float) $p->markup, 'margin' => $p->currentMargin(),
                'price' => (float) $p->price,
                'suggested' => $this->pricing->priceFromCost((float) $p->cost, (float) $p->markup, (float) $p->vat_rate),
                'cost_updated_at' => $p->cost_updated_at?->toDateTimeString(),
                'price_updated_at' => $p->price_updated_at?->toDateTimeString(),
            ]);

        return Inertia::render('Prices/Review', [
            'products' => $products,
            'filters' => $request->only(['tolerance', 'supplier_id', 'category_id']),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function reviewApply(Request $request)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'all' => ['boolean'],
            'tolerance' => ['nullable', 'numeric'],
        ]);
        $q = Product::where('is_active', true)->whereRaw(PricingService::outdatedSql((float) ($data['tolerance'] ?? 2)));
        if (empty($data['all'])) {
            $q->whereIn('id', $data['ids'] ?? []);
        }
        $changes = $q->get()->map(fn (Product $p) => [
            'product' => $p, 'new_cost' => (float) $p->cost, 'new_markup' => (float) $p->markup,
            'new_price' => $this->pricing->priceFromCost((float) $p->cost, (float) $p->markup, (float) $p->vat_rate),
        ]);
        $update = $this->updates->applyChanges('review', 'Revisión de precios desactualizados', $changes);

        return back()->with('success', $update ? "Se actualizaron {$update->products_count} precios." : 'No hubo cambios.');
    }

    // ---------------- Comparativa de costos entre proveedores ----------------

    public function comparison(Request $request)
    {
        $products = Product::query()->where('is_active', true)
            ->whereHas('supplierProducts', fn ($q) => $q->where('cost', '>', 0), '>=', $request->boolean('only_multi', true) ? 2 : 1)
            ->search($request->search)
            ->when($request->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->with(['supplier:id,name', 'supplierProducts' => fn ($q) => $q->where('cost', '>', 0)->with('supplier:id,name')->orderBy('cost')])
            ->orderBy('name')->paginate(40)->withQueryString()
            ->through(function (Product $p) {
                $best = $p->supplierProducts->first();
                $current = $p->supplierProducts->firstWhere('supplier_id', $p->supplier_id);

                return [
                    'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'cost' => (float) $p->cost,
                    'main_supplier' => $p->supplier?->name,
                    'best' => $best ? ['supplier' => $best->supplier?->name, 'cost' => (float) $best->cost] : null,
                    'saving_percent' => $current && $best && (float) $current->cost > 0 && $best->id !== $current->id
                        ? round((1 - (float) $best->cost / (float) $current->cost) * 100, 1) : 0,
                    'offers' => $p->supplierProducts->map(fn (SupplierProduct $sp) => [
                        'supplier' => $sp->supplier?->name, 'supplier_code' => $sp->supplier_code, 'cost' => (float) $sp->cost,
                        'list_price' => (float) $sp->list_price, 'updated' => $sp->last_price_at?->toDateString(),
                    ]),
                ];
            });

        $summary = DB::table('supplier_products as sp')
            ->join('suppliers as s', 's.id', '=', 'sp.supplier_id')
            ->where('sp.tenant_id', app(\App\Support\TenantContext::class)->id())
            ->selectRaw('s.id, s.name, COUNT(*) products, MAX(sp.last_price_at) last_update')
            ->groupBy('s.id', 's.name')->orderBy('s.name')->get();

        return Inertia::render('Prices/Comparison', [
            'products' => $products,
            'summary' => $summary,
            'filters' => $request->only(['search', 'category_id', 'only_multi']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
