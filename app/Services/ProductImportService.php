<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Unit;
use App\Support\SpreadsheetReader;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Carga inicial / masiva de productos desde Excel y exportación del catálogo.
 */
class ProductImportService
{
    public const COLUMNS = [
        'codigo' => 'Código interno (obligatorio)',
        'codigo_barras' => 'Código de barras',
        'descripcion' => 'Descripción (obligatorio)',
        'categoria' => 'Categoría (se crea si no existe)',
        'marca' => 'Marca (se crea si no existe)',
        'unidad' => 'Unidad: un, m, kg, l...',
        'proveedor' => 'Proveedor habitual (se crea si no existe)',
        'codigo_proveedor' => 'Código del artículo en el proveedor',
        'costo' => 'Costo neto sin IVA',
        'iva' => 'Alícuota IVA (21, 10.5, 27, 0)',
        'margen' => 'Margen % sobre costo',
        'precio' => 'Precio final (si se omite se calcula)',
        'stock' => 'Stock inicial en la sucursal actual',
        'stock_minimo' => 'Stock mínimo',
        'ubicacion' => 'Ubicación (estante, pasillo)',
        'fraccionable' => 'Se vende fraccionado (si/no)',
    ];

    public function __construct(private PricingService $pricing, private StockService $stock, private TenantContext $context) {}

    public function template(): string
    {
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Productos');
        $ws->fromArray(array_keys(self::COLUMNS), null, 'A1');
        $ws->fromArray(['TOR-6X40', '7791234567890', 'Tornillo autoperforante 6x40 mm', 'Bulonería', 'Fischer', 'un', 'Distribuidora Norte', 'F-6040', 12.5, 21, 60, '', 500, 100, 'Pasillo 3', 'no'], null, 'A2');
        $ws->fromArray(['CAB-2.5', '', 'Cable unipolar 2,5 mm rollo x100 m', 'Electricidad', 'Pirelli', 'm', 'Electro Mayorista', 'UNI25', 450, 21, 45, '', 300, 100, 'Depósito', 'si'], null, 'A3');
        foreach (range('A', 'P') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $ws->getStyle('A1:P1')->getFont()->setBold(true);
        $path = storage_path('app/private/plantilla-productos.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new Xlsx($sheet))->save($path);

        return $path;
    }

    /** @return array{created:int, updated:int, errors:array} */
    public function import(string $path, bool $updateExisting = true): array
    {
        $rows = SpreadsheetReader::rows($path);
        $header = array_shift($rows) ?? [];
        $map = [];
        foreach ($header as $col => $name) {
            $key = Str::snake(Str::ascii(mb_strtolower((string) $name)));
            $key = str_replace(['codigo_de_barras', 'precio_final', 'stock_min'], ['codigo_barras', 'precio', 'stock_minimo'], $key);
            if (array_key_exists($key, self::COLUMNS)) {
                $map[$key] = $col;
            }
        }
        if (! isset($map['codigo'], $map['descripcion'])) {
            return ['created' => 0, 'updated' => 0, 'errors' => ['La planilla debe tener las columnas "codigo" y "descripcion" en la primera fila.']];
        }

        $categories = Category::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all();
        $brands = Brand::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all();
        $suppliers = Supplier::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id])->all();
        $units = Unit::all()->flatMap(fn ($u) => [mb_strtolower($u->abbreviation) => $u->id, mb_strtolower($u->name) => $u->id])->all();
        $defaultMarkup = (float) $this->context->setting('default_markup', 40);
        $defaultVat = (float) $this->context->setting('default_vat', 21);
        $branchId = $this->context->branchId();

        $created = $updated = 0;
        $errors = [];

        foreach ($rows as $n => $cells) {
            $get = fn ($k) => isset($map[$k]) ? ($cells[$map[$k]] ?? null) : null;
            $code = SpreadsheetReader::text($get('codigo'));
            $name = SpreadsheetReader::text($get('descripcion'));
            if (! $code || ! $name) {
                $errors[] = "Fila {$n}: falta código o descripción.";

                continue;
            }

            try {
                DB::transaction(function () use ($get, $code, $name, &$categories, &$brands, &$suppliers, $units, $defaultMarkup, $defaultVat, $branchId, $updateExisting, &$created, &$updated) {
                    $product = Product::where('code', $code)->first();
                    if ($product && ! $updateExisting) {
                        return;
                    }
                    $lookup = function (&$cache, string $class, $value) {
                        $value = SpreadsheetReader::text($value);
                        if (! $value) {
                            return null;
                        }
                        $k = mb_strtolower($value);

                        return $cache[$k] ??= $class::create(['name' => $value])->id;
                    };

                    $cost = PricingService::parseNumber($get('costo')) ?? (float) ($product->cost ?? 0);
                    $vat = PricingService::parseNumber($get('iva')) ?? (float) ($product->vat_rate ?? $defaultVat);
                    $markup = PricingService::parseNumber($get('margen'));
                    $price = PricingService::parseNumber($get('precio'));
                    if ($price && $markup === null) {
                        $markup = $this->pricing->markupFromPrice($cost, $price, $vat);
                    }
                    $markup ??= (float) ($product->markup ?? $defaultMarkup);
                    $price ??= $this->pricing->priceFromCost($cost, $markup, $vat);
                    $supplierId = $lookup($suppliers, Supplier::class, $get('proveedor'));
                    $fraction = mb_strtolower((string) $get('fraccionable'));

                    $attrs = array_filter([
                        'barcode' => SpreadsheetReader::text($get('codigo_barras')),
                        'name' => $name,
                        'category_id' => $lookup($categories, Category::class, $get('categoria')),
                        'brand_id' => $lookup($brands, Brand::class, $get('marca')),
                        'unit_id' => $units[mb_strtolower((string) $get('unidad'))] ?? null,
                        'supplier_id' => $supplierId,
                        'min_stock' => PricingService::parseNumber($get('stock_minimo')),
                        'location' => SpreadsheetReader::text($get('ubicacion')),
                    ], fn ($v) => $v !== null);
                    $attrs += [
                        'cost' => $cost, 'vat_rate' => $vat, 'markup' => $markup, 'price' => $price,
                        'cost_updated_at' => now(), 'price_updated_at' => now(),
                    ];
                    if ($fraction !== '') {
                        $attrs['allow_fraction'] = in_array($fraction, ['si', 'sí', 's', '1', 'true', 'x'], true);
                    }

                    if ($product) {
                        $product->update($attrs);
                        $updated++;
                    } else {
                        $product = Product::create($attrs + ['code' => $code, 'average_cost' => $cost]);
                        $created++;
                    }

                    if ($supplierId) {
                        SupplierProduct::updateOrCreate(
                            ['supplier_id' => $supplierId, 'product_id' => $product->id],
                            ['tenant_id' => $product->tenant_id, 'supplier_code' => SpreadsheetReader::text($get('codigo_proveedor')), 'cost' => $cost, 'list_price' => $cost, 'last_price_at' => now()]
                        );
                    }
                    $stock = PricingService::parseNumber($get('stock'));
                    if ($stock !== null && $branchId) {
                        $this->stock->setQuantity($product, $branchId, $stock, 'initial', null, 'Importación Excel');
                    }
                });
            } catch (\Throwable $e) {
                $errors[] = "Fila {$n}: ".Str::limit($e->getMessage(), 150);
            }
        }

        return compact('created', 'updated', 'errors');
    }

    public function export($products): string
    {
        $sheet = new Spreadsheet;
        $ws = $sheet->getActiveSheet();
        $ws->fromArray(['codigo', 'codigo_barras', 'descripcion', 'categoria', 'marca', 'unidad', 'proveedor', 'costo', 'iva', 'margen', 'precio', 'stock', 'stock_minimo', 'ubicacion'], null, 'A1');
        $r = 2;
        foreach ($products as $p) {
            $ws->fromArray([
                $p->code, $p->barcode, $p->name, $p->category?->name, $p->brand?->name, $p->unit?->abbreviation, $p->supplier?->name,
                (float) $p->cost, (float) $p->vat_rate, (float) $p->markup, (float) $p->price, (float) $p->stocks->sum('quantity'), (float) $p->min_stock, $p->location,
            ], null, 'A'.$r++);
        }
        $ws->getStyle('A1:N1')->getFont()->setBold(true);
        $path = storage_path('app/private/export-productos-'.now()->format('YmdHis').'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new Xlsx($sheet))->save($path);

        return $path;
    }
}
