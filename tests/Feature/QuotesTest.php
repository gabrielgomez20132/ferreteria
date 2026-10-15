<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\ProductStock;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CashService;
use App\Services\PurchaseService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuotesTest extends TestCase
{
    private function seller(): User
    {
        $u = User::create(['tenant_id' => $this->user->tenant_id, 'role_id' => Role::where('name', 'Vendedor')->value('id'), 'name' => 'Vendedor', 'email' => 'v'.mt_rand(1, 99999).'@t.com', 'password' => 'x', 'current_branch_id' => app(TenantContext::class)->branchId()]);
        app(CashService::class)->open(CashRegister::create(['branch_id' => app(TenantContext::class)->branchId(), 'name' => 'Caja '.$u->id]), $u, 0);

        return $u;
    }

    /** Presupuesto hecho por el administrador con precio especial (800 en vez de 1000) y 15 % de descuento */
    private function quoteWithSpecialPrice(): array
    {
        $this->makeTenant(settings: ['price_rounding' => 0, 'quote_validity_days' => 10]);
        $p = $this->makeProduct(['price' => 1000, 'cost' => 500], 20);
        $c = Customer::create(['name' => 'Obra Norte', 'phone' => '3511234567']);
        $this->post(route('pos.store'), ['type' => 'quote', 'customer_id' => $c->id, 'discount_percent' => 15, 'notes' => 'Entrega en obra',
            'items' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => 800]]])->assertSessionHas('success');

        return [Sale::where('type', 'quote')->firstOrFail(), $p, $c];
    }

    public function test_quote_has_validity_terms_and_link_and_does_not_move_stock(): void
    {
        [$q, $p] = $this->quoteWithSpecialPrice();

        $this->assertSame('quote', $q->status);
        $this->assertSame('pending', $q->quote_state);
        $this->assertSame(now()->addDays(10)->toDateString(), $q->valid_until->toDateString());
        $this->assertNotEmpty($q->terms);
        $this->assertSame(40, strlen($q->public_token));
        $this->assertEquals(3400, (float) $q->total); // 5 x 800 = 4000 - 15 %
        $this->assertEquals(20, (float) ProductStock::where('product_id', $p->id)->value('quantity'));

        $this->get(route('quotes.index'))->assertOk()->assertInertia(fn (Assert $pg) => $pg->component('Quotes/Index', false)->where('counts.pending', 1)->where('kpis.pending_amount', 3400));
        $this->get(route('quotes.show', $q))->assertOk()->assertInertia(fn (Assert $pg) => $pg->where('publicUrl', route('quote.public', $q->public_token)));
        $this->get(route('sales.show', $q))->assertRedirect(route('quotes.show', $q));
    }

    public function test_seller_converts_valid_quote_keeping_quoted_price_and_discount(): void
    {
        [$q, $p, $c] = $this->quoteWithSpecialPrice();
        $seller = $this->seller(); // sin permiso para cambiar precios y con descuento máximo menor a 15 %

        $sale = fn () => ['type' => 'sale', 'quote_id' => $q->id, 'customer_id' => $c->id, 'discount_percent' => 15,
            'items' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => 800]], 'payments' => [['method' => 'cash', 'amount' => 3400]]];

        $this->actingAs($seller)->post(route('pos.store'), $sale())->assertSessionHas('success');
        $s = Sale::where('type', 'sale')->firstOrFail();
        $this->assertEquals(3400, (float) $s->total);
        $this->assertSame($q->id, $s->quote_id);
        $q->refresh();
        $this->assertSame('converted', $q->quote_state);
        $this->assertSame($s->id, $q->converted_sale_id);
        $this->assertEquals(15, (float) ProductStock::where('product_id', $p->id)->value('quantity'));

        // No se puede convertir dos veces
        $this->post(route('pos.store'), $sale())->assertSessionHas('error');

        // Sin presupuesto, el vendedor no puede poner ese precio
        $free = $sale();
        unset($free['quote_id']);
        $this->post(route('pos.store'), $free)->assertSessionHas('error');

        // Si se anula la venta, el presupuesto vuelve a quedar abierto
        $this->actingAs($this->user);
        app(CashService::class)->open(CashRegister::first(), $this->user, 0);
        $this->post(route('sales.cancel', $s), ['reason' => 'error de carga'])->assertSessionHas('success');
        $this->assertSame('pending', $q->fresh()->quote_state);
    }

    public function test_expired_quote_is_charged_at_current_prices(): void
    {
        [$q, $p, $c] = $this->quoteWithSpecialPrice();
        $this->travel(11)->days();
        $this->assertSame('expired', $q->fresh()->quote_state);
        $seller = $this->seller();

        $this->actingAs($seller)->post(route('pos.store'), ['type' => 'sale', 'quote_id' => $q->id, 'customer_id' => $c->id,
            'items' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => 800]], 'payments' => [['method' => 'cash', 'amount' => 4000]]])
            ->assertSessionHas('error');
        $this->post(route('pos.store'), ['type' => 'sale', 'quote_id' => $q->id, 'customer_id' => $c->id,
            'items' => [['product_id' => $p->id, 'quantity' => 5]], 'payments' => [['method' => 'cash', 'amount' => 5000]]])
            ->assertSessionHas('success');
        $this->assertSame('converted', $q->fresh()->quote_state);
    }

    public function test_edit_reject_reopen_and_extend(): void
    {
        [$q, $p, $c] = $this->quoteWithSpecialPrice();
        $p2 = $this->makeProduct(['price' => 300]);

        $this->put(route('quotes.update', $q), ['customer_id' => $c->id, 'discount_percent' => 0,
            'items' => [['product_id' => $p->id, 'quantity' => 2, 'unit_price' => 800], ['product_id' => $p2->id, 'quantity' => 1]]])
            ->assertRedirect(route('quotes.show', $q));
        $q->refresh();
        $this->assertSame(2, $q->items()->count());
        $this->assertEquals(1900, (float) $q->total);
        $this->assertSame(1, Sale::where('type', 'quote')->count()); // mismo número, no uno nuevo

        $this->patch(route('quotes.details', $q), ['valid_until' => now()->addDays(30)->toDateString(), 'terms' => 'Pago contado', 'notes' => 'x'])->assertSessionHas('success');
        $this->assertSame('Pago contado', $q->fresh()->terms);

        $this->post(route('quotes.reject', $q), ['reason' => 'Precio: más barato en otro lado'])->assertSessionHas('success');
        $this->assertSame('rejected', $q->fresh()->quote_state);
        $this->put(route('quotes.update', $q), ['items' => [['product_id' => $p->id, 'quantity' => 1]]])->assertSessionHas('error');
        $this->get(route('pos', ['quote' => $q->id]))->assertRedirect(route('quotes.show', $q));

        $this->post(route('quotes.reopen', $q))->assertSessionHas('success');
        $this->assertSame('pending', $q->fresh()->quote_state);

        $this->postJson(route('quotes.sent', $q))->assertOk();
        $this->assertNotNull($q->fresh()->sent_at);
    }

    public function test_public_link_shows_quote_without_costs(): void
    {
        [$q] = $this->quoteWithSpecialPrice();
        $this->getJson(route('quotes.pdf', $q))->assertOk()->assertJsonPath('quote.number', $q->number);

        Auth::logout();
        $res = $this->get(route('quote.public', $q->public_token))->assertOk()->assertSee('noindex', false);
        $res->assertInertia(fn (Assert $pg) => $pg->component('Quotes/Public', false)
            ->where('quote.number', $q->number)
            ->where('quote.quote_state', 'pending')
            ->missing('quote.cost_total')
            ->missing('quote.items.0.unit_cost')
            ->missing('quote.public_token'));
        $this->assertNotNull($q->fresh()->viewed_at);
        $this->get(route('quote.public', str_repeat('z', 40)))->assertNotFound();
    }

    public function test_quotes_and_purchases_reports_and_dashboard(): void
    {
        [$q, $p, $c] = $this->quoteWithSpecialPrice();
        $this->post(route('pos.store'), ['type' => 'quote', 'items' => [['product_id' => $p->id, 'quantity' => 1]]]);
        $q2 = Sale::where('type', 'quote')->latest('id')->first();
        $this->post(route('quotes.reject', $q2), ['reason' => 'Precio']);
        app(CashService::class)->open(CashRegister::first(), $this->user, 0);
        $this->post(route('pos.store'), ['type' => 'sale', 'quote_id' => $q->id, 'customer_id' => $c->id, 'discount_percent' => 15,
            'items' => [['product_id' => $p->id, 'quantity' => 5, 'unit_price' => 800]], 'payments' => [['method' => 'cash', 'amount' => 3400]]])->assertSessionHas('success');

        $this->get(route('reports.quotes'))->assertOk()->assertInertia(fn (Assert $pg) => $pg->component('Reports/Quotes', false)
            ->where('kpis.count', 2)->where('kpis.conv', 1)->where('kpis.rejected', 1)
            ->where('kpis.rate', 50)->where('kpis.sold', 3400)
            ->has('bySeller', 1)->where('reasons.0.reason', 'Precio')->has('months', 12));
        $this->get(route('reports.quotes.export'))->assertOk()->assertDownload();

        $supplier = $this->makeSupplier();
        foreach ([[400, 1], [440, 0]] as [$cost, $daysAgo]) {
            app(PurchaseService::class)->create([
                'supplier_id' => $supplier->id, 'voucher_type' => 'FA', 'voucher_number' => '0001-'.$cost, 'date' => now()->subDays($daysAgo)->toDateString(),
                'update_costs' => true, 'price_action' => 'keep_price',
                'items' => [['product_id' => $p->id, 'quantity' => 10, 'unit_factor' => 1, 'unit_cost' => $cost, 'discount_percent' => 0, 'vat_rate' => 21]],
            ]);
        }
        $this->get(route('reports.purchases', ['by' => 'supplier']))->assertOk()->assertInertia(fn (Assert $pg) => $pg->component('Reports/Purchases', false)
            ->where('kpis.count', 2)->where('kpis.net', 8400)
            ->where('rows.0.label', $supplier->name)->where('rows.0.amount', fn ($v) => abs($v - 8400) < 0.01)
            ->where('variation.0.variation', 10)
            ->has('pending', 2)->has('months', 12));
        foreach (['category', 'brand', 'month', 'product'] as $by) {
            $this->get(route('reports.purchases', ['by' => $by]))->assertOk();
        }
        $this->get(route('reports.purchases.export'))->assertOk()->assertDownload();

        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $pg) => $pg->where('stats.quotes_month', 2)->where('stats.quotes_month_converted', 1)->where('stats.purchases_month_count', 2));
    }
}
