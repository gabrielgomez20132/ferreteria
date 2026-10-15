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

    // ======================= Clientes =======================

    /** Datos del reporte de clientes (se usa en pantalla y en la exportación) */
    private function customersData(Request $request): array
    {
        [$from, $to, $branch] = $this->period($request);
        $tid = $this->ctx->id();
        $today = now()->toDateString();
        $inactiveDays = max(15, min(730, (int) $request->input('inactive_days', 60)));

        // Por venta: cantidad, total, fechas
        $bySale = $this->salesBase($from, $to, $branch)->whereNotNull('s.customer_id')
            ->selectRaw('s.customer_id, COUNT(*) count, SUM(s.total - s.returned_amount) total, MIN(s.created_at) first_at, MAX(s.created_at) last_at')
            ->groupBy('s.customer_id')->get()->keyBy('customer_id');
        // Por ítem: venta neta sin IVA y costo (para la ganancia)
        $byItem = $this->itemsBase($from, $to, $branch)->whereNotNull('s.customer_id')
            ->selectRaw('s.customer_id, '.self::NET.' net, '.self::COST.' cost')
            ->groupBy('s.customer_id')->get()->keyBy('customer_id');
        $overdue = DB::table('customer_movements')->where('tenant_id', $tid)->where('pending_amount', '>', 0)->where('due_date', '<', $today)
            ->selectRaw('customer_id, SUM(pending_amount) amount')->groupBy('customer_id')->pluck('amount', 'customer_id');
        $customers = Customer::withTrashed()->whereIn('id', $bySale->keys())->get(['id', 'name', 'phone', 'balance', 'created_at', 'deleted_at'])->keyBy('id');
        // Primera compra histórica, para saber cuáles son clientes nuevos en el período
        $firstEver = DB::table('sales')->where('tenant_id', $tid)->where('type', 'sale')->where('status', 'completed')
            ->whereIn('customer_id', $bySale->keys())->selectRaw('customer_id, MIN(created_at) first_at')->groupBy('customer_id')->pluck('first_at', 'customer_id');

        $rows = $bySale->map(function ($r) use ($byItem, $overdue, $customers, $firstEver, $from) {
            $c = $customers[$r->customer_id] ?? null;
            $net = (float) ($byItem[$r->customer_id]->net ?? 0);
            $cost = (float) ($byItem[$r->customer_id]->cost ?? 0);
            $count = (int) $r->count;
            $days = $count > 1 ? round((strtotime($r->last_at) - strtotime($r->first_at)) / 86400 / ($count - 1), 1) : null;

            return [
                'id' => (int) $r->customer_id,
                'name' => $c?->name ?? 'Cliente eliminado',
                'phone' => $c?->phone,
                'count' => $count,
                'total' => round((float) $r->total, 2),
                'avg_ticket' => $count ? round((float) $r->total / $count, 2) : 0,
                'net' => round($net, 2),
                'gross' => round($net - $cost, 2),
                'margin' => $net > 0 ? round(($net - $cost) / $net * 100, 1) : 0,
                'every_days' => $days,
                'last_at' => $r->last_at,
                'is_new' => isset($firstEver[$r->customer_id]) && substr($firstEver[$r->customer_id], 0, 10) >= $from,
                'balance' => round((float) ($c?->balance ?? 0), 2),
                'overdue' => round((float) ($overdue[$r->customer_id] ?? 0), 2),
            ];
        })->values();

        // Clasificación ABC: A = clientes que suman el 80 % de la venta, B = hasta el 95 %, C = el resto
        $sumTotal = (float) $rows->sum('total');
        $acc = 0;
        $rows = $rows->sortByDesc('total')->values()->map(function ($r) use (&$acc, $sumTotal) {
            $acc += $r['total'];
            $share = $sumTotal > 0 ? $acc / $sumTotal * 100 : 0;
            $r['share'] = $sumTotal > 0 ? round($r['total'] / $sumTotal * 100, 1) : 0;
            $r['abc'] = $share - $r['share'] < 80 ? 'A' : ($share - $r['share'] < 95 ? 'B' : 'C');

            return $r;
        });

        $all = $this->salesBase($from, $to, $branch)->selectRaw('COUNT(*) count, COALESCE(SUM(s.total - s.returned_amount),0) total')->first();
        $identifiedTotal = $sumTotal;
        $identifiedCount = (int) $rows->sum('count');

        // Clientes que compraban y dejaron de venir (última compra hace más de N días)
        $limitDate = now()->subDays($inactiveDays)->toDateString();
        $inactive = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.tenant_id', $tid)->where('s.type', 'sale')->where('s.status', 'completed')->whereNull('c.deleted_at')
            ->when($branch !== 'all', fn ($q) => $q->where('s.branch_id', $branch))
            ->selectRaw('c.id, c.name, c.phone, c.balance, COUNT(*) count, SUM(s.total - s.returned_amount) total, MAX(s.created_at) last_at')
            ->groupBy('c.id', 'c.name', 'c.phone', 'c.balance')
            ->havingRaw('MAX(s.created_at) < ?', [$limitDate.' 00:00:00'])
            ->orderByDesc('total')->limit(200)->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'balance' => (float) $r->balance,
                'count' => (int) $r->count, 'total' => round((float) $r->total, 2), 'last_at' => $r->last_at,
                'days' => (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($r->last_at)->startOfDay(), true),
            ]);

        return [
            'from' => $from, 'to' => $to, 'branch' => $branch, 'inactiveDays' => $inactiveDays,
            'rows' => $rows,
            'inactive' => $inactive,
            'kpis' => [
                'customers' => $rows->count(),
                'new' => $rows->where('is_new', true)->count(),
                'identified_total' => round($identifiedTotal, 2),
                'identified_share' => (float) $all->total > 0 ? round($identifiedTotal / (float) $all->total * 100, 1) : 0,
                'anonymous_total' => round((float) $all->total - $identifiedTotal, 2),
                'anonymous_count' => (int) $all->count - $identifiedCount,
                'avg_ticket' => $identifiedCount ? round($identifiedTotal / $identifiedCount, 2) : 0,
                'gross' => round((float) $rows->sum('gross'), 2),
                'a_count' => $rows->where('abc', 'A')->count(),
                'receivable' => (float) Customer::where('balance', '>', 0)->sum('balance'),
                'overdue' => round((float) DB::table('customer_movements')->where('tenant_id', $tid)->where('pending_amount', '>', 0)->where('due_date', '<', $today)->sum('pending_amount'), 2),
                'inactive' => $inactive->count(),
                'inactive_total' => round((float) $inactive->sum('total'), 2),
            ],
        ];
    }

    public function customers(Request $request)
    {
        $data = $this->customersData($request);
        $sort = in_array($request->input('sort'), ['total', 'gross', 'count', 'avg_ticket', 'last_at', 'balance'], true) ? $request->input('sort') : 'total';
        $rows = $data['rows']->sortByDesc($sort)->values();
        $customerId = (int) $request->input('customer_id');

        $common = $this->common($request);
        unset($common['filters']['customer_id']);
        $common['filters']['inactive_days'] = $data['inactiveDays'];

        return Inertia::render('Reports/Customers', $common + [
            'rows' => $rows,
            'inactive' => $data['inactive'],
            'kpis' => $data['kpis'],
            'sort' => $sort,
            'inactiveDays' => $data['inactiveDays'],
            // Qué compra un cliente (se carga al abrir el detalle)
            'detail' => $customerId ? $this->customerDetail($customerId, $data['from'], $data['to'], $data['branch']) : null,
        ]);
    }

    private function customerDetail(int $customerId, string $from, string $to, $branch): ?array
    {
        $customer = Customer::withTrashed()->find($customerId, ['id', 'name', 'phone', 'balance', 'credit_limit', 'allow_credit']);
        if (! $customer) {
            return null;
        }
        $base = fn () => $this->itemsBase($from, $to, $branch)->where('s.customer_id', $customerId);
        $categories = $base()->leftJoin('products as p', 'p.id', '=', 'si.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->selectRaw('COALESCE(c.name, "Sin categoría") label, '.self::NET.' net, '.self::COST.' cost')
            ->groupBy('label')->orderByDesc('net')->limit(10)->get()
            ->map(fn ($r) => ['label' => $r->label, 'net' => round((float) $r->net, 2), 'gross' => round((float) $r->net - (float) $r->cost, 2)]);
        $products = $base()->selectRaw('si.code, si.description, SUM(si.quantity - si.returned_quantity) qty, SUM(si.subtotal) total')
            ->groupBy('si.code', 'si.description')->orderByDesc('total')->limit(15)->get();
        $months = $this->salesBase(now()->subMonths(11)->startOfMonth()->toDateString(), now()->toDateString(), $branch)->where('s.customer_id', $customerId)
            ->selectRaw('DATE_FORMAT(s.created_at, "%Y-%m") ym, COUNT(*) count, SUM(s.total - s.returned_amount) total')->groupBy('ym')->pluck('total', 'ym');

        return [
            'customer' => $customer,
            'categories' => $categories,
            'products' => $products,
            'months' => collect(range(11, 0))->map(fn ($i) => ['ym' => now()->subMonths($i)->format('Y-m'), 'total' => round((float) ($months[now()->subMonths($i)->format('Y-m')] ?? 0), 2)]),
        ];
    }

    public function customersExport(Request $request)
    {
        $data = $this->customersData($request);
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;

        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Ranking');
        $ws->fromArray(['Clientes del '.date('d/m/Y', strtotime($data['from'])).' al '.date('d/m/Y', strtotime($data['to']))], null, 'A1');
        $ws->fromArray(['Cliente', 'Teléfono', 'Clase', 'Compras', 'Total', '% del total', 'Ticket promedio', 'Venta neta s/IVA', 'Ganancia bruta', 'Margen %', 'Compra cada (días)', 'Última compra', 'Nuevo', 'Saldo cta. cte.', 'Vencido'], null, 'A3');
        $r = 4;
        foreach ($data['rows'] as $x) {
            $ws->fromArray([$x['name'], $x['phone'], $x['abc'], $x['count'], $x['total'], $x['share'], $x['avg_ticket'], $x['net'], $x['gross'], $x['margin'], $x['every_days'], date('d/m/Y', strtotime($x['last_at'])), $x['is_new'] ? 'Sí' : '', $x['balance'], $x['overdue']], null, 'A'.$r++);
        }
        $ws->getStyle('A1:O3')->getFont()->setBold(true);
        foreach (['E', 'G', 'H', 'I', 'N', 'O'] as $c) {
            $ws->getStyle("{$c}4:{$c}{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range('A', 'O') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }

        $ws2 = $sheet->createSheet();
        $ws2->setTitle('Inactivos');
        $ws2->fromArray(["Clientes sin compras en los últimos {$data['inactiveDays']} días"], null, 'A1');
        $ws2->fromArray(['Cliente', 'Teléfono', 'Última compra', 'Días sin comprar', 'Compras históricas', 'Total histórico', 'Saldo cta. cte.'], null, 'A3');
        $r = 4;
        foreach ($data['inactive'] as $x) {
            $ws2->fromArray([$x['name'], $x['phone'], date('d/m/Y', strtotime($x['last_at'])), $x['days'], $x['count'], $x['total'], $x['balance']], null, 'A'.$r++);
        }
        $ws2->getStyle('A1:G3')->getFont()->setBold(true);
        foreach (range('A', 'G') as $c) {
            $ws2->getColumnDimension($c)->setAutoSize(true);
        }

        $sheet->setActiveSheetIndex(0);
        $path = storage_path('app/private/clientes-'.now()->format('YmdHis').'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        return response()->download($path, 'reporte-clientes-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    // ======================= Compras =======================

    private function purchasesBase(string $from, string $to, $branch)
    {
        return DB::table('purchases as p')
            ->where('p.tenant_id', $this->ctx->id())->where('p.status', 'confirmed')
            ->whereBetween('p.date', [$from, $to])
            ->when($branch !== 'all', fn ($q) => $q->where('p.branch_id', $branch));
    }

    private function purchasesData(Request $request): array
    {
        [$from, $to, $branch] = $this->period($request);
        $tid = $this->ctx->id();
        $today = now()->toDateString();
        $by = in_array($request->input('by'), ['supplier', 'category', 'brand', 'month', 'product'], true) ? $request->input('by') : 'supplier';

        $tot = $this->purchasesBase($from, $to, $branch)
            ->selectRaw('COUNT(*) count, COALESCE(SUM(total),0) total, COALESCE(SUM(subtotal),0) net, COALESCE(SUM(vat_amount),0) vat, COALESCE(SUM(other_taxes),0) taxes, COALESCE(SUM(extra_costs),0) extra, COUNT(DISTINCT supplier_id) suppliers')->first();
        // Costo de lo vendido en el mismo período: si compro más de lo que vendo, el stock crece
        $cogs = (float) $this->itemsBase($from, $to, $branch)->selectRaw(self::COST.' cost')->value('cost');

        $items = fn () => $this->purchasesBase($from, $to, $branch)->join('purchase_items as pi', 'pi.purchase_id', '=', 'p.id')
            ->leftJoin('products as pr', 'pr.id', '=', 'pi.product_id');
        $q = match ($by) {
            'category' => $items()->leftJoin('categories as c', 'c.id', '=', 'pr.category_id')->selectRaw('COALESCE(c.name, "Sin categoría") label')->groupBy('label'),
            'brand' => $items()->leftJoin('brands as b', 'b.id', '=', 'pr.brand_id')->selectRaw('COALESCE(b.name, "Sin marca") label')->groupBy('label'),
            'month' => $items()->selectRaw('DATE_FORMAT(p.date, "%Y-%m") label')->groupBy('label'),
            'product' => $items()->selectRaw('pr.code, COALESCE(pr.name, pi.description) label')->groupBy('pr.code', 'label'),
            default => $items()->join('suppliers as s', 's.id', '=', 'p.supplier_id')->selectRaw('s.id supplier_id, s.name label')->groupBy('s.id', 's.name'),
        };
        $rows = $q->selectRaw('COUNT(DISTINCT p.id) invoices, SUM(pi.stock_quantity) qty, SUM(pi.subtotal) amount')
            ->when($by === 'month', fn ($x) => $x->orderBy('label'), fn ($x) => $x->orderByDesc('amount'))
            ->limit(300)->get();
        $sumAmount = (float) $rows->sum('amount');
        $rows = $rows->map(fn ($r) => (array) $r + ['share' => $sumAmount > 0 ? round($r->amount / $sumAmount * 100, 1) : 0]);

        // Variación de costo de cada producto dentro del período (primera vs última compra)
        $variation = $items()->where('pi.stock_quantity', '>', 0)
            ->selectRaw('pi.product_id, pr.code, COALESCE(pr.name, pi.description) name, p.date, pi.id, pi.subtotal / pi.stock_quantity unit_cost, pi.stock_quantity qty')
            ->orderBy('p.date')->orderBy('pi.id')->get()->groupBy('product_id')
            ->map(function ($g) {
                $first = (float) $g->first()->unit_cost;
                $last = (float) $g->last()->unit_cost;

                return [
                    'product_id' => $g->first()->product_id, 'code' => $g->first()->code, 'name' => $g->first()->name,
                    'purchases' => $g->count(), 'qty' => round((float) $g->sum('qty'), 3),
                    'first_cost' => round($first, 2), 'last_cost' => round($last, 2),
                    'first_date' => $g->first()->date, 'last_date' => $g->last()->date,
                    'variation' => $first > 0 ? round(($last - $first) / $first * 100, 1) : null,
                ];
            })
            ->filter(fn ($r) => $r['purchases'] > 1 && $r['variation'] !== null && abs($r['variation']) >= 0.1)
            ->sortByDesc(fn ($r) => abs($r['variation']))->take(50)->values();

        // Facturas impagas (todas, no solo las del período)
        $pending = DB::table('purchases as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.tenant_id', $tid)->where('p.status', 'confirmed')->whereRaw('p.total - p.paid_amount > 0.009')
            ->when($branch !== 'all', fn ($q) => $q->where('p.branch_id', $branch))
            ->selectRaw('p.id, p.supplier_id, s.name supplier, p.voucher_type, p.voucher_number, p.date, p.due_date, p.total, p.paid_amount, p.total - p.paid_amount pending')
            ->orderByRaw('p.due_date IS NULL, p.due_date')->orderBy('p.date')->limit(200)->get()
            ->map(fn ($r) => (array) $r + ['days_overdue' => $r->due_date && $r->due_date < $today ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($r->due_date), true) : 0]);

        // Últimos 12 meses: compras vs costo de lo vendido
        $from12 = now()->subMonths(11)->startOfMonth()->toDateString();
        $buy12 = $this->purchasesBase($from12, $today, $branch)->selectRaw('DATE_FORMAT(date, "%Y-%m") ym, SUM(total) t')->groupBy('ym')->pluck('t', 'ym');
        $cogs12 = $this->itemsBase($from12, $today, $branch)->selectRaw('DATE_FORMAT(s.created_at, "%Y-%m") ym, '.self::COST.' c')->groupBy('ym')->pluck('c', 'ym');
        $months = collect(range(11, 0))->map(function ($i) use ($buy12, $cogs12) {
            $ym = now()->subMonths($i)->format('Y-m');

            return ['ym' => $ym, 'purchases' => round((float) ($buy12[$ym] ?? 0), 2), 'cogs' => round((float) ($cogs12[$ym] ?? 0), 2)];
        });

        return [
            'from' => $from, 'to' => $to, 'by' => $by,
            'rows' => $rows, 'variation' => $variation, 'pending' => $pending, 'months' => $months,
            'kpis' => [
                'total' => round((float) $tot->total, 2), 'count' => (int) $tot->count, 'avg' => $tot->count ? round($tot->total / $tot->count, 2) : 0,
                'net' => round((float) $tot->net, 2), 'vat' => round((float) $tot->vat, 2), 'taxes' => round((float) $tot->taxes, 2), 'extra' => round((float) $tot->extra, 2),
                'suppliers' => (int) $tot->suppliers, 'cogs' => round($cogs, 2),
                'cover' => $cogs > 0 ? round((float) $tot->net / $cogs * 100, 1) : null,
                'to_pay' => round((float) $pending->sum('pending'), 2),
                'overdue' => round((float) $pending->where('days_overdue', '>', 0)->sum('pending'), 2),
                'cost_up' => $variation->where('variation', '>', 0)->count(),
                'cost_down' => $variation->where('variation', '<', 0)->count(),
            ],
        ];
    }

    public function purchases(Request $request)
    {
        $d = $this->purchasesData($request);

        return Inertia::render('Reports/Purchases', $this->common($request) + [
            'rows' => $d['rows'], 'variation' => $d['variation'], 'pending' => $d['pending'], 'months' => $d['months'], 'kpis' => $d['kpis'], 'by' => $d['by'],
        ]);
    }

    public function purchasesExport(Request $request)
    {
        $d = $this->purchasesData($request);
        $labels = ['supplier' => 'Proveedor', 'category' => 'Rubro', 'brand' => 'Marca', 'month' => 'Mes', 'product' => 'Producto'];
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $this->xlsSheet($sheet->getActiveSheet(), 'Compras', 'Compras del '.date('d/m/Y', strtotime($d['from'])).' al '.date('d/m/Y', strtotime($d['to'])).' por '.strtolower($labels[$d['by']]),
            [$labels[$d['by']], 'Facturas', 'Cantidad (u. stock)', 'Importe neto', '% del total'],
            $d['rows']->map(fn ($r) => [$r['label'], $r['invoices'], (float) $r['qty'], (float) $r['amount'], $r['share']]), ['D']);
        $this->xlsSheet($sheet->createSheet(), 'Variación de costos', 'Variación del costo unitario entre la primera y la última compra del período',
            ['Código', 'Producto', 'Compras', 'Primera compra', 'Costo inicial', 'Última compra', 'Costo final', 'Variación %'],
            $d['variation']->map(fn ($r) => [$r['code'], $r['name'], $r['purchases'], date('d/m/Y', strtotime($r['first_date'])), $r['first_cost'], date('d/m/Y', strtotime($r['last_date'])), $r['last_cost'], $r['variation']]), ['E', 'G']);
        $this->xlsSheet($sheet->createSheet(), 'Facturas impagas', 'Facturas de compra pendientes de pago al '.now()->format('d/m/Y'),
            ['Proveedor', 'Comprobante', 'Fecha', 'Vencimiento', 'Total', 'Pagado', 'Pendiente', 'Días vencida'],
            $d['pending']->map(fn ($r) => [$r['supplier'], trim($r['voucher_type'].' '.$r['voucher_number']), date('d/m/Y', strtotime($r['date'])), $r['due_date'] ? date('d/m/Y', strtotime($r['due_date'])) : '', (float) $r['total'], (float) $r['paid_amount'], (float) $r['pending'], $r['days_overdue'] ?: '']), ['E', 'F', 'G']);

        return $this->xlsDownload($sheet, 'reporte-compras');
    }

    // ======================= Presupuestos =======================

    private function quotesData(Request $request): array
    {
        [$from, $to, $branch] = $this->period($request);
        $tid = $this->ctx->id();
        $today = now()->toDateString();
        $userId = $request->input('user_id');

        $base = fn () => DB::table('sales as q')->where('q.tenant_id', $tid)->where('q.type', 'quote')
            ->whereBetween('q.created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($branch !== 'all', fn ($x) => $x->where('q.branch_id', $branch))
            ->when($userId, fn ($x) => $x->where('q.user_id', $userId));
        $stateSql = "CASE WHEN q.status = 'converted' THEN 'converted' WHEN q.status = 'rejected' THEN 'rejected' WHEN q.valid_until < '{$today}' THEN 'expired' ELSE 'pending' END";
        $agg = "COUNT(*) count, COALESCE(SUM(q.total),0) amount,
            SUM(q.status = 'converted') conv, COALESCE(SUM(CASE WHEN q.status = 'converted' THEN q.total ELSE 0 END),0) conv_amount,
            COALESCE(SUM(CASE WHEN q.status = 'converted' THEN cs.total - cs.returned_amount ELSE 0 END),0) sold,
            SUM(q.status = 'rejected') rejected, COALESCE(SUM(CASE WHEN q.status = 'rejected' THEN q.total ELSE 0 END),0) rejected_amount,
            SUM(q.status = 'quote' AND q.valid_until < '{$today}') expired, COALESCE(SUM(CASE WHEN q.status = 'quote' AND q.valid_until < '{$today}' THEN q.total ELSE 0 END),0) expired_amount,
            SUM(q.status = 'quote' AND (q.valid_until IS NULL OR q.valid_until >= '{$today}')) pending, COALESCE(SUM(CASE WHEN q.status = 'quote' AND (q.valid_until IS NULL OR q.valid_until >= '{$today}') THEN q.total ELSE 0 END),0) pending_amount,
            AVG(CASE WHEN q.status = 'converted' THEN TIMESTAMPDIFF(HOUR, q.created_at, q.converted_at) / 24 END) days_to_convert";
        $withSale = fn ($x) => $x->leftJoin('sales as cs', 'cs.id', '=', 'q.converted_sale_id');
        $rate = fn ($r) => array_merge($this->numeric((array) $r), [
            'rate_all' => $r->count > 0 ? round($r->conv / $r->count * 100, 1) : null,
            'rate' => $r->count - $r->pending > 0 ? round($r->conv / ($r->count - $r->pending) * 100, 1) : null, // sobre los ya resueltos
            'rate_amount' => (float) $r->amount - (float) $r->pending_amount > 0 ? round($r->conv_amount / ($r->amount - $r->pending_amount) * 100, 1) : null,
        ]);

        $tot = $rate($withSale($base())->selectRaw($agg)->first());

        $bySeller = $withSale($base())->join('users as u', 'u.id', '=', 'q.user_id')
            ->selectRaw('u.id, u.name, '.$agg)->groupBy('u.id', 'u.name')->orderByDesc('amount')->get()->map($rate);

        $byCustomer = $withSale($base())->leftJoin('customers as c', 'c.id', '=', 'q.customer_id')
            ->selectRaw('c.id, COALESCE(c.name, "Sin cliente") name, c.phone, '.$agg)->groupBy('c.id', 'name', 'c.phone')->orderByDesc('amount')->limit(30)->get()->map($rate);

        $reasons = $base()->where('q.status', 'rejected')
            ->selectRaw('TRIM(SUBSTRING_INDEX(COALESCE(q.reject_reason, "Sin motivo"), ":", 1)) reason, COUNT(*) count, SUM(q.total) amount')
            ->groupBy('reason')->orderByDesc('count')->get()->map(fn ($r) => $this->numeric((array) $r));

        // Productos: cuántas veces se presupuestan y cuántas se concretan
        $products = $base()->join('sale_items as si', 'si.sale_id', '=', 'q.id')
            ->selectRaw("si.product_id, si.code, si.description, COUNT(DISTINCT q.id) quotes,
                COUNT(DISTINCT CASE WHEN q.status = 'converted' THEN q.id END) converted,
                COUNT(DISTINCT CASE WHEN q.status = 'rejected' OR (q.status = 'quote' AND q.valid_until < '{$today}') THEN q.id END) lost,
                SUM(si.quantity) qty, SUM(si.subtotal) amount,
                COALESCE(SUM(CASE WHEN q.status = 'rejected' OR (q.status = 'quote' AND q.valid_until < '{$today}') THEN si.subtotal ELSE 0 END),0) lost_amount")
            ->groupBy('si.product_id', 'si.code', 'si.description')->orderByDesc('lost_amount')->limit(40)->get();

        // 12 meses: presupuestado vs convertido
        $from12 = now()->subMonths(11)->startOfMonth()->toDateString();
        $m = DB::table('sales as q')->where('q.tenant_id', $tid)->where('q.type', 'quote')->where('q.created_at', '>=', $from12)
            ->when($branch !== 'all', fn ($x) => $x->where('q.branch_id', $branch))
            ->when($userId, fn ($x) => $x->where('q.user_id', $userId))
            ->selectRaw("DATE_FORMAT(q.created_at, '%Y-%m') ym, COUNT(*) count, SUM(q.total) amount, SUM(CASE WHEN q.status = 'converted' THEN q.total ELSE 0 END) conv_amount, SUM(q.status = 'converted') conv")
            ->groupBy('ym')->get()->keyBy('ym');
        $months = collect(range(11, 0))->map(function ($i) use ($m) {
            $ym = now()->subMonths($i)->format('Y-m');
            $r = $m[$ym] ?? null;

            return ['ym' => $ym, 'count' => (int) ($r->count ?? 0), 'amount' => round((float) ($r->amount ?? 0), 2), 'conv_amount' => round((float) ($r->conv_amount ?? 0), 2), 'conv' => (int) ($r->conv ?? 0)];
        });

        // Para hacer seguimiento: vigentes que vencen en los próximos 7 días (de cualquier fecha)
        $followUp = DB::table('sales as q')->leftJoin('customers as c', 'c.id', '=', 'q.customer_id')->join('users as u', 'u.id', '=', 'q.user_id')
            ->where('q.tenant_id', $tid)->where('q.type', 'quote')->where('q.status', 'quote')
            ->whereBetween('q.valid_until', [$today, now()->addDays(7)->toDateString()])
            ->when($branch !== 'all', fn ($x) => $x->where('q.branch_id', $branch))
            ->when($userId, fn ($x) => $x->where('q.user_id', $userId))
            ->orderBy('q.valid_until')->limit(50)
            ->get(['q.id', 'q.number', 'q.total', 'q.valid_until', 'q.created_at', 'q.sent_at', 'q.viewed_at', 'c.name as customer', 'c.phone', 'u.name as seller']);

        $list = $withSale($base())->leftJoin('customers as c', 'c.id', '=', 'q.customer_id')->join('users as u', 'u.id', '=', 'q.user_id')
            ->selectRaw("q.number, q.created_at, q.valid_until, COALESCE(c.name, '') customer, u.name seller, q.total, {$stateSql} state, cs.number sale_number, q.reject_reason")
            ->orderBy('q.created_at')->get();

        return compact('from', 'to', 'tot', 'bySeller', 'byCustomer', 'reasons', 'products', 'months', 'followUp', 'list');
    }

    public function quotes(Request $request)
    {
        $d = $this->quotesData($request);

        return Inertia::render('Reports/Quotes', $this->common($request) + [
            'kpis' => $d['tot'], 'bySeller' => $d['bySeller'], 'byCustomer' => $d['byCustomer'], 'reasons' => $d['reasons'],
            'products' => $d['products'], 'months' => $d['months'], 'followUp' => $d['followUp'],
            'users' => \App\Models\User::where('tenant_id', $this->ctx->id())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function quotesExport(Request $request)
    {
        $d = $this->quotesData($request);
        $states = \App\Models\Sale::QUOTE_STATES;
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $title = 'del '.date('d/m/Y', strtotime($d['from'])).' al '.date('d/m/Y', strtotime($d['to']));
        $this->xlsSheet($sheet->getActiveSheet(), 'Presupuestos', "Presupuestos {$title}",
            ['N°', 'Fecha', 'Válido hasta', 'Cliente', 'Vendedor', 'Total', 'Estado', 'Venta', 'Motivo de rechazo'],
            $d['list']->map(fn ($r) => [$r->number, date('d/m/Y', strtotime($r->created_at)), $r->valid_until ? date('d/m/Y', strtotime($r->valid_until)) : '', $r->customer, $r->seller, (float) $r->total, $states[$r->state] ?? $r->state, $r->sale_number ? '#'.$r->sale_number : '', $r->reject_reason]), ['F']);
        $this->xlsSheet($sheet->createSheet(), 'Por vendedor', "Presupuestos por vendedor {$title}",
            ['Vendedor', 'Presupuestos', 'Importe', 'Convertidos', 'Importe convertido', 'Tasa de cierre %', 'Rechazados', 'Vencidos', 'Vigentes', 'Días promedio al cierre'],
            $d['bySeller']->map(fn ($r) => [$r['name'], (int) $r['count'], (float) $r['amount'], (int) $r['conv'], (float) $r['conv_amount'], $r['rate'], (int) $r['rejected'], (int) $r['expired'], (int) $r['pending'], $r['days_to_convert'] !== null ? round((float) $r['days_to_convert'], 1) : '']), ['C', 'E']);
        $this->xlsSheet($sheet->createSheet(), 'Productos', "Productos presupuestados {$title}",
            ['Código', 'Producto', 'Presupuestos', 'Convertidos', 'Perdidos', 'Cantidad', 'Importe', 'Importe perdido'],
            $d['products']->map(fn ($r) => [$r->code, $r->description, (int) $r->quotes, (int) $r->converted, (int) $r->lost, (float) $r->qty, (float) $r->amount, (float) $r->lost_amount]), ['G', 'H']);

        return $this->xlsDownload($sheet, 'reporte-presupuestos');
    }

    /** MySQL devuelve SUM/AVG como texto: los convierte a número (enteros para conteos) */
    private function numeric(array $r): array
    {
        foreach ($r as $k => $v) {
            if (is_string($v) && is_numeric($v) && ! in_array($k, ['name', 'phone', 'reason', 'code', 'label'], true)) {
                $r[$k] = in_array($k, ['count', 'conv', 'rejected', 'expired', 'pending', 'id'], true) ? (int) $v : round((float) $v, 2);
            }
        }

        return $r;
    }

    /** Hoja de Excel con título, encabezados en negrita y columnas de importes con formato */
    private function xlsSheet($ws, string $name, string $title, array $head, $rows, array $moneyCols = []): void
    {
        $ws->setTitle(mb_substr($name, 0, 31));
        $ws->fromArray([$title], null, 'A1');
        $ws->fromArray($head, null, 'A3');
        $r = 4;
        foreach ($rows as $row) {
            $ws->fromArray(array_values((array) $row), null, 'A'.$r++);
        }
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($head));
        $ws->getStyle("A1:{$last}3")->getFont()->setBold(true);
        foreach ($moneyCols as $c) {
            $ws->getStyle("{$c}4:{$c}".max(4, $r))->getNumberFormat()->setFormatCode('#,##0.00');
        }
        foreach (range(1, count($head)) as $i) {
            $ws->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    private function xlsDownload($sheet, string $name)
    {
        $sheet->setActiveSheetIndex(0);
        $path = storage_path('app/private/'.$name.'-'.now()->format('YmdHis').'-'.mt_rand(100, 999).'.xlsx');
        @mkdir(dirname($path), 0775, true);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($path);

        return response()->download($path, $name.'-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }
}
