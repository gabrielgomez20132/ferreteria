<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCode;
use App\Models\Supplier;
use App\Models\SupplierPriceImport;
use App\Models\SupplierPriceImportRow;
use App\Models\SupplierProduct;
use App\Support\SpreadsheetReader;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\BusinessException;

/**
 * Flujo de actualización de precios desde listas de proveedores:
 *
 *   1. upload()   → guarda el archivo y crea la importación
 *   2. preview()  → primeras filas + mapeo sugerido de columnas
 *   3. process()  → lee todo, calcula costos netos, vincula con productos, detecta variaciones y alertas
 *   4. linkRow() / createProducts() → resolver filas no vinculadas (el sistema "aprende" el código del proveedor)
 *   5. apply()    → actualiza costos del proveedor, costos de productos y precios de venta en un lote reversible
 */
class SupplierPriceImportService
{
    public const DEFAULT_OPTIONS = [
        'header_row' => 1,
        'prices_include_vat' => false,
        'default_vat' => 21,
        'use_supplier_discounts' => true,
        'extra_discount_1' => 0,
        'extra_discount_2' => 0,
        'surcharge_percent' => 0,
        'exchange_rate' => 1,
        'match_by_barcode' => true,
        'match_by_internal_code' => false,
        'price_action' => 'keep_markup',
        'custom_markup' => null,
        'cost_policy' => 'always',
        'alert_threshold' => 15,
    ];

    public function __construct(
        private PricingService $pricing,
        private PriceUpdateService $updates,
        private TenantContext $context,
        private SupplierDiscounts $discounts,
    ) {}

    public function upload(Supplier $supplier, UploadedFile $file): SupplierPriceImport
    {
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $path = $file->storeAs('price-imports/'.$supplier->tenant_id, $name, 'local');

        $options = array_merge(self::DEFAULT_OPTIONS, [
            'prices_include_vat' => (bool) $supplier->prices_include_vat,
            'price_action' => $this->context->setting('default_price_action', 'keep_markup'),
            'cost_policy' => $this->context->setting('cost_update_policy', 'always'),
            'alert_threshold' => (float) $this->context->setting('price_alert_threshold', 15),
            'default_vat' => (float) $this->context->setting('default_vat', 21),
        ], $supplier->import_mapping['options'] ?? []);

        return SupplierPriceImport::create([
            'supplier_id' => $supplier->id,
            'user_id' => auth()->id(),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'uploaded',
            'mapping' => $supplier->import_mapping['columns'] ?? null,
            'options' => $options,
        ]);
    }

    public function absolutePath(SupplierPriceImport $import): string
    {
        return Storage::disk('local')->path($import->file_path);
    }

    /** @return array{rows: array, columns: array, suggested: array} */
    public function preview(SupplierPriceImport $import, int $limit = 20): array
    {
        $rows = SpreadsheetReader::rows($this->absolutePath($import), $limit);
        $columns = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $c) {
                $columns[$c] = true;
            }
        }
        $columns = array_keys($columns);
        usort($columns, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));

        // Si es la primera lista del proveedor, se detecta la fila de encabezado automáticamente
        $headerRow = $import->mapping ? (int) ($import->options['header_row'] ?? 1) : SpreadsheetReader::detectHeaderRow($rows);
        $suggested = $import->mapping ?: SpreadsheetReader::guessMapping($rows[$headerRow] ?? reset($rows) ?: []);

        return [
            'rows' => collect($rows)->map(fn ($r, $n) => ['number' => $n, 'cells' => $r])->values()->all(),
            'columns' => $columns,
            'suggested' => $suggested,
            'header_row' => $headerRow,
        ];
    }

    /**
     * Procesa el archivo completo con el mapeo y opciones indicados.
     */
    public function process(SupplierPriceImport $import, array $mapping, array $options): SupplierPriceImport
    {
        if ($import->status === 'applied') {
            throw new BusinessException('La importación ya fue aplicada.');
        }
        if (empty($mapping['price']) || (empty($mapping['code']) && empty($mapping['barcode']))) {
            throw new BusinessException('Debe indicar al menos la columna de precio y la de código (o código de barras).');
        }

        $options = array_merge(self::DEFAULT_OPTIONS, $import->options ?? [], $options);
        $supplier = $import->supplier;
        $rows = SpreadsheetReader::rows($this->absolutePath($import));
        $headerRow = (int) $options['header_row'];

        // ---- Índices para vincular rápido (una sola consulta por tipo) ----
        $supplierProducts = SupplierProduct::where('supplier_id', $supplier->id)
            ->whereNotNull('supplier_code')->get()
            ->keyBy(fn ($sp) => $this->key($sp->supplier_code));
        $products = Product::query()->select('id', 'code', 'barcode', 'cost', 'price', 'markup', 'vat_rate', 'supplier_id', 'tenant_id', 'brand_id', 'category_id')->get()->keyBy('id');
        $barcodes = [];
        $internalCodes = [];
        foreach ($products as $p) {
            if ($p->barcode) {
                $barcodes[$this->key($p->barcode)] = $p->id;
            }
            $internalCodes[$this->key($p->code)] = $p->id;
        }
        foreach (ProductCode::query()->get(['product_id', 'code']) as $pc) {
            $barcodes[$this->key($pc->code)] ??= $pc->product_id;
        }
        $spByProduct = SupplierProduct::where('supplier_id', $supplier->id)->get()->keyBy('product_id');

        $extraDiscounts = [(float) $options['extra_discount_1'], (float) $options['extra_discount_2']];
        $threshold = (float) $options['alert_threshold'];
        $out = [];
        $seen = [];
        $seenProducts = [];

        foreach ($rows as $number => $cells) {
            if ($number <= $headerRow) {
                continue;
            }
            $code = SpreadsheetReader::text($this->cell($cells, $mapping['code'] ?? null));
            $barcode = SpreadsheetReader::text($this->cell($cells, $mapping['barcode'] ?? null));
            $rawPrice = $this->cell($cells, $mapping['price']);
            $price = PricingService::parseNumber($rawPrice);
            $description = SpreadsheetReader::text($this->cell($cells, $mapping['description'] ?? null));
            $brand = SpreadsheetReader::text($this->cell($cells, $mapping['brand'] ?? null));
            $packFromFile = PricingService::parseNumber($this->cell($cells, $mapping['pack'] ?? null));

            // Filas de título / separadores de rubro: sin código y sin precio
            if (! $code && ! $barcode) {
                continue;
            }
            if ($price === null && ! $description) {
                continue;
            }

            $row = [
                'supplier_price_import_id' => $import->id,
                'row_number' => $number,
                'supplier_code' => $code ? Str::limit($code, 60, '') : null,
                'barcode' => $barcode ? Str::limit($barcode, 60, '') : null,
                'description' => $description ? Str::limit($description, 250, '') : null,
                'brand' => $brand ? Str::limit($brand, 100, '') : null,
                'list_price' => $price,
                'new_cost' => null, 'current_cost' => null, 'variation_percent' => null,
                'current_price' => null, 'suggested_price' => null,
                'product_id' => null, 'supplier_product_id' => null, 'match_type' => null,
                'status' => 'unmatched', 'is_alert' => false, 'selected' => true, 'error' => null,
            ];

            if ($price === null || $price <= 0) {
                $row['status'] = 'error';
                $row['error'] = 'Precio inválido: '.Str::limit((string) $rawPrice, 30);
                $row['selected'] = false;
                $out[] = $row;

                continue;
            }

            if ($code && isset($seen[$this->key($code)])) {
                $row['status'] = 'error';
                $row['error'] = 'Código duplicado en la lista (fila '.$seen[$this->key($code)].')';
                $row['selected'] = false;
                $out[] = $row;

                continue;
            }
            if ($code) {
                $seen[$this->key($code)] = $number;
            }

            // ---- Vinculación ----
            $productId = null;
            $sp = null;
            if ($code && ($sp = $supplierProducts->get($this->key($code)))) {
                $productId = $sp->product_id;
                $row['match_type'] = 'supplier_code';
            } elseif ($options['match_by_barcode'] && ($id = $barcodes[$this->key($barcode ?? $code)] ?? null)) {
                // Si no hay columna de código de barras, se prueba el código del proveedor como EAN
                $productId = $id;
                $row['match_type'] = 'barcode';
            } elseif ($options['match_by_internal_code'] && $code && ($id = $internalCodes[$this->key($code)] ?? null)) {
                $productId = $id;
                $row['match_type'] = 'internal_code';
            }

            $product = $productId ? $products->get($productId) : null;
            if ($product && isset($seenProducts[$product->id])) {
                $row['status'] = 'error';
                $row['error'] = 'El mismo producto ya figura en la fila '.$seenProducts[$product->id];
                $row['selected'] = false;
                $row['match_type'] = null;
                $out[] = $row;

                continue;
            }
            if ($product) {
                $seenProducts[$product->id] = $number;
            }
            if (! $product) {
                // sin vincular: calculamos el costo igualmente para poder crear el producto
                $row['new_cost'] = $this->pricing->supplierCost(
                    $price, $this->discountsFor(null, $supplier, $options, $extraDiscounts), $packFromFile ?: 1,
                    (bool) $options['prices_include_vat'], (float) $options['default_vat'],
                    (float) $options['surcharge_percent'], (float) $options['exchange_rate'],
                );
                $out[] = $row;

                continue;
            }

            $sp ??= $spByProduct->get($product->id);
            $pack = $packFromFile ?: (float) ($sp?->pack_quantity ?: 1);
            $newCost = $this->pricing->supplierCost(
                $price, $this->discountsFor($sp, $supplier, $options, $extraDiscounts, $product), $pack,
                (bool) $options['prices_include_vat'], (float) $product->vat_rate,
                (float) $options['surcharge_percent'], (float) $options['exchange_rate'],
            );
            $this->fillMatched($row, $product, $sp, $newCost, $options, $threshold);
            $out[] = $row;
        }

        DB::transaction(function () use ($import, $out, $mapping, $options, $supplier) {
            $import->rows()->delete();
            foreach (array_chunk($out, 500) as $chunk) {
                SupplierPriceImportRow::insert($chunk);
            }
            $import->update(['mapping' => $mapping, 'options' => $options, 'status' => 'processed']);
            $this->refreshStats($import);

            // Recordar la configuración para la próxima lista de este proveedor
            $keep = array_intersect_key($options, array_flip([
                'header_row', 'prices_include_vat', 'use_supplier_discounts', 'extra_discount_1', 'extra_discount_2',
                'surcharge_percent', 'match_by_barcode', 'match_by_internal_code', 'price_action', 'cost_policy',
            ]));
            $supplier->update(['import_mapping' => ['columns' => $mapping, 'options' => $keep]]);
        });

        return $import->fresh();
    }

    private function fillMatched(array &$row, Product $product, ?SupplierProduct $sp, float $newCost, array $options, float $threshold): void
    {
        $reference = $sp && (float) $sp->cost > 0 ? (float) $sp->cost : (float) $product->cost;
        $variation = $reference > 0 ? round(($newCost - $reference) / $reference * 100, 2) : null;
        $resolved = $this->pricing->resolvePrice($product, $newCost, $options['price_action'], $options['custom_markup'] !== null ? (float) $options['custom_markup'] : null);

        $row['product_id'] = $product->id;
        $row['supplier_product_id'] = $sp?->id;
        $row['new_cost'] = $newCost;
        $row['current_cost'] = $reference;
        $row['variation_percent'] = $variation;
        $row['current_price'] = (float) $product->price;
        $row['suggested_price'] = $resolved['price'];
        $row['status'] = ($variation !== null && abs($variation) < 0.01) ? 'unchanged' : 'matched';
        $row['is_alert'] = $variation === null || abs($variation) >= $threshold;
        // Por defecto no se seleccionan las alertas grandes (posible error de carga del proveedor)
        $row['selected'] = $row['status'] === 'matched' && ($variation === null || abs($variation) < max($threshold * 3, 50));
    }

    private function discountsFor(?SupplierProduct $sp, Supplier $supplier, array $options, array $extra, ?Product $product = null): array
    {
        $base = $options['use_supplier_discounts']
            ? $this->discounts->forProduct($supplier, $sp, $product?->brand_id, $product?->category_id)
            : [];

        return array_merge(array_map('floatval', $base), $extra);
    }

    public function refreshStats(SupplierPriceImport $import): void
    {
        $s = $import->rows()->selectRaw("
            COUNT(*) total,
            SUM(status IN ('matched','unchanged')) matched,
            SUM(status = 'unmatched') unmatched,
            SUM(status = 'matched' AND variation_percent > 0) increased,
            SUM(status = 'matched' AND variation_percent < 0) decreased,
            SUM(status = 'unchanged') unchanged,
            SUM(status = 'matched' AND is_alert = 1) alerts,
            AVG(CASE WHEN status = 'matched' THEN variation_percent END) avg_var
        ")->first();

        $import->update([
            'total_rows' => (int) $s->total,
            'matched_rows' => (int) $s->matched,
            'unmatched_rows' => (int) $s->unmatched,
            'increased_rows' => (int) $s->increased,
            'decreased_rows' => (int) $s->decreased,
            'unchanged_rows' => (int) $s->unchanged,
            'alert_rows' => (int) $s->alerts,
            'avg_variation' => $s->avg_var !== null ? round((float) $s->avg_var, 2) : null,
        ]);
    }

    /**
     * Vincula manualmente una fila a un producto. Se guarda el código del proveedor para que
     * en la próxima lista se vincule automáticamente.
     */
    public function linkRow(SupplierPriceImportRow $row, Product $product, float $packQuantity = 1): SupplierPriceImportRow
    {
        $import = $row->import;
        $supplier = $import->supplier;
        $options = array_merge(self::DEFAULT_OPTIONS, $import->options ?? []);

        $other = $import->rows()->where('product_id', $product->id)->where('id', '!=', $row->id)->value('row_number');
        if ($other) {
            throw new BusinessException("\"{$product->name}\" ya está vinculado a la fila {$other} de esta lista.");
        }

        return DB::transaction(function () use ($row, $product, $packQuantity, $import, $supplier, $options) {
            $sp = SupplierProduct::firstOrNew(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
            $sp->fill([
                'tenant_id' => $product->tenant_id,
                'supplier_code' => $row->supplier_code ?? $sp->supplier_code,
                'supplier_description' => $row->description ?? $sp->supplier_description,
                'pack_quantity' => $packQuantity > 0 ? $packQuantity : 1,
            ]);
            $sp->save();

            $newCost = $this->pricing->supplierCost(
                (float) $row->list_price,
                $this->discountsFor($sp, $supplier, $options, [(float) $options['extra_discount_1'], (float) $options['extra_discount_2']], $product),
                (float) $sp->pack_quantity, (bool) $options['prices_include_vat'], (float) $product->vat_rate,
                (float) $options['surcharge_percent'], (float) $options['exchange_rate'],
            );

            $data = $row->only(['row_number']);
            $this->fillMatched($data, $product, $sp, $newCost, $options, (float) $options['alert_threshold']);
            $data['match_type'] = 'manual';
            unset($data['row_number']);
            $row->update($data);
            $this->refreshStats($import);

            return $row->fresh(['product']);
        });
    }

    public function unlinkRow(SupplierPriceImportRow $row): void
    {
        $row->update([
            'product_id' => null, 'supplier_product_id' => null, 'match_type' => null, 'status' => 'unmatched',
            'current_cost' => null, 'variation_percent' => null, 'current_price' => null, 'suggested_price' => null,
            'is_alert' => false, 'selected' => true,
        ]);
        $this->refreshStats($row->import);
    }

    /**
     * Da de alta productos nuevos a partir de filas no vinculadas.
     */
    public function createProducts(SupplierPriceImport $import, array $rowIds, array $data): int
    {
        $supplier = $import->supplier;
        $markup = (float) ($data['markup'] ?? $supplier->default_markup ?? $this->context->setting('default_markup', 40));
        $vat = (float) ($data['vat_rate'] ?? $this->context->setting('default_vat', 21));

        return DB::transaction(function () use ($import, $rowIds, $data, $supplier, $markup, $vat) {
            $rows = $import->rows()->whereIn('id', $rowIds)->where('status', 'unmatched')->get();
            $count = 0;
            foreach ($rows as $row) {
                $code = $this->uniqueCode($data['code_strategy'] === 'supplier_code' && $row->supplier_code
                    ? ($data['code_prefix'] ?? '').$row->supplier_code
                    : null);
                $cost = (float) $row->new_cost;
                $product = Product::create([
                    'code' => $code,
                    'barcode' => $row->barcode,
                    'name' => $row->description ?: ('Artículo '.$row->supplier_code),
                    'category_id' => $data['category_id'] ?? null,
                    'brand_id' => $data['brand_id'] ?? null,
                    'unit_id' => $data['unit_id'] ?? null,
                    'supplier_id' => $supplier->id,
                    'cost' => $cost,
                    'average_cost' => $cost,
                    'vat_rate' => $vat,
                    'markup' => $markup,
                    'price' => $this->pricing->priceFromCost($cost, $markup, $vat),
                    'cost_updated_at' => now(),
                    'price_updated_at' => now(),
                ]);
                $sp = SupplierProduct::create([
                    'supplier_id' => $supplier->id,
                    'product_id' => $product->id,
                    'supplier_code' => $row->supplier_code,
                    'supplier_description' => $row->description,
                    'list_price' => $row->list_price,
                    'cost' => $cost,
                    'is_preferred' => true,
                    'last_price_at' => now(),
                ]);
                $row->update([
                    'product_id' => $product->id, 'supplier_product_id' => $sp->id, 'match_type' => 'created',
                    'status' => 'created', 'current_cost' => $cost, 'variation_percent' => 0,
                    'current_price' => $product->price, 'suggested_price' => $product->price, 'selected' => false,
                ]);
                $count++;
            }
            $this->refreshStats($import);

            return $count;
        });
    }

    private function uniqueCode(?string $wanted): string
    {
        if ($wanted && ! Product::withTrashed()->where('code', $wanted)->exists()) {
            return Str::limit($wanted, 50, '');
        }
        $next = (int) Product::withTrashed()->whereRaw("code REGEXP '^[0-9]+$'")->max(DB::raw('CAST(code AS UNSIGNED)')) + 1;

        return str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Aplica la lista: actualiza costos del proveedor y, según la política, costo y precio de cada producto.
     */
    public function apply(SupplierPriceImport $import, array $options): array
    {
        if ($import->status !== 'processed') {
            throw new BusinessException('La importación debe estar procesada y no aplicada.');
        }
        $options = array_merge(self::DEFAULT_OPTIONS, $import->options ?? [], $options);
        $supplier = $import->supplier;
        $action = $options['price_action'];
        $customMarkup = $options['custom_markup'] !== null && $options['custom_markup'] !== '' ? (float) $options['custom_markup'] : null;

        return DB::transaction(function () use ($import, $options, $supplier, $action, $customMarkup) {
            $rows = $import->rows()->whereIn('status', ['matched', 'unchanged'])->where(function ($q) {
                $q->where('selected', true)->orWhere('status', 'unchanged');
            })->get();

            $products = Product::whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
            $now = now();
            $changes = collect();
            $supplierProductsUpdated = 0;

            foreach ($rows as $row) {
                $product = $products->get($row->product_id);
                if (! $product) {
                    continue;
                }
                $sp = SupplierProduct::firstOrNew(['supplier_id' => $supplier->id, 'product_id' => $product->id]);
                $sp->fill([
                    'tenant_id' => $product->tenant_id,
                    'supplier_code' => $sp->supplier_code ?: $row->supplier_code,
                    'supplier_description' => $row->description ?: $sp->supplier_description,
                    'list_price' => $row->list_price,
                    'cost' => $row->new_cost,
                    'last_price_at' => $now,
                ])->save();
                $supplierProductsUpdated++;

                if ($row->status === 'unchanged') {
                    continue;
                }

                $newCost = $this->costByPolicy($product, $supplier->id, (float) $row->new_cost, $options['cost_policy']);
                if ($newCost === null) {
                    continue; // otro proveedor define el costo de este producto
                }
                $resolved = $this->pricing->resolvePrice($product, $newCost, $action, $customMarkup);
                $changes->push(['product' => $product, 'new_cost' => $newCost, 'new_price' => $resolved['price'], 'new_markup' => $resolved['markup']]);
            }

            $update = $this->updates->applyChanges(
                'supplier_import',
                "Lista de precios {$supplier->name} ({$import->file_name})",
                $changes,
                ['price_action' => $action, 'custom_markup' => $customMarkup, 'cost_policy' => $options['cost_policy']],
                $supplier->id,
                $import->id,
            );

            $import->update([
                'status' => 'applied',
                'applied_at' => $now,
                'price_update_id' => $update?->id,
                'options' => $options,
            ]);

            return [
                'supplier_products' => $supplierProductsUpdated,
                'products' => $update?->products_count ?? 0,
                'price_update_id' => $update?->id,
            ];
        });
    }

    /** Devuelve el costo a aplicar al producto según la política, o null si no corresponde actualizarlo */
    private function costByPolicy(Product $product, int $supplierId, float $newCost, string $policy): ?float
    {
        return match ($policy) {
            'main_supplier' => (! $product->supplier_id || $product->supplier_id === $supplierId) ? $newCost : null,
            'best_cost' => (float) min($newCost, (float) (SupplierProduct::where('product_id', $product->id)
                ->where('supplier_id', '!=', $supplierId)->where('cost', '>', 0)->min('cost') ?? $newCost)),
            default => $newCost,
        };
    }

    public function cancel(SupplierPriceImport $import): void
    {
        if ($import->status === 'applied') {
            throw new BusinessException('No se puede cancelar una importación aplicada. Revierta la actualización de precios.');
        }
        $import->update(['status' => 'cancelled']);
    }

    private function cell(array $cells, ?string $col): mixed
    {
        return $col ? ($cells[$col] ?? null) : null;
    }

    private function key(?string $value): string
    {
        return mb_strtolower(ltrim(trim((string) $value), '0') ?: trim((string) $value));
    }

    /** Filas para la grilla de revisión */
    public function rowsQuery(SupplierPriceImport $import, array $filters)
    {
        $q = $import->rows()->with('product:id,code,name,price,cost,vat_rate,markup,supplier_id');
        match ($filters['filter'] ?? 'all') {
            'increase' => $q->where('status', 'matched')->where('variation_percent', '>', 0),
            'decrease' => $q->where('status', 'matched')->where('variation_percent', '<', 0),
            'alerts' => $q->where('status', 'matched')->where('is_alert', true),
            'unmatched' => $q->where('status', 'unmatched'),
            'unchanged' => $q->where('status', 'unchanged'),
            'errors' => $q->where('status', 'error'),
            'created' => $q->where('status', 'created'),
            'matched' => $q->whereIn('status', ['matched']),
            default => null,
        };
        if ($s = trim($filters['search'] ?? '')) {
            $q->where(fn ($w) => $w->where('supplier_code', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"));
        }
        $sort = $filters['sort'] ?? 'row_number';
        if ($sort === 'variation_desc') {
            $q->orderByDesc('variation_percent');
        } elseif ($sort === 'variation_asc') {
            $q->orderBy('variation_percent');
        } else {
            $q->orderBy('row_number');
        }

        return $q;
    }

    public function summaryByVariation(SupplierPriceImport $import): Collection
    {
        // Distribución de variaciones para el gráfico de la pantalla de revisión
        $ranges = [[-1000, -10, '< -10%'], [-10, -0.01, '-10% a 0%'], [-0.01, 0.01, 'Sin cambio'], [0.01, 5, '0% a 5%'], [5, 10, '5% a 10%'], [10, 20, '10% a 20%'], [20, 50, '20% a 50%'], [50, 100000, '> 50%']];
        $vals = $import->rows()->whereIn('status', ['matched', 'unchanged'])->pluck('variation_percent')->map(fn ($v) => (float) $v);

        return collect($ranges)->map(fn ($r) => ['label' => $r[2], 'count' => $vals->filter(fn ($v) => $v >= $r[0] && $v < $r[1])->count()]);
    }
}
