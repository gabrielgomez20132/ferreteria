<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\CashService;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerPortalAndReportsTest extends TestCase
{
    /** Cliente con una venta a cuenta de 3000 y un pago de 1000 */
    private function customerWithAccount(): Customer
    {
        $this->makeTenant(settings: ['price_rounding' => 0]);
        app(CashService::class)->open(CashRegister::first(), $this->user, 0);
        $p = $this->makeProduct(['price' => 1000], 10);
        $c = Customer::create(['name' => 'Juan Pérez', 'phone' => '3415551234', 'allow_credit' => true, 'credit_limit' => 10000]);
        $this->post(route('pos.store'), ['type' => 'sale', 'customer_id' => $c->id, 'items' => [['product_id' => $p->id, 'quantity' => 3]], 'payments' => [['method' => 'account', 'amount' => 3000]]])
            ->assertSessionHas('success');
        $this->post(route('customers.payment', $c), ['amount' => 1000, 'method' => 'transfer'])->assertSessionHas('success');

        return $c->fresh();
    }

    public function test_portal_link_can_be_enabled_regenerated_and_disabled(): void
    {
        $c = $this->customerWithAccount();
        $this->assertNull($c->portal_token);
        $this->get(route('customers.show', $c))->assertInertia(fn (Assert $p) => $p->where('portal.url', null));

        $this->post(route('customers.portal.enable', $c))->assertSessionHas('success');
        $token = $c->fresh()->portal_token;
        $this->assertSame(40, strlen($token));
        $this->get(route('customers.show', $c))->assertInertia(fn (Assert $p) => $p->where('portal.url', route('portal.show', $token)));

        // El token no se filtra en listados ni en la búsqueda del POS
        $this->getJson(route('customers.search', ['q' => 'Juan']))->assertOk()->assertJsonMissingPath('0.portal_token');

        // Regenerar invalida el link anterior
        $this->post(route('customers.portal.enable', $c));
        $new = $c->fresh()->portal_token;
        $this->assertNotSame($token, $new);

        Auth::logout();
        $this->get(route('portal.show', $token))->assertNotFound();
        $this->get(route('portal.show', $new))->assertOk();

        $this->actingAs($this->user)->delete(route('customers.portal.disable', $c))->assertSessionHas('success');
        Auth::logout();
        $this->get(route('portal.show', $new))->assertNotFound();
    }

    public function test_portal_shows_account_pending_sales_and_payments_without_login(): void
    {
        $c = $this->customerWithAccount();
        $token = $c->regeneratePortalToken();
        $sale = Sale::where('customer_id', $c->id)->firstOrFail();

        // Otro cliente de la misma empresa con su propia venta
        $other = Customer::create(['name' => 'Otro', 'allow_credit' => true]);
        $this->post(route('pos.store'), ['type' => 'sale', 'customer_id' => $other->id, 'items' => [['product_id' => $sale->items()->value('product_id'), 'quantity' => 1]], 'payments' => [['method' => 'account', 'amount' => 1000]]]);
        $otherSale = Sale::where('customer_id', $other->id)->firstOrFail();

        Auth::logout();
        $this->get(route('portal.show', $token))
            ->assertOk()
            ->assertSee('noindex', false)
            ->assertInertia(fn (Assert $p) => $p
                ->component('Portal/Show', false)
                ->where('months', 12)
                ->where('customer.name', 'Juan Pérez')
                ->missing('customer.portal_token')
                ->where('summary.balance', 2000)
                ->has('pending', 1)
                ->where('pending.0.pending', 2000)
                ->where('pending.0.sale_id', $sale->id)
                ->has('sales', 1)
                ->has('payments', 1)
                ->where('payments.0.credit', 1000)
                ->where('payments.0.method', 'transfer')
                ->has('movements', 2));
        $this->assertNotNull($c->fresh()->portal_last_seen_at);

        // Comprobante propio sí, el de otro cliente no
        $this->getJson(route('portal.sale', [$token, $sale->id]))->assertOk()->assertJsonPath('sale.number', $sale->number)->assertJsonCount(1, 'sale.items');
        $this->getJson(route('portal.sale', [$token, $otherSale->id]))->assertNotFound();
        $this->get(route('portal.show', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_portal_is_closed_when_company_is_suspended(): void
    {
        $c = $this->customerWithAccount();
        $token = $c->regeneratePortalToken();
        $this->user->tenant->update(['status' => 'suspended']);
        Auth::logout();
        $this->get(route('portal.show', $token))->assertNotFound();
    }

    public function test_customers_report_ranking_inactive_detail_and_export(): void
    {
        $c = $this->customerWithAccount();
        // Cliente que compró hace 4 meses y no volvió
        $old = Customer::create(['name' => 'Cliente Viejo', 'allow_credit' => true]);
        $this->travel(-120)->days();
        app(CashService::class)->open(CashRegister::create(['branch_id' => app(\App\Support\TenantContext::class)->branchId(), 'name' => 'Caja vieja']), $this->user, 0);
        $p = $this->makeProduct(['price' => 500], 5);
        $this->post(route('pos.store'), ['type' => 'sale', 'customer_id' => $old->id, 'items' => [['product_id' => $p->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => 500]]])->assertSessionHas('success');
        $this->travelBack();

        $this->get(route('reports.customers', ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (Assert $pg) => $pg
                ->component('Reports/Customers', false)
                ->has('rows', 1)
                ->where('rows.0.name', 'Juan Pérez')
                ->where('rows.0.total', 3000)
                ->where('rows.0.count', 1)
                ->where('rows.0.abc', 'A')
                ->where('rows.0.is_new', true)
                ->where('rows.0.balance', 2000)
                ->where('kpis.customers', 1)
                ->has('inactive', 1)
                ->where('inactive.0.name', 'Cliente Viejo')
                ->where('detail', null));

        $this->get(route('reports.customers', ['customer_id' => $c->id]))
            ->assertInertia(fn (Assert $pg) => $pg->where('detail.customer.id', $c->id)->has('detail.months', 12)->has('detail.products', 1)->missing('filters.customer_id'));

        $this->get(route('reports.customers.export'))->assertOk()->assertDownload();
    }
}
