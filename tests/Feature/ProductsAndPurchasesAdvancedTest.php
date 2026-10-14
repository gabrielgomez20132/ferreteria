<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductStock;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SupplierDiscountRule;
use App\Services\CashService;
use App\Services\PaymentOrderService;
use App\Services\ProductStructureService;
use App\Services\PurchaseService;
use App\Services\SupplierPriceImportService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductsAndPurchasesAdvancedTest extends TestCase
{
    private function stock(Product $p): float
    {
        return (float) ProductStock::where('product_id', $p->id)->where('branch_id', app(TenantContext::class)->branchId())->value('quantity');
    }

    public function test_kit_sale_discounts_component_stock_and_cancel_restores_it(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        app(CashService::class)->open(CashRegister::first(), $this->user, 0);
        $cable = $this->makeProduct(['code' => 'CAB', 'cost' => 100], 50);
        $plug = $this->makeProduct(['code' => 'FIC', 'cost' => 300], 10);

        $this->post(route('products.store'), [
            'code' => 'KIT-1', 'name' => 'Kit alargue', 'type' => 'kit', 'cost' => 0, 'vat_rate' => 21, 'markup' => 50, 'price' => 2000,
            'purchase_unit_factor' => 1, 'components' => [['component_id' => $cable->id, 'quantity' => 5], ['component_id' => $plug->id, 'quantity' => 2]],
        ])->assertRedirect();
        $kit = Product::where('code', 'KIT-1')->firstOrFail();
        $this->assertFalse($kit->track_stock);
        $this->assertEquals(5, app(ProductStructureService::class)->kitAvailable($kit, app(TenantContext::class)->branchId()));

        $this->post(route('pos.store'), ['type' => 'sale', 'items' => [['product_id' => $kit->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 4000]]])
            ->assertSessionHas('success');
        $this->assertEquals(40, $this->stock($cable));
        $this->assertEquals(6, $this->stock($plug));

        $this->post(route('sales.cancel', Sale::firstOrFail()), ['reason' => 'x'])->assertSessionHas('success');
        $this->assertEquals(50, $this->stock($cable));
        $this->assertEquals(10, $this->stock($plug));

        // el costo del kit sigue al de sus componentes
        app(\App\Services\PriceUpdateService::class)->applyChanges('manual', 'x', collect([['product' => $cable->fresh(), 'new_cost' => 200, 'new_price' => 400, 'new_markup' => 50]]));
        $this->assertEqualsWithDelta(5 * 200 + 2 * 300, (float) $kit->fresh()->cost, 0.001);
    }

    public function test_variants_are_generated_and_parent_is_not_sellable(): void
    {
        $this->makeTenant();
        $parent = $this->makeProduct(['code' => 'TOR', 'name' => 'Tornillo', 'price' => 100], 0);
        $this->post(route('products.variants', $parent), ['attributes' => [
            ['name' => 'Medida', 'values' => '6x40, 8x50'], ['name' => 'Terminación', 'values' => 'Zincado; Negro'],
        ]])->assertSessionHas('success');

        $this->assertSame(4, $parent->variants()->count());
        $this->assertTrue($parent->fresh()->has_variants);
        $this->assertTrue(Product::where('name', 'Tornillo 6x40 / Negro')->exists());
        $json = $this->getJson(route('products.search', ['q' => 'tornillo']))->json('items');
        $this->assertCount(4, $json);
    }

    public function test_production_order_consumes_inputs_and_produces_stock(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $cable = $this->makeProduct(['code' => 'CAB', 'cost' => 100], 20);
        $plug = $this->makeProduct(['code' => 'FIC', 'cost' => 300], 3);
        $made = $this->makeProduct(['code' => 'ALG', 'type' => 'manufactured', 'cost' => 0, 'markup' => 50, 'price' => 1500], 0);
        app(ProductStructureService::class)->syncComponents($made, [['component_id' => $cable->id, 'quantity' => 3], ['component_id' => $plug->id, 'quantity' => 1]]);

        $this->post(route('stock.production.store'), ['product_id' => $made->id, 'quantity' => 5])->assertRedirect();
        $order = ProductionOrder::firstOrFail();
        $this->get(route('stock.production.show', $order))->assertOk();
        // faltan fichas (5 necesarias, 3 en stock)
        $this->post(route('stock.production.complete', $order))->assertSessionHas('error');
        $this->get(route('stock.production.index'))->assertOk()->assertInertia(fn ($p) => $p->where('requirements.0.missing', 2));

        app(\App\Services\StockService::class)->move($plug, app(TenantContext::class)->branchId(), 2, 'adjustment');
        $this->post(route('stock.production.complete', $order))->assertSessionHas('success');
        $this->assertEquals(5, $this->stock($made));
        $this->assertEquals(5, $this->stock($cable));
        $this->assertEquals(0, $this->stock($plug));
        $this->assertEqualsWithDelta(600, (float) $made->fresh()->cost, 0.001);
        $this->get(route('stock.production.requirements'))->assertOk();
    }

    public function test_payment_order_applies_to_oldest_invoices_and_can_be_cancelled(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $s = $this->makeSupplier();
        $p = $this->makeProduct();
        $svc = app(PurchaseService::class);
        $mk = fn ($date) => $svc->create(['supplier_id' => $s->id, 'voucher_type' => 'FC', 'date' => $date, 'due_date' => $date, 'update_costs' => false,
            'items' => [['product_id' => $p->id, 'quantity' => 10, 'unit_factor' => 1, 'unit_cost' => 100, 'vat_rate' => 0]]]);
        $old = $mk(now()->subDays(20)->toDateString());
        $new = $mk(now()->toDateString());

        $this->post(route('payment-orders.store'), [
            'supplier_id' => $s->id, 'date' => now()->toDateString(),
            'lines' => [['method' => 'transfer', 'amount' => 800, 'reference' => 'TRF-1'], ['method' => 'check', 'amount' => 700, 'reference' => '123', 'bank' => 'Galicia', 'check_date' => now()->addDays(30)->toDateString()]],
        ])->assertRedirect();

        $this->assertEquals(1000, (float) $old->fresh()->paid_amount);
        $this->assertEquals(500, (float) $new->fresh()->paid_amount);
        $this->assertEquals(500, (float) $s->fresh()->balance);
        $this->post(route('purchases.cancel', $old))->assertSessionHas('error');

        $order = \App\Models\PaymentOrder::firstOrFail();
        $this->get(route('payment-orders.show', $order))->assertOk();
        $this->post(route('payment-orders.cancel', $order))->assertSessionHas('success');
        $this->assertEquals(0, (float) $old->fresh()->paid_amount);
        $this->assertEquals(2000, (float) $s->fresh()->balance);
    }

    public function test_brand_discount_rule_is_used_when_importing_a_price_list(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $s = $this->makeSupplier(['discount_1' => 10]);
        $brand = Brand::create(['name' => 'Fischer']);
        $a = $this->makeProduct(['code' => 'A', 'brand_id' => $brand->id]);
        $b = $this->makeProduct(['code' => 'B']);
        $this->link($s, $a, 'S-A', 100);
        $this->link($s, $b, 'S-B', 100);
        SupplierDiscountRule::create(['supplier_id' => $s->id, 'brand_id' => $brand->id, 'discount_1' => 25]);

        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['cod', 'precio'], ['S-A', 1000], ['S-B', 1000]], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'l').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $svc = app(SupplierPriceImportService::class);
        $import = $svc->upload($s, new UploadedFile($path, 'l.xlsx', null, null, true));
        $svc->process($import, ['code' => 'A', 'price' => 'B'], ['header_row' => 1]);

        $this->assertEqualsWithDelta(750, (float) $import->rows()->where('supplier_code', 'S-A')->value('new_cost'), 0.001); // regla de marca 25%
        $this->assertEqualsWithDelta(900, (float) $import->rows()->where('supplier_code', 'S-B')->value('new_cost'), 0.001); // general 10%
    }

    public function test_automatic_purchase_orders_by_supplier_skip_already_ordered(): void
    {
        $this->makeTenant();
        $s = $this->makeSupplier();
        $p = $this->makeProduct(['supplier_id' => $s->id, 'min_stock' => 10], 2);
        $this->post(route('purchases.auto-orders'))->assertRedirect();
        $order = PurchaseOrder::with('items')->firstOrFail();
        $this->assertSame($s->id, $order->supplier_id);
        $this->assertEquals(18, (float) $order->items->first()->quantity); // max(2×mín, vendido) − stock = 20 − 2
        // ya pedido: no genera otro
        $this->post(route('purchases.auto-orders'))->assertSessionHas('error');
    }

    public function test_warehouse_cannot_sell_and_over_max_alert(): void
    {
        $this->makeTenant();
        $p = $this->makeProduct([], 100);
        ProductStock::where('product_id', $p->id)->update(['max_stock' => 50]);
        $this->get(route('stock.low', ['mode' => 'over']))->assertOk()->assertInertia(fn ($pg) => $pg->where('counts.over', 1));

        $w = Branch::create(['name' => 'Depósito', 'type' => 'warehouse']);
        $this->user->forceFill(['current_branch_id' => $w->id])->save();
        $this->post(route('pos.store'), ['type' => 'sale', 'items' => [['product_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 999]]])
            ->assertSessionHas('error');
    }
}
