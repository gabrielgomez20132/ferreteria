<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Support\TenantContext;

/**
 * Reglas de cálculo de costos y precios.
 *
 *  costo neto proveedor = precio lista × (1 - bonif1) × (1 - bonif2) × (1 - bonif3) × (1 + recargo) [/ (1+IVA) si la lista incluye IVA] / unidades por bulto
 *  precio final        = costo × (1 + markup) × (1 + IVA)  → redondeado según configuración
 */
class PricingService
{
    public const PRICE_ACTIONS = [
        'keep_markup' => 'Mantener margen (recalcular precio)',
        'only_increase' => 'Mantener margen, pero nunca bajar precios',
        'keep_price' => 'Mantener precio (recalcular margen)',
        'custom_markup' => 'Aplicar un margen fijo',
        'review' => 'Solo actualizar costo (revisar precios después)',
    ];

    public function __construct(private TenantContext $context) {}

    public function roundPrice(float $price): float
    {
        $step = (float) $this->context->setting('price_rounding', 0);
        if ($step <= 0 || $price <= 0) {
            return round($price, 2);
        }
        $mode = $this->context->setting('rounding_mode', 'up');
        $units = $price / $step;
        // tolerancia para evitar 1000.0000001 → 1010
        $units = $mode === 'up' ? ceil(round($units, 6)) : round($units);

        return round(max($units, 1) * $step, 2);
    }

    public function priceFromCost(float $cost, float $markup, float $vatRate, bool $round = true): float
    {
        $price = $cost * (1 + $markup / 100) * (1 + $vatRate / 100);

        return $round ? $this->roundPrice($price) : round($price, 2);
    }

    public function markupFromPrice(float $cost, float $price, float $vatRate): float
    {
        if ($cost <= 0) {
            return 0;
        }
        $net = $price / (1 + $vatRate / 100);

        return round(($net / $cost - 1) * 100, 2);
    }

    /**
     * Costo neto por unidad de stock a partir del precio de lista del proveedor.
     */
    public function supplierCost(
        float $listPrice,
        array $discounts = [],
        float $packQuantity = 1,
        bool $priceIncludesVat = false,
        float $vatRate = 21,
        float $surchargePercent = 0,
        float $exchangeRate = 1,
    ): float {
        $cost = $listPrice * ($exchangeRate > 0 ? $exchangeRate : 1);
        foreach ($discounts as $d) {
            $d = (float) $d;
            if ($d != 0.0) {
                $cost *= (1 - $d / 100);
            }
        }
        if ($surchargePercent != 0.0) {
            $cost *= (1 + $surchargePercent / 100);
        }
        if ($priceIncludesVat) {
            $cost /= (1 + $vatRate / 100);
        }
        $pack = $packQuantity > 0 ? $packQuantity : 1;

        return round($cost / $pack, 4);
    }

    /**
     * Calcula el nuevo precio y margen de un producto ante un cambio de costo, según la acción elegida.
     *
     * @return array{price: float, markup: float}
     */
    public function resolvePrice(Product $product, float $newCost, string $action, ?float $customMarkup = null): array
    {
        $vat = (float) $product->vat_rate;
        $markup = (float) $product->markup;
        $currentPrice = (float) $product->price;

        switch ($action) {
            case 'keep_markup':
                $price = $this->priceFromCost($newCost, $markup, $vat);
                break;
            case 'only_increase':
                $price = max($this->priceFromCost($newCost, $markup, $vat), $currentPrice);
                break;
            case 'custom_markup':
                $markup = (float) ($customMarkup ?? $markup);
                $price = $this->priceFromCost($newCost, $markup, $vat);
                break;
            case 'keep_price':
            case 'review':
            default:
                // El precio no cambia; el margen objetivo se conserva y el producto
                // aparecerá en "Precios a revisar" si su margen real quedó desfasado.
                $price = $currentPrice;
                break;
        }

        return ['price' => $price, 'markup' => round($markup, 2)];
    }

    /**
     * Expresión SQL del precio sugerido (sin redondeo) para detectar precios desactualizados.
     */
    public static function suggestedPriceSql(string $table = 'products'): string
    {
        return "({$table}.cost * (1 + {$table}.markup / 100) * (1 + {$table}.vat_rate / 100))";
    }

    /** Condición SQL: precio por debajo del sugerido más allá de la tolerancia (%) */
    public static function outdatedSql(float $tolerance = 2, string $table = 'products'): string
    {
        $f = 1 - $tolerance / 100;

        return "{$table}.cost > 0 AND {$table}.price < ".self::suggestedPriceSql($table)." * {$f}";
    }

    /** Precio de un producto en una lista determinada */
    public function priceFor(Product $product, ?PriceList $list): float
    {
        if (! $list || $list->is_default) {
            return (float) $product->price;
        }
        $override = $product->relationLoaded('prices')
            ? $product->prices->firstWhere('price_list_id', $list->id)
            : $product->prices()->where('price_list_id', $list->id)->first();
        if ($override) {
            return (float) $override->price;
        }

        return $this->roundPrice((float) $product->price * (1 + (float) $list->adjustment_percent / 100));
    }

    /** Convierte texto de planilla en número: soporta "1.234,56", "1,234.56", "$ 1234", "1234.5" */
    public static function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string) $value);
        if ($s === '' || $s === '-' || $s === null) {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {          // 1.234,56
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {                              // 1,234.56
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            // En Argentina la coma es separador decimal ("1234,56"); varias comas = separador de miles
            if (substr_count($s, ',') > 1) {
                $s = str_replace(',', '', $s);
            } else {
                $s = str_replace(',', '.', $s);
            }
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) { // 1.234.567
            $s = str_replace('.', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
