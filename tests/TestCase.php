<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\PricingService;
use App\Services\StockService;
use App\Services\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** Crea una empresa con su administrador y deja el contexto activo */
    protected function makeTenant(string $email = 'admin@test.com', array $settings = []): User
    {
        Plan::firstOrCreate(['slug' => 'pro'], ['name' => 'Pro', 'price' => 1, 'max_branches' => 5, 'max_users' => 10]);
        $user = app(TenantProvisioner::class)->provision(['name' => 'Ferretería Test', 'status' => 'active'], ['name' => 'Admin', 'email' => $email, 'password' => 'password']);
        $tenant = $user->tenant;
        if ($settings) {
            $tenant->update(['settings' => array_merge($tenant->allSettings(), $settings)]);
        }
        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        app(TenantContext::class)->set($tenant->fresh(), $branch);
        $this->actingAs($user);
        $this->user = $user;

        return $user;
    }

    protected function makeProduct(array $attrs = [], float $stock = 100): Product
    {
        $p = Product::create(array_merge([
            'code' => 'P'.mt_rand(10000, 99999), 'name' => 'Producto test', 'cost' => 100, 'markup' => 50,
            'vat_rate' => 21, 'price' => 181.5, 'track_stock' => true, 'is_active' => true,
        ], $attrs))->fresh();
        if ($stock) {
            app(StockService::class)->move($p, app(TenantContext::class)->branchId(), $stock, 'initial');
        }

        return $p->fresh();
    }

    protected function makeSupplier(array $attrs = []): Supplier
    {
        return Supplier::create(array_merge(['name' => 'Proveedor test'], $attrs))->fresh();
    }

    protected function link(Supplier $s, Product $p, string $code, float $listPrice, float $pack = 1): SupplierProduct
    {
        return SupplierProduct::create([
            'supplier_id' => $s->id, 'product_id' => $p->id, 'supplier_code' => $code,
            'list_price' => $listPrice, 'pack_quantity' => $pack,
            'cost' => app(PricingService::class)->supplierCost($listPrice, $s->discounts(), $pack),
        ]);
    }
}
