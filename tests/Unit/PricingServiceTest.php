<?php

namespace Tests\Unit;

use App\Services\PricingService;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    public function test_parse_number_handles_argentine_and_english_formats(): void
    {
        $this->assertSame(1234.56, PricingService::parseNumber('1.234,56'));
        $this->assertSame(1234.56, PricingService::parseNumber('1,234.56'));
        $this->assertSame(1234.56, PricingService::parseNumber('$ 1234,56'));
        $this->assertSame(1234567.0, PricingService::parseNumber('1.234.567'));
        $this->assertSame(99.9, PricingService::parseNumber(99.9));
        $this->assertNull(PricingService::parseNumber('consultar'));
        $this->assertNull(PricingService::parseNumber(''));
    }

    public function test_supplier_cost_applies_cascading_discounts_vat_and_pack(): void
    {
        $this->makeTenant();
        $p = app(PricingService::class);
        // 1000 -10% -5% = 855
        $this->assertEqualsWithDelta(855, $p->supplierCost(1000, [10, 5]), 0.0001);
        // rollo de 100 m a 42000 con 8% → 386.4 por metro
        $this->assertEqualsWithDelta(386.4, $p->supplierCost(42000, [8], 100), 0.0001);
        // lista con IVA incluido 1210 → 1000 neto
        $this->assertEqualsWithDelta(1000, $p->supplierCost(1210, [], 1, true, 21), 0.0001);
        // lista en dólares
        $this->assertEqualsWithDelta(10000, $p->supplierCost(10, [], 1, false, 21, 0, 1000), 0.0001);
    }

    public function test_price_rounding_up_to_step(): void
    {
        $this->makeTenant(settings: ['price_rounding' => 10, 'rounding_mode' => 'up']);
        $p = app(PricingService::class);
        // 100 * 1.5 * 1.21 = 181.5 → 190
        $this->assertEquals(190, $p->priceFromCost(100, 50, 21));
        $this->assertEquals(1000, $p->roundPrice(1000));
    }
}
