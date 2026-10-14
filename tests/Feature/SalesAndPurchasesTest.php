<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashService;
use App\Services\PurchaseService;
use App\Support\TenantContext;
use Tests\TestCase;

class SalesAndPurchasesTest extends TestCase
{
    private function openCash(): void
    {
        app(CashService::class)->open(CashRegister::first(), $this->user, 1000);
    }

    private function stock(Product $p): float
    {
        return (float) ProductStock::where('product_id', $p->id)->where('branch_id', app(TenantContext::class)->branchId())->value('quantity');
    }

    public function test_sale_requires_open_cash_and_moves_stock_and_cash(): void
    {
        $this->makeTenant();
        $p = $this->makeProduct(['price' => 1000], 10);

        $this->post(route('pos.store'), ['type' => 'sale', 'items' => [['product_id' => $p->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2000]]])
            ->assertSessionHas('error');

        $this->openCash();
        $this->post(route('pos.store'), ['type' => 'sale', 'items' => [['product_id' => $p->id, 'quantity' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2500]]])
            ->assertSessionHas('success');

        $sale = Sale::firstOrFail();
        $this->assertEquals(2000, (float) $sale->total);
        $this->assertEquals(500, (float) $sale->change_amount);
        $this->assertEquals(8, $this->stock($p));
        $this->assertEquals(3000, app(CashService::class)->expectedCash($sale->cashSession)); // 1000 apertura + 2000

        // anulación
        $this->post(route('sales.cancel', $sale), ['reason' => 'error'])->assertSessionHas('success');
        $this->assertEquals(10, $this->stock($p));
        $this->assertEquals(1000, app(CashService::class)->expectedCash($sale->cashSession));
    }

    public function test_fractional_sale_and_seller_discount_limit(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $this->openCash();
        $cable = $this->makeProduct(['price' => 500, 'allow_fraction' => true], 100);
        $screw = $this->makeProduct(['price' => 10, 'allow_fraction' => false], 100);

        $this->post(route('pos.store'), ['type' => 'sale', 'items' => [['product_id' => $cable->id, 'quantity' => 12.5]], 'payments' => [['method' => 'transfer', 'amount' => 6250]]])
            ->assertSessionHas('success');
        $this->assertEquals(87.5, $this->stock($cable));

        $this->post(route('pos.store'), ['type' => 'sale', 'items' => [['product_id' => $screw->id, 'quantity' => 1.5]], 'payments' => [['method' => 'cash', 'amount' => 100]]])
            ->assertSessionHas('error');

        // un vendedor (5% máx.) no puede hacer 10%
        $seller = User::create(['tenant_id' => $this->user->tenant_id, 'role_id' => \App\Models\Role::where('name', 'Vendedor')->value('id'), 'name' => 'V', 'email' => 'v@t.com', 'password' => 'x', 'current_branch_id' => app(TenantContext::class)->branchId()]);
        app(CashService::class)->open(CashRegister::create(['branch_id' => app(TenantContext::class)->branchId(), 'name' => 'Caja 2']), $seller, 0);
        $this->actingAs($seller)->post(route('pos.store'), ['type' => 'sale', 'discount_percent' => 10, 'items' => [['product_id' => $screw->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 10]]])
            ->assertSessionHas('error');
    }

    public function test_account_sale_and_payment(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $this->openCash();
        $p = $this->makeProduct(['price' => 1000], 10);
        $c = Customer::create(['name' => 'Cliente', 'allow_credit' => true, 'credit_limit' => 5000]);

        $this->post(route('pos.store'), ['type' => 'sale', 'customer_id' => $c->id, 'items' => [['product_id' => $p->id, 'quantity' => 3]], 'payments' => [['method' => 'account', 'amount' => 3000]]])
            ->assertSessionHas('success');
        $this->assertEquals(3000, (float) $c->fresh()->balance);

        // supera límite
        $this->post(route('pos.store'), ['type' => 'sale', 'customer_id' => $c->id, 'items' => [['product_id' => $p->id, 'quantity' => 3]], 'payments' => [['method' => 'account', 'amount' => 3000]]])
            ->assertSessionHas('error');

        $this->post(route('customers.payment', $c), ['amount' => 1000, 'method' => 'cash'])->assertSessionHas('success');
        $this->assertEquals(2000, (float) $c->fresh()->balance);

        // devolución parcial acreditada en cuenta
        $sale = Sale::firstOrFail();
        $item = $sale->items->first();
        $this->post(route('sales.return', $sale), ['items' => [$item->id => 1], 'refund_method' => 'account', 'restock' => true])->assertSessionHas('success');
        $this->assertEquals(1000, (float) $c->fresh()->balance);
        $this->assertEquals(8, $this->stock($p));
    }

    public function test_purchase_with_unit_conversion_updates_stock_costs_and_prices(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        $supplier = $this->makeSupplier(['payment_term_days' => 30]);
        // cable vendido por metro, comprado por rollo de 100 m
        $cable = $this->makeProduct(['cost' => 300, 'markup' => 50, 'price' => 544.5, 'allow_fraction' => true, 'purchase_unit_factor' => 100], 100);

        app(PurchaseService::class)->create([
            'supplier_id' => $supplier->id, 'voucher_type' => 'FA', 'voucher_number' => '0001-00001234', 'date' => now()->toDateString(),
            'extra_costs' => 1000, 'update_costs' => true, 'price_action' => 'keep_markup',
            'items' => [['product_id' => $cable->id, 'quantity' => 5, 'unit_factor' => 100, 'unit_cost' => 40000, 'discount_percent' => 0, 'vat_rate' => 21]],
        ]);

        $cable->refresh();
        $this->assertEquals(600, $this->stock($cable));                           // 100 + 5 rollos x 100 m
        $this->assertEqualsWithDelta(402, (float) $cable->cost, 0.001);            // (200000 + 1000 flete) / 500
        $this->assertEqualsWithDelta((100 * 300 + 500 * 402) / 600, (float) $cable->average_cost, 0.01);
        $this->assertEqualsWithDelta(402 * 1.5 * 1.21, (float) $cable->price, 0.01);
        $this->assertEqualsWithDelta(200000 * 1.21 + 1000, (float) $supplier->fresh()->balance, 0.01);
    }

    public function test_tenants_are_isolated(): void
    {
        $this->makeTenant('a@a.com');
        $p = $this->makeProduct(['code' => 'SECRET']);
        $this->makeTenant('b@b.com');

        $this->assertNull(Product::where('code', 'SECRET')->first());
        $this->get(route('products.show', $p))->assertNotFound();
    }

    public function test_main_pages_render(): void
    {
        $this->makeTenant();
        $this->makeProduct();
        foreach (['dashboard', 'products.index', 'products.create', 'pos', 'sales.index', 'customers.index', 'suppliers.index', 'purchases.index',
            'purchases.create', 'purchases.suggestions', 'purchase-orders.index', 'cash.index', 'cash.history', 'expenses.index', 'stock.index', 'stock.movements',
            'stock.low', 'stock.adjust', 'stock.transfers.index', 'stock.inventory.index', 'price-imports.index', 'prices.updates', 'prices.mass',
            'prices.review', 'prices.comparison', 'reports.index', 'reports.sales', 'reports.profitability', 'reports.valuation', 'reports.no-rotation',
            'reports.low-margin', 'reports.accounts', 'reports.results', 'settings.company', 'branches.index', 'users.index', 'roles.index', 'audit.index',
            'products.import'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('catalog.index', 'categorias'))->assertOk();
    }
}
