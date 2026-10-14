<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Conteos de alertas de reposición de una sucursal (bajo mínimo / sobre máximo).
 */
class StockAlerts
{
    public static function counts(?int $branchId): array
    {
        if (! $branchId) {
            return ['low' => 0, 'over' => 0];
        }
        $tenantId = app(TenantContext::class)->id();
        $base = fn () => DB::table('products')
            ->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branchId))
            ->where('products.tenant_id', $tenantId)->whereNull('products.deleted_at')
            ->where('products.is_active', true)->where('products.track_stock', true);

        return [
            'low' => $base()->whereRaw('COALESCE(ps.min_stock, products.min_stock) > 0')
                ->whereRaw('COALESCE(ps.quantity,0) <= COALESCE(ps.min_stock, products.min_stock)')->count(),
            'over' => $base()->where('ps.max_stock', '>', 0)->whereRaw('ps.quantity > ps.max_stock')->count(),
        ];
    }
}
