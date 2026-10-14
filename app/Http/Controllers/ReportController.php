<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Reportes gerenciales. Todos los importes de rentabilidad se calculan netos de IVA y de devoluciones.
 */
class ReportController extends Controller
{
    private const NET = 'SUM((si.quantity - si.returned_quantity) / si.quantity * si.subtotal * (1 - s.discount_percent / 100) / (1 + si.vat_rate / 100))';

    private const COST = 'SUM((si.quantity - si.returned_quantity) * si.unit_cost)';

    public function __construct(private TenantContext $ctx) {}

    public function index()
    {
        return Inertia::render('Reports/Index');
    }

    private function period(Request $request): array
    {
        return [
            $request->input('from', now()->startOfMonth()->toDateString()),
            $request->input('to', now()->toDateString()),
            $request->input('branch_id', 'all'),
        ];
    }

    private function salesBase(string $from, string $to, $branch)
    {
        return DB::table('sales as s')
            ->where('s.tenant_id', $this->ctx->id())->where('s.status', 'completed')->where('s.type', 'sale')
            ->whereBetween('s.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branch !== 'all', fn ($q) => $q->where('s.branch_id', $branch));
    }

    private function itemsBase(string $from, string $to, $branch)
    {
        return $this->salesBase($from, $to, $branch)->join('sale_items as si', 'si.sale_id', '=', 's.id');
    }

    private function common(Request $request): array
    {
        [$from, $to, $branch] = $this->period($request);

        return ['filters' => array_merge($request->all(), ['from' => $from, 'to' => $to, 'branch_id' => $branch]), 'branches' => Branch::orderBy('name')->get(['id', 'name'])];
    }

    public function sales(Request $request)
    {
        [$from, $to, $branch] = $this->period($request);
        $group = $request->input('group', 'day');

        $q = $this->salesBase($from, $to, $branch);
        $rows = match ($group) {
            'user' => $q->join('users as u', 'u.id', '=', 's.user_id')->selectRaw('u.name label, COUNT(*) count, SUM(s.total - s.returned_amount) total')->groupBy('u.name')->orderByDesc('total')->get(),
            'branch' => $q->join('branches as b', 'b.id', '=', 's.branch_id')->selectRaw('b.name label, COUNT(*) count, SUM(s.total - s.returned_amount) total')->groupBy('b.name')->orderByDesc('total')->get(),
            'method' => $q->join('sale_payments as p', 'p.sale_id', '=', 's.id')->selectRaw('p.method label, COUNT(DISTINCT s.id) count, SUM(p.amount) total')->groupBy('p.method')->orderByDesc('total')->get(),
            'hour' => $q->selectRaw('LPAD(HOUR(s.created_at),2,"0") label, COUNT(*) count, SUM(s.total) total')->groupBy('label')->orderBy('label')->get(),
            'month' => $q->selectRaw('DATE_FORMAT(s.created_at, "%Y-%m") label, COUNT(*) count, SUM(s.total - s.returned_amount) total')->groupBy('label')->orderBy('label')->get(),
            default => $q->selectRaw('DATE(s.created_at) label, COUNT(*) count, SUM(s.total - s.returned_amount) total')->groupBy('label')->orderBy('label')->get(),
        };

        $top = $this->itemsBase($from, $to, $branch)
            ->selectRaw('si.product_id, si.code, si.description, SUM(si.quantity - si.returned_quantity) qty, SUM(si.subtotal) total')
            ->groupBy('si.product_id', 'si.code', 'si.description')->orderByDesc($request->input('top_by', 'total'))->limit(30)->get();

        $totals = $this->salesBase($from, $to, $branch)->selectRaw('COUNT(*) count, COALESCE(SUM(total),0) total, COALESCE(SUM(returned_amount),0) returned, COALESCE(SUM(discount_amount),0) discounts')->first();

        return Inertia::render('Reports/Sales', $this->common($request) + compact('rows', 'top', 'totals', 'group'));
    }

    public function profitability(Request $request)
    {
        [$from, $to, $branch] = $this->period($request);
        $by = $request->input('by', 'category');
        $q = $this->itemsBase($from, $to, $branch)->leftJoin('products as p', 'p.id', '=', 'si.product_id');

        $q = match ($by) {
            'product' => $q->selectRaw('si.code, si.description label')->groupBy('si.code', 'si.description'),
            'brand' => $q->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')->selectRaw('COALESCE(b.name, "Sin marca") label')->groupBy('label'),
            'supplier' => $q->leftJoin('suppliers as sup', 'sup.id', '=', 'p.supplier_id')->selectRaw('COALESCE(sup.name, "Sin proveedor") label')->groupBy('label'),
            'seller' => $q->join('users as u', 'u.id', '=', 's.user_id')->selectRaw('u.name label')->groupBy('u.name'),
            default => $q->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->selectRaw('COALESCE(c.name, "Sin categoría") label')->groupBy('label'),
        };
        $rows = $q->selectRaw(self::NET.' net, '.self::COST.' cost, SUM(si.quantity - si.returned_quantity) qty')
            ->orderByDesc('net')->limit(300)->get()
            ->map(function ($r) {
                $r->gross = round((float) $r->net - (float) $r->cost, 2);
                $r->margin = (float) $r->net > 0 ? round($r->gross / (float) $r->net * 100, 1) : 0;

                return $r;
            });

        return Inertia::render('Reports/Profitability', $this->common($request) + ['rows' => $rows, 'by' => $by]);
    }

    public function valuation(Request $request)
    {
        $branch = $request->input('branch_id', 'all');
        $rows = DB::table('product_stocks as ps')->join('products as p', 'p.id', '=', 'ps.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('ps.tenant_id', $this->ctx->id())->whereNull('p.deleted_at')->where('ps.quantity', '>', 0)
            ->when($branch !== 'all', fn ($q) => $q->where('ps.branch_id', $branch))
            ->selectRaw('COALESCE(c.name, "Sin categoría") label, COUNT(DISTINCT p.id) products, SUM(ps.quantity) qty,
                SUM(ps.quantity * p.cost) at_cost, SUM(ps.quantity * p.average_cost) at_avg, SUM(ps.quantity * p.price) at_price')
            ->groupBy('label')->orderByDesc('at_cost')->get();
        $byBranch = DB::table('product_stocks as ps')->join('products as p', 'p.id', '=', 'ps.product_id')->join('branches as b', 'b.id', '=', 'ps.branch_id')
            ->where('ps.tenant_id', $this->ctx->id())->whereNull('p.deleted_at')->where('ps.quantity', '>', 0)
            ->selectRaw('b.name label, SUM(ps.quantity * p.cost) at_cost, SUM(ps.quantity * p.price) at_price')->groupBy('b.name')->get();

        return Inertia::render('Reports/Valuation', [
            'rows' => $rows, 'byBranch' => $byBranch,
            'filters' => ['branch_id' => $branch], 'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function noRotation(Request $request)
    {
        $days = max(15, (int) $request->input('days', 90));
        $since = now()->subDays($days);
        $base = DB::table('products as p')
            ->join('product_stocks as ps', 'ps.product_id', '=', 'p.id')
            ->where('p.tenant_id', $this->ctx->id())->whereNull('p.deleted_at')->where('p.is_active', true)
            ->where('ps.quantity', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('stock_movements as m')->whereColumn('m.product_id', 'p.id')->where('m.type', 'sale')->where('m.created_at', '>=', $since));

        $total = (float) (clone $base)->sum(DB::raw('ps.quantity * p.cost'));
        $rows = $base->selectRaw('p.id, p.code, p.name, p.cost, SUM(ps.quantity) qty, SUM(ps.quantity) * p.cost immobilized,
                (SELECT MAX(m2.created_at) FROM stock_movements m2 WHERE m2.product_id = p.id AND m2.type = "sale") last_sale')
            ->groupBy('p.id', 'p.code', 'p.name', 'p.cost')
            ->orderByDesc('immobilized')->paginate(50)->withQueryString();

        return Inertia::render('Reports/NoRotation', ['rows' => $rows, 'filters' => ['days' => $days], 'total' => $total]);
    }

    public function lowMargin(Request $request)
    {
        $threshold = (float) $request->input('threshold', 20);
        $rows = DB::table('products as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.tenant_id', $this->ctx->id())->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.price', '>', 0)
            ->whereRaw('(p.price / (1 + p.vat_rate / 100) - p.cost) / (p.price / (1 + p.vat_rate / 100)) * 100 < ?', [$threshold])
            ->selectRaw('p.id, p.code, p.name, p.cost, p.price, p.markup, s.name supplier,
                ROUND((p.price / (1 + p.vat_rate / 100) - p.cost) / (p.price / (1 + p.vat_rate / 100)) * 100, 1) margin')
            ->orderBy('margin')->paginate(50)->withQueryString();

        return Inertia::render('Reports/LowMargin', ['rows' => $rows, 'filters' => ['threshold' => $threshold]]);
    }

    public function accounts()
    {
        $today = now()->toDateString();
        $aging = DB::table('customer_movements as m')->join('customers as c', 'c.id', '=', 'm.customer_id')
            ->where('m.tenant_id', $this->ctx->id())->where('m.pending_amount', '>', 0)->whereNull('c.deleted_at')
            ->selectRaw("c.id, c.name, c.phone, c.balance,
                SUM(CASE WHEN m.due_date >= ? THEN m.pending_amount ELSE 0 END) not_due,
                SUM(CASE WHEN m.due_date < ? AND DATEDIFF(?, m.due_date) <= 30 THEN m.pending_amount ELSE 0 END) d30,
                SUM(CASE WHEN DATEDIFF(?, m.due_date) BETWEEN 31 AND 60 THEN m.pending_amount ELSE 0 END) d60,
                SUM(CASE WHEN DATEDIFF(?, m.due_date) BETWEEN 61 AND 90 THEN m.pending_amount ELSE 0 END) d90,
                SUM(CASE WHEN DATEDIFF(?, m.due_date) > 90 THEN m.pending_amount ELSE 0 END) d90plus", [$today, $today, $today, $today, $today, $today])
            ->groupBy('c.id', 'c.name', 'c.phone', 'c.balance')->orderByDesc('c.balance')->get();

        return Inertia::render('Reports/Accounts', [
            'customers' => $aging,
            'suppliers' => Supplier::where('balance', '!=', 0)->orderByDesc('balance')->get(['id', 'name', 'phone', 'balance', 'payment_term_days']),
            'totals' => [
                'receivable' => (float) Customer::where('balance', '>', 0)->sum('balance'),
                'payable' => (float) Supplier::where('balance', '>', 0)->sum('balance'),
            ],
        ]);
    }

    /** Estado de resultados simplificado por mes */
    public function results(Request $request)
    {
        $months = max(1, min(24, (int) $request->input('months', 6)));
        $branch = $request->input('branch_id', 'all');
        $from = now()->subMonths($months - 1)->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $sales = $this->itemsBase($from, $to, $branch)
            ->selectRaw('DATE_FORMAT(s.created_at, "%Y-%m") ym, '.self::NET.' net, '.self::COST.' cost')
            ->groupBy('ym')->get()->keyBy('ym');
        $expenses = Expense::whereBetween('date', [$from, $to])->when($branch !== 'all', fn ($q) => $q->where('branch_id', $branch))
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") ym, expense_category_id, SUM(amount) total')->groupBy('ym', 'expense_category_id')->get();
        $categories = \App\Models\ExpenseCategory::pluck('name', 'id');

        $rows = collect(range($months - 1, 0))->map(function ($i) use ($sales, $expenses, $categories) {
            $ym = now()->subMonths($i)->format('Y-m');
            $net = (float) ($sales[$ym]->net ?? 0);
            $cost = (float) ($sales[$ym]->cost ?? 0);
            $exp = $expenses->where('ym', $ym);
            $expTotal = (float) $exp->sum('total');

            return [
                'month' => $ym, 'net_sales' => round($net, 2), 'cogs' => round($cost, 2), 'gross' => round($net - $cost, 2),
                'gross_margin' => $net > 0 ? round(($net - $cost) / $net * 100, 1) : 0,
                'expenses' => round($expTotal, 2),
                'expenses_detail' => $exp->map(fn ($e) => ['category' => $categories[$e->expense_category_id] ?? 'Sin categoría', 'total' => (float) $e->total])->values(),
                'result' => round($net - $cost - $expTotal, 2),
            ];
        });

        return Inertia::render('Reports/Results', [
            'rows' => $rows,
            'filters' => ['months' => $months, 'branch_id' => $branch],
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
