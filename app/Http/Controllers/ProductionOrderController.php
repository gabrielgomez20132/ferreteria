<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductionOrder;
use App\Services\ProductionService;
use App\Services\ProductStructureService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionService $service, private TenantContext $ctx) {}

    public function index(Request $request)
    {
        $pending = ProductionOrder::where('status', 'pending')->where('branch_id', $this->ctx->branchId())->get();

        return Inertia::render('Production/Index', [
            'orders' => ProductionOrder::with('product:id,code,name', 'user:id,name', 'branch:id,name')
                ->when($request->status, fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(25)->withQueryString(),
            'requirements' => $this->service->requirements($pending, $this->ctx->branchId()),
            'pendingCount' => $pending->count(),
            'products' => Product::where('type', 'manufactured')->where('is_active', true)->where('has_variants', false)->orderBy('name')
                ->withCount('components')->get(['id', 'code', 'name']),
            'filters' => $request->only('status'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $order = $this->service->create(Product::findOrFail($data['product_id']), (float) $data['quantity'], $data['due_date'] ?? null, $data['notes'] ?? null);

        return redirect()->route('stock.production.show', $order)->with('success', "Orden de producción #{$order->number} creada.");
    }

    public function show(ProductionOrder $order, ProductStructureService $structure)
    {
        $order->load('product.components.component.unit', 'product.unit', 'user:id,name', 'branch:id,name');

        return Inertia::render('Production/Show', [
            'order' => $order,
            'requirements' => $this->service->requirements(collect([$order]), $order->branch_id),
            'unitCost' => $structure->componentsCost($order->product),
        ]);
    }

    public function complete(ProductionOrder $order)
    {
        $this->service->complete($order);

        return back()->with('success', 'Producción completada: se consumieron los insumos y se ingresó el producto terminado al stock.');
    }

    public function cancel(ProductionOrder $order)
    {
        $this->service->cancel($order);

        return back()->with('success', 'Orden cancelada.');
    }

    /** Exporta a Excel la lista de insumos necesarios para las órdenes pendientes */
    public function exportRequirements()
    {
        $pending = ProductionOrder::with('product:id,code,name')->where('status', 'pending')->where('branch_id', $this->ctx->branchId())->get();
        $rows = $this->service->requirements($pending, $this->ctx->branchId());

        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Insumos');
        $ws->fromArray(['Insumos necesarios para '.$pending->count().' órdenes de producción pendientes - '.$this->ctx->branch->name.' - '.now()->format('d/m/Y')], null, 'A1');
        $ws->fromArray(['Código', 'Insumo', 'Unidad', 'Necesario', 'Stock', 'Faltante', 'Costo unit.', 'Costo faltante'], null, 'A3');
        $r = 4;
        foreach ($rows as $x) {
            $ws->fromArray([$x['code'], $x['name'], $x['unit'], $x['required'], $x['stock'], $x['missing'], $x['cost'], round($x['missing'] * $x['cost'], 2)], null, 'A'.$r++);
        }
        $r++;
        $ws->fromArray(['Órdenes incluidas'], null, 'A'.$r++);
        foreach ($pending as $o) {
            $ws->fromArray(["#{$o->number}", $o->product->name, null, (float) $o->quantity], null, 'A'.$r++);
        }
        $ws->getStyle('A1')->getFont()->setBold(true);
        $ws->getStyle('A3:H3')->getFont()->setBold(true);
        foreach (range('A', 'H') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $path = storage_path('app/private/insumos-'.now()->format('YmdHis').'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new Xlsx($sheet))->save($path);

        return response()->download($path, 'insumos-produccion.xlsx')->deleteFileAfterSend();
    }
}
