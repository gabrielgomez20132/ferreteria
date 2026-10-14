<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierPriceImport;
use App\Services\PricingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function home(Request $request)
    {
        $user = $request->user();
        if ($user->is_super_admin && ! $user->tenant_id) {
            return redirect()->route('admin.dashboard');
        }
        if ($user->hasPermission('dashboard.view')) {
            return redirect()->route('dashboard');
        }
        if ($user->hasPermission('sales.create')) {
            return redirect()->route('pos');
        }

        return redirect()->route('products.index');
    }

    public function expired(TenantContext $ctx)
    {
        return Inertia::render('Subscription/Expired', ['tenant' => $ctx->tenant?->only(['name', 'status', 'trial_ends_at', 'subscription_ends_at'])]);
    }

    public function index(Request $request, TenantContext $ctx)
    {
        $scope = $request->input('scope', 'branch'); // branch | all
        $branchId = $ctx->branchId();
        $sales = fn () => Sale::where('status', 'completed')->where('type', 'sale')
            ->when($scope === 'branch', fn ($q) => $q->where('branch_id', $branchId));

        $today = now()->startOfDay();
        $month = now()->startOfMonth();

        $todayStats = $sales()->where('created_at', '>=', $today)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total - returned_amount),0) t, COALESCE(SUM(cost_total),0) cost')->first();
        $monthStats = $sales()->where('created_at', '>=', $month)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total - returned_amount),0) t, COALESCE(SUM(cost_total),0) cost')->first();
        $prevMonthTotal = (float) $sales()->whereBetween('created_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->setDay(min(now()->day, now()->subMonthNoOverflow()->daysInMonth))->endOfDay()])->sum('total');

        $daily = $sales()->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) d, SUM(total) t, COUNT(*) c')->groupBy('d')->orderBy('d')->get()->keyBy('d');
        $chart = collect(range(29, 0))->map(function ($i) use ($daily) {
            $d = now()->subDays($i)->toDateString();

            return ['date' => $d, 'total' => (float) ($daily[$d]->t ?? 0), 'count' => (int) ($daily[$d]->c ?? 0)];
        });

        $topProducts = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $ctx->id())->where('sales.status', 'completed')->where('sales.type', 'sale')
            ->where('sales.created_at', '>=', $month)
            ->when($scope === 'branch', fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('sale_items.product_id, sale_items.description, SUM(sale_items.quantity) qty, SUM(sale_items.subtotal) total')
            ->groupBy('sale_items.product_id', 'sale_items.description')->orderByDesc('total')->limit(8)->get();

        $lowStock = Product::where('products.is_active', true)->where('track_stock', true)
            ->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branchId))
            ->whereRaw('COALESCE(ps.min_stock, products.min_stock) > 0')
            ->whereRaw('COALESCE(ps.quantity,0) <= COALESCE(ps.min_stock, products.min_stock)')->count();

        $byMethod = DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.tenant_id', $ctx->id())->where('sales.status', 'completed')->where('sales.created_at', '>=', $today)
            ->when($scope === 'branch', fn ($q) => $q->where('sales.branch_id', $branchId))
            ->selectRaw('method, SUM(amount) total')->groupBy('method')->get();

        return Inertia::render('Dashboard', [
            'scope' => $scope,
            'stats' => [
                'today_total' => (float) $todayStats->t,
                'today_count' => (int) $todayStats->c,
                'today_margin' => $this->margin($ctx, $today, $scope)['margin'],
                'month_total' => (float) $monthStats->t,
                'month_count' => (int) $monthStats->c,
                'month_ticket' => $monthStats->c ? round((float) $monthStats->t / $monthStats->c, 2) : 0,
                'month_gross' => $this->margin($ctx, $month, $scope)['gross'],
                'month_margin' => $this->margin($ctx, $month, $scope)['margin'],
                'month_vs_prev' => $prevMonthTotal > 0 ? round(((float) $monthStats->t - $prevMonthTotal) / $prevMonthTotal * 100, 1) : null,
                'low_stock' => $lowStock,
                'over_stock' => \App\Support\StockAlerts::counts($branchId)['over'],
                'pending_production' => \App\Models\ProductionOrder::where('status', 'pending')->where('branch_id', $branchId)->count(),
                'outdated_prices' => Product::where('is_active', true)->whereRaw(PricingService::outdatedSql())->count(),
                'receivables' => (float) Customer::where('balance', '>', 0)->sum('balance'),
                'payables' => (float) Supplier::where('balance', '>', 0)->sum('balance'),
                'overdue_customers' => Customer::whereHas('movements', fn ($q) => $q->where('pending_amount', '>', 0)->where('due_date', '<', now()->toDateString()))->count(),
            ],
            'chart' => $chart,
            'topProducts' => $topProducts,
            'paymentMethods' => $byMethod,
            'openCash' => CashSession::where('branch_id', $branchId)->where('status', 'open')->with('cashRegister:id,name', 'user:id,name')->get(['id', 'cash_register_id', 'user_id', 'opened_at']),
            'pendingImports' => SupplierPriceImport::whereIn('status', ['uploaded', 'processed'])->with('supplier:id,name')->latest()->limit(5)->get(['id', 'supplier_id', 'file_name', 'status', 'matched_rows', 'avg_variation', 'created_at']),
            'incomingTransfers' => StockTransfer::where('to_branch_id', $branchId)->where('status', 'in_transit')->with('fromBranch:id,name')->get(['id', 'number', 'from_branch_id', 'created_at']),
            'recentPriceUpdates' => \App\Models\PriceUpdate::latest()->limit(5)->get(['id', 'description', 'source', 'products_count', 'avg_price_variation', 'status', 'created_at']),
        ]);
    }

    /** Utilidad bruta (neto sin IVA - costo) y margen % desde una fecha */
    private function margin(TenantContext $ctx, $from, string $scope): array
    {
        static $cache = [];
        $key = $from->timestamp.$scope;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $r = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $ctx->id())->where('sales.status', 'completed')->where('sales.type', 'sale')
            ->where('sales.created_at', '>=', $from)
            ->when($scope === 'branch', fn ($q) => $q->where('sales.branch_id', $ctx->branchId()))
            ->selectRaw('COALESCE(SUM((sale_items.quantity - sale_items.returned_quantity) / sale_items.quantity * sale_items.subtotal * (1 - sales.discount_percent / 100) / (1 + sale_items.vat_rate / 100)),0) net')
            ->selectRaw('COALESCE(SUM((sale_items.quantity - sale_items.returned_quantity) * sale_items.unit_cost),0) cost')
            ->first();
        $net = (float) $r->net;
        $gross = round($net - (float) $r->cost, 2);

        return $cache[$key] = ['gross' => $gross, 'margin' => $net > 0 ? round($gross / $net * 100, 1) : 0];
    }
}
