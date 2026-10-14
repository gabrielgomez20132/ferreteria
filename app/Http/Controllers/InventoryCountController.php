<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\InventoryCount;
use App\Services\InventoryService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InventoryCountController extends Controller
{
    public function __construct(private InventoryService $service, private TenantContext $ctx) {}

    public function index()
    {
        return Inertia::render('Stock/Inventory/Index', [
            'counts' => InventoryCount::with('branch:id,name', 'user:id,name', 'category:id,name')->withCount('items')->latest()->paginate(20),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $this->ctx->id())],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $count = $this->service->startCount($this->ctx->branchId(), $data['category_id'] ?? null, $data['notes'] ?? null);

        return redirect()->route('stock.inventory.show', $count);
    }

    public function show(Request $request, InventoryCount $count)
    {
        $items = $count->items()->with('product:id,code,barcode,name,location')
            ->join('products', 'products.id', '=', 'inventory_count_items.product_id')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('products.name', 'like', "%{$s}%")->orWhere('products.code', $s)->orWhere('products.barcode', $s)))
            ->when($request->only_diff, fn ($q) => $q->whereNotNull('counted_quantity')->whereRaw('counted_quantity <> system_quantity'))
            ->when($request->pending, fn ($q) => $q->whereNull('counted_quantity'))
            ->orderBy('products.location')->orderBy('products.name')
            ->select('inventory_count_items.*')
            ->paginate(100)->withQueryString();

        $summary = $count->items()->selectRaw('COUNT(*) total, SUM(counted_quantity IS NOT NULL) counted,
            SUM(CASE WHEN counted_quantity IS NOT NULL THEN (counted_quantity - system_quantity) * unit_cost ELSE 0 END) value_diff')->first();

        return Inertia::render('Stock/Inventory/Show', [
            'count' => $count->load('branch:id,name', 'category:id,name', 'user:id,name'),
            'items' => $items,
            'summary' => $summary,
            'filters' => $request->only(['search', 'only_diff', 'pending']),
        ]);
    }

    public function update(Request $request, InventoryCount $count)
    {
        abort_if($count->status !== 'open', 422);
        $data = $request->validate(['counts' => ['required', 'array'], 'counts.*' => ['nullable', 'numeric', 'min:0']]);
        foreach ($data['counts'] as $itemId => $qty) {
            $count->items()->whereKey($itemId)->update([
                'counted_quantity' => $qty,
                'difference' => $qty === null ? null : DB::raw(((float) $qty).' - system_quantity'),
            ]);
        }

        return back()->with('success', 'Conteo guardado.');
    }

    public function apply(InventoryCount $count)
    {
        $n = $this->service->applyCount($count);

        return back()->with('success', "Inventario aplicado: {$n} productos ajustados.");
    }

    public function cancel(InventoryCount $count)
    {
        abort_if($count->status !== 'open', 422);
        $count->update(['status' => 'cancelled']);

        return back()->with('success', 'Inventario cancelado.');
    }
}
