<?php

namespace Tests\Feature;

use App\Models\PriceUpdate;
use App\Models\Product;
use App\Models\SupplierPriceImport;
use App\Models\SupplierProduct;
use App\Services\PriceUpdateService;
use App\Services\SupplierPriceImportService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SupplierPriceImportTest extends TestCase
{
    private function excel(array $rows): UploadedFile
    {
        $s = new Spreadsheet;
        $s->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'lst').'.xlsx';
        (new Xlsx($s))->save($path);

        return new UploadedFile($path, 'lista.xlsx', null, null, true);
    }

    public function test_full_flow_upload_process_link_create_apply_and_revert(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $supplier = $this->makeSupplier(['discount_1' => 10]);
        $a = $this->makeProduct(['code' => 'A1', 'cost' => 90, 'markup' => 50, 'price' => 163.35, 'supplier_id' => $supplier->id]);
        $b = $this->makeProduct(['code' => 'B1', 'barcode' => '7790000000017', 'cost' => 200, 'markup' => 40, 'price' => 338.8]);
        $c = $this->makeProduct(['code' => 'C1', 'cost' => 50, 'markup' => 30, 'price' => 78.65]);
        $this->link($supplier, $a, 'PROV-A', 100);

        $file = $this->excel([
            ['Código', 'Descripción', 'Precio', 'EAN'],
            ['PROV-A', 'Artículo A', '120,00', null],          // vinculado por código: +20%
            ['PROV-B', 'Artículo B', 250, '7790000000017'],    // vinculado por código de barras
            ['PROV-C', 'Artículo nuevo C', 80, null],          // sin vincular
            ['PROV-X', 'Sin precio', 'consultar', null],       // error
            [null, 'RUBRO TORNILLOS', null, null],             // título: se ignora
        ]);

        $this->post(route('price-imports.store'), ['supplier_id' => $supplier->id, 'file' => $file])->assertRedirect();
        $import = SupplierPriceImport::firstOrFail();

        $this->get(route('price-imports.show', $import))->assertOk();

        $this->post(route('price-imports.process', $import), [
            'mapping' => ['code' => 'A', 'description' => 'B', 'price' => 'C', 'barcode' => 'D'],
            'options' => [
                'header_row' => 1, 'prices_include_vat' => false, 'use_supplier_discounts' => true,
                'extra_discount_1' => 0, 'extra_discount_2' => 0, 'surcharge_percent' => 0, 'exchange_rate' => 1,
                'match_by_barcode' => true, 'match_by_internal_code' => false, 'price_action' => 'keep_markup',
                'cost_policy' => 'always', 'alert_threshold' => 15, 'default_vat' => 21,
            ],
        ])->assertRedirect(route('price-imports.review', $import));

        $import->refresh();
        $this->assertSame(4, $import->total_rows);
        $this->assertSame(2, $import->matched_rows);
        $this->assertSame(1, $import->unmatched_rows);

        $rowA = $import->rows()->where('supplier_code', 'PROV-A')->first();
        $this->assertSame('supplier_code', $rowA->match_type);
        $this->assertEqualsWithDelta(108, (float) $rowA->new_cost, 0.001);       // 120 - 10%
        $this->assertEqualsWithDelta(20, (float) $rowA->variation_percent, 0.01);  // vs 90
        $this->assertTrue($rowA->is_alert);                                         // 20% > 15%

        $rowB = $import->rows()->where('supplier_code', 'PROV-B')->first();
        $this->assertSame('barcode', $rowB->match_type);
        $this->assertSame('error', $import->rows()->where('supplier_code', 'PROV-X')->value('status'));

        $this->get(route('price-imports.review', $import))->assertOk();

        // vinculación manual de la fila C → aprende el código del proveedor
        $rowC = $import->rows()->where('supplier_code', 'PROV-C')->first();
        $this->post(route('price-imports.link', [$import, $rowC]), ['product_id' => $c->id])->assertRedirect();
        $this->assertDatabaseHas('supplier_products', ['supplier_id' => $supplier->id, 'product_id' => $c->id, 'supplier_code' => 'PROV-C']);

        // aplicar con todas las filas seleccionadas
        $this->post(route('price-imports.select', $import), ['selected' => true])->assertRedirect();
        $this->post(route('price-imports.apply', $import), ['price_action' => 'keep_markup', 'cost_policy' => 'always'])->assertRedirect();

        $a->refresh();
        $this->assertEqualsWithDelta(108, (float) $a->cost, 0.001);
        $this->assertEqualsWithDelta(108 * 1.5 * 1.21, (float) $a->price, 0.01);
        $this->assertEqualsWithDelta(108, (float) SupplierProduct::where('product_id', $a->id)->value('cost'), 0.001);
        $this->assertSame('applied', $import->fresh()->status);

        $update = PriceUpdate::firstOrFail();
        $this->assertSame(3, $update->products_count);
        $this->assertDatabaseCount('product_price_histories', 3);

        // revertir
        $this->post(route('prices.updates.revert', $update))->assertRedirect();
        $this->assertEqualsWithDelta(90, (float) $a->fresh()->cost, 0.001);
        $this->assertEqualsWithDelta(163.35, (float) $a->fresh()->price, 0.001);
        $this->assertSame('reverted', $update->fresh()->status);
    }

    public function test_create_products_from_unmatched_rows(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $supplier = $this->makeSupplier();
        $import = app(SupplierPriceImportService::class)->upload($supplier, $this->excel([
            ['cod', 'desc', 'precio'], ['N-1', 'Martillo nuevo', 1000], ['N-2', 'Pinza nueva', 2000],
        ]));
        app(SupplierPriceImportService::class)->process($import, ['code' => 'A', 'description' => 'B', 'price' => 'C'], ['header_row' => 1]);

        $this->post(route('price-imports.create-products', $import), [
            'all_unmatched' => true, 'markup' => 50, 'vat_rate' => 21, 'code_strategy' => 'supplier_code', 'code_prefix' => 'X-',
        ])->assertRedirect();

        $p = Product::where('code', 'X-N-1')->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $p->cost, 0.001);
        $this->assertEqualsWithDelta(1815, (float) $p->price, 0.01);
        $this->assertSame(2, $import->rows()->where('status', 'created')->count());
    }

    public function test_keep_price_action_leaves_product_in_review_list(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $supplier = $this->makeSupplier();
        $p = $this->makeProduct(['cost' => 100, 'markup' => 50, 'price' => 181.5]);
        $this->link($supplier, $p, 'K1', 100);
        $import = app(SupplierPriceImportService::class)->upload($supplier, $this->excel([['c', 'p'], ['K1', 130]]));
        $svc = app(SupplierPriceImportService::class);
        $svc->process($import, ['code' => 'A', 'price' => 'B'], ['header_row' => 1, 'price_action' => 'keep_price']);
        $svc->apply($import->fresh(), ['price_action' => 'keep_price', 'cost_policy' => 'always']);

        $p->refresh();
        $this->assertEqualsWithDelta(130, (float) $p->cost, 0.001);
        $this->assertEqualsWithDelta(181.5, (float) $p->price, 0.001);
        $this->get(route('prices.review'))->assertOk()->assertInertia(fn ($page) => $page->where('products.total', 1));

        $this->post(route('prices.review.apply'), ['all' => true])->assertRedirect();
        $this->assertEqualsWithDelta(130 * 1.5 * 1.21, (float) $p->fresh()->price, 0.01);
    }

    public function test_revert_is_blocked_when_a_later_update_touched_the_same_products(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $p = $this->makeProduct();
        $svc = app(PriceUpdateService::class);
        $first = $svc->applyChanges('manual', 'uno', collect([['product' => $p, 'new_cost' => 110, 'new_price' => 200, 'new_markup' => 50]]));
        $svc->applyChanges('manual', 'dos', collect([['product' => $p->fresh(), 'new_cost' => 120, 'new_price' => 220, 'new_markup' => 50]]));

        $this->post(route('prices.updates.revert', $first))->assertSessionHas('error');
        $this->assertSame('applied', $first->fresh()->status);
    }

    public function test_mass_update_by_supplier(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $s = $this->makeSupplier();
        $p1 = $this->makeProduct(['supplier_id' => $s->id, 'cost' => 100, 'markup' => 50, 'price' => 181.5]);
        $p2 = $this->makeProduct(['cost' => 100, 'markup' => 50, 'price' => 181.5]);

        $this->post(route('prices.mass.apply'), [
            'filters' => ['supplier_id' => $s->id], 'target' => 'cost', 'percent' => 10, 'price_action' => 'keep_markup',
        ])->assertRedirect(route('prices.updates'));

        $this->assertEqualsWithDelta(110, (float) $p1->fresh()->cost, 0.001);
        $this->assertEqualsWithDelta(199.65, (float) $p1->fresh()->price, 0.01);
        $this->assertEqualsWithDelta(100, (float) $p2->fresh()->cost, 0.001);
    }

    public function test_a_product_cannot_be_linked_twice_in_the_same_list(): void
    {
        $this->makeTenant();
        $supplier = $this->makeSupplier();
        $p = $this->makeProduct(['code' => 'X1', 'barcode' => '7790000000099']);
        $this->link($supplier, $p, 'S-1', 100);
        $svc = app(SupplierPriceImportService::class);
        // S-1 vincula por código; S-2 trae el mismo EAN → debe marcarse como duplicado
        $import = $svc->upload($supplier, $this->excel([['c', 'p', 'ean'], ['S-1', 110, null], ['S-2', 120, '7790000000099'], ['S-3', 50, null]]));
        $svc->process($import, ['code' => 'A', 'price' => 'B', 'barcode' => 'C'], ['header_row' => 1]);

        $this->assertSame('error', $import->rows()->where('supplier_code', 'S-2')->value('status'));
        $row = $import->rows()->where('supplier_code', 'S-3')->first();
        $this->post(route('price-imports.link', [$import, $row]), ['product_id' => $p->id])->assertSessionHas('error');
        $this->assertSame('S-1', SupplierProduct::where('product_id', $p->id)->value('supplier_code'));
    }
}
