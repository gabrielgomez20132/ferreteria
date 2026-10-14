<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StockController extends Controller
{
    public function __construct(private TenantContext $ctx) {}

    /** Stock consolidado: una columna por sucursal */
    public function index(Request $request)
    {
        $branches = Branch::where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']);
        $products = Product::query()->where('is_active', true)->where('track_stock', true)
            ->search($request->search)
            ->when($request->category_id, fn ($q, $id) => $q->where('category_id', $id))
            ->with(['stocks:id,product_id,branch_id,quantity,min_stock', 'unit:id,abbreviation'])
            ->orderBy('name')->paginate(30)->withQueryString()
            ->through(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'unit' => $p->unit?->abbreviation,
                'min_stock' => (float) $p->min_stock, 'location' => $p->location,
                'cost' => $request->user()->hasPermission('products.view_cost') ? (float) $p->cost : null,
                'stocks' => $p->stocks->mapWithKeys(fn ($s) => [$s->branch_id => ['qty' => (float) $s->quantity, 'min' => $s->min_stock !== null ? (float) $s->min_stock : null]]),
                'total' => (float) $p->stocks->sum('quantity'),
            ]);

        return Inertia::render('Stock/Index', [
            'products' => $products,
            'branches' => $branches,
            'filters' => $request->only(['search', 'category_id']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function movements(Request $request)
    {
        $movements = StockMovement::with('product:id,code,name', 'branch:id,name', 'user:id,name')
            ->when($request->branch_id, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($request->product_id, fn ($q, $id) => $q->where('product_id', $id))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->from, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('created_at')->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('Stock/Movements', [
            'movements' => $movements,
            'filters' => $request->only(['branch_id', 'product_id', 'type', 'from', 'to']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'types' => StockService::TYPES,
            'product' => $request->product_id ? Product::find($request->product_id, ['id', 'code', 'name']) : null,
        ]);
    }

    public function lowStock(Request $request)
    {
        $branchId = $this->ctx->branchId();
        $rows = Product::query()->where('products.is_active', true)->where('track_stock', true)
            ->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branchId))
            ->whereRaw('COALESCE(ps.min_stock, products.min_stock) > 0')
            ->whereRaw('COALESCE(ps.quantity,0) <= COALESCE(ps.min_stock, products.min_stock)')
            ->with('supplier:id,name', 'unit:id,abbreviation')
            ->select('products.*', DB::raw('COALESCE(ps.quantity,0) as branch_stock'), DB::raw('COALESCE(ps.min_stock, products.min_stock) as branch_min'))
            ->orderByRaw('COALESCE(ps.quantity,0) - COALESCE(ps.min_stock, products.min_stock)')
            ->paginate(50)->withQueryString();

        return Inertia::render('Stock/Low', ['products' => $rows]);
    }

    public function adjustForm()
    {
        return Inertia::render('Stock/Adjust');
    }

    public function adjust(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.mode' => ['required', Rule::in(['add', 'subtract', 'set'])],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);
        DB::transaction(function () use ($data, $stock) {
            foreach ($data['items'] as $i) {
                $product = Product::findOrFail($i['product_id']);
                $qty = (float) $i['quantity'];
                match ($i['mode']) {
                    'add' => $stock->move($product, $this->ctx->branchId(), $qty, 'adjustment', null, null, $data['reason']),
                    'subtract' => $stock->move($product, $this->ctx->branchId(), -$qty, 'adjustment', null, null, $data['reason']),
                    'set' => $stock->setQuantity($product, $this->ctx->branchId(), $qty, 'adjustment', null, $data['reason']),
                };
            }
        });

        return redirect()->route('stock.movements', ['type' => 'adjustment'])->with('success', 'Ajuste registrado.');
    }

    public function updateLevels(Request $request, Product $product)
    {
        $data = $request->validate([
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'location' => ['nullable', 'string', 'max:60'],
        ]);
        ProductStock::updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $this->ctx->branchId()],
            $data + ['tenant_id' => $product->tenant_id]
        );

        return back()->with('success', 'Niveles de stock actualizados.');
    }
}
