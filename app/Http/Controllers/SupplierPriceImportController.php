<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPriceImport;
use App\Models\SupplierPriceImportRow;
use App\Models\Unit;
use App\Services\PricingService;
use App\Services\SupplierPriceImportService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Actualización de precios a partir de listas de proveedores (Excel / CSV).
 */
class SupplierPriceImportController extends Controller
{
    public function __construct(private SupplierPriceImportService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Prices/Imports/Index', [
            'imports' => SupplierPriceImport::with('supplier:id,name', 'user:id,name')
                ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
                ->latest()->paginate(20)->withQueryString(),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')
                ->withMax('priceImports as last_import_at', 'applied_at')
                ->get(['id', 'name', 'discount_1', 'discount_2', 'discount_3', 'prices_include_vat', 'import_mapping']),
            'filters' => $request->only('supplier_id'),
        ]);
    }

    public function store(Request $request, TenantContext $ctx)
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('tenant_id', $ctx->id())],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt,ods', 'max:30720'],
        ]);
        $import = $this->service->upload(Supplier::findOrFail($data['supplier_id']), $request->file('file'));

        return redirect()->route('price-imports.show', $import);
    }

    /** Paso 2: mapeo de columnas y opciones de cálculo */
    public function show(SupplierPriceImport $import)
    {
        if ($import->status === 'applied') {
            return redirect()->route('price-imports.review', $import);
        }
        $import->load('supplier');

        return Inertia::render('Prices/Imports/Mapping', [
            'import' => $import,
            'preview' => $this->service->preview($import),
            'priceActions' => PricingService::PRICE_ACTIONS,
        ]);
    }

    /** Paso 3: procesar todo el archivo */
    public function process(Request $request, SupplierPriceImport $import)
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.code' => ['nullable', 'string', 'max:3'],
            'mapping.barcode' => ['nullable', 'string', 'max:3'],
            'mapping.description' => ['nullable', 'string', 'max:3'],
            'mapping.price' => ['required', 'string', 'max:3'],
            'mapping.brand' => ['nullable', 'string', 'max:3'],
            'mapping.pack' => ['nullable', 'string', 'max:3'],
            'options' => ['required', 'array'],
            'options.header_row' => ['required', 'integer', 'min:0'],
            'options.prices_include_vat' => ['boolean'],
            'options.default_vat' => ['numeric'],
            'options.use_supplier_discounts' => ['boolean'],
            'options.extra_discount_1' => ['numeric', 'min:-100', 'max:100'],
            'options.extra_discount_2' => ['numeric', 'min:-100', 'max:100'],
            'options.surcharge_percent' => ['numeric', 'min:-100', 'max:1000'],
            'options.exchange_rate' => ['numeric', 'gt:0'],
            'options.match_by_barcode' => ['boolean'],
            'options.match_by_internal_code' => ['boolean'],
            'options.price_action' => ['required', Rule::in(array_keys(PricingService::PRICE_ACTIONS))],
            'options.custom_markup' => ['nullable', 'numeric'],
            'options.cost_policy' => ['required', Rule::in(['always', 'main_supplier', 'best_cost'])],
            'options.alert_threshold' => ['numeric', 'min:0'],
        ]);
        @set_time_limit(600);
        $mapping = array_filter($data['mapping']);
        $this->service->process($import, $mapping, $data['options']);

        return redirect()->route('price-imports.review', $import)->with('success', 'Lista procesada. Revisá las variaciones antes de aplicar.');
    }

    /** Paso 4: revisión de variaciones */
    public function review(Request $request, SupplierPriceImport $import)
    {
        $import->load('supplier:id,name', 'user:id,name', 'priceUpdate:id,status');
        $filters = $request->only(['filter', 'search', 'sort']);
        $rows = $this->service->rowsQuery($import, $filters)->paginate(50)->withQueryString();

        return Inertia::render('Prices/Imports/Review', [
            'import' => $import,
            'rows' => $rows,
            'filters' => $filters,
            'distribution' => $this->service->summaryByVariation($import),
            'selectedCount' => $import->rows()->where('status', 'matched')->where('selected', true)->count(),
            'priceActions' => PricingService::PRICE_ACTIONS,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Seleccionar / deseleccionar filas (individual, por filtro o todas) */
    public function select(Request $request, SupplierPriceImport $import)
    {
        $data = $request->validate([
            'selected' => ['required', 'boolean'],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'filter' => ['nullable', 'string'],
        ]);
        abort_if($import->status !== 'processed', 422);
        $q = $import->rows()->where('status', 'matched');
        if (! empty($data['ids'])) {
            $q->whereIn('id', $data['ids']);
        } elseif (! empty($data['filter'])) {
            $q = $this->service->rowsQuery($import, ['filter' => $data['filter']])->where('status', 'matched');
        }
        $q->update(['selected' => $data['selected']]);

        return back();
    }

    public function link(Request $request, SupplierPriceImport $import, SupplierPriceImportRow $row)
    {
        abort_unless($row->supplier_price_import_id === $import->id && $import->status === 'processed', 404);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'pack_quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $product = Product::findOrFail($data['product_id']);
        $this->service->linkRow($row, $product, (float) ($data['pack_quantity'] ?? 1));

        return back()->with('success', "Vinculado con {$product->name}. La próxima lista de este proveedor lo reconocerá automáticamente.");
    }

    public function unlink(SupplierPriceImport $import, SupplierPriceImportRow $row)
    {
        abort_unless($row->supplier_price_import_id === $import->id && $import->status === 'processed', 404);
        $this->service->unlinkRow($row);

        return back();
    }

    public function createProducts(Request $request, SupplierPriceImport $import, TenantContext $ctx)
    {
        $data = $request->validate([
            'row_ids' => ['nullable', 'array'],
            'all_unmatched' => ['boolean'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('tenant_id', $ctx->id())],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where('tenant_id', $ctx->id())],
            'unit_id' => ['nullable', Rule::exists('units', 'id')->where('tenant_id', $ctx->id())],
            'markup' => ['required', 'numeric'],
            'vat_rate' => ['required', 'numeric'],
            'code_strategy' => ['required', Rule::in(['supplier_code', 'sequence'])],
            'code_prefix' => ['nullable', 'string', 'max:10'],
        ]);
        abort_if($import->status !== 'processed', 422);
        $ids = ! empty($data['all_unmatched']) ? $import->rows()->where('status', 'unmatched')->pluck('id')->all() : ($data['row_ids'] ?? []);
        @set_time_limit(600);
        $n = $this->service->createProducts($import, $ids, $data);

        return back()->with('success', "Se crearon {$n} productos nuevos.");
    }

    /** Paso 5: aplicar */
    public function apply(Request $request, SupplierPriceImport $import)
    {
        $data = $request->validate([
            'price_action' => ['required', Rule::in(array_keys(PricingService::PRICE_ACTIONS))],
            'custom_markup' => ['nullable', 'numeric'],
            'cost_policy' => ['required', Rule::in(['always', 'main_supplier', 'best_cost'])],
        ]);
        @set_time_limit(600);
        $r = $this->service->apply($import, $data);

        return redirect()->route('price-imports.review', $import)->with('success',
            "Lista aplicada: {$r['supplier_products']} costos de proveedor actualizados, {$r['products']} productos con cambio de costo/precio.");
    }

    public function cancel(SupplierPriceImport $import)
    {
        $this->service->cancel($import);

        return redirect()->route('price-imports.index')->with('success', 'Importación cancelada.');
    }

    public function download(SupplierPriceImport $import)
    {
        return Storage::disk('local')->download($import->file_path, $import->file_name);
    }

    /** Exporta la revisión a Excel (para compartir con el encargado o el proveedor) */
    public function export(SupplierPriceImport $import)
    {
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->fromArray(['Fila', 'Cód. proveedor', 'Descripción proveedor', 'Producto', 'Precio lista', 'Costo anterior', 'Costo nuevo', 'Var. %', 'Precio actual', 'Precio sugerido', 'Estado', 'Alerta'], null, 'A1');
        $r = 2;
        $import->rows()->with('product:id,code,name')->orderBy('row_number')->chunk(1000, function ($rows) use ($ws, &$r) {
            foreach ($rows as $row) {
                $ws->fromArray([
                    $row->row_number, $row->supplier_code, $row->description, $row->product ? "{$row->product->code} - {$row->product->name}" : '',
                    (float) $row->list_price, (float) $row->current_cost, (float) $row->new_cost, $row->variation_percent !== null ? (float) $row->variation_percent : null,
                    (float) $row->current_price, (float) $row->suggested_price, $row->status, $row->is_alert ? 'SI' : '',
                ], null, 'A'.$r++);
            }
        });
        $ws->getStyle('A1:L1')->getFont()->setBold(true);
        $path = storage_path("app/private/revision-lista-{$import->id}.xlsx");
        (new Xlsx($sheet))->save($path);

        return response()->download($path, "revision-lista-{$import->id}.xlsx")->deleteFileAfterSend();
    }
}
