<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Sale;
use App\Services\CashService;
use App\Services\SaleService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PosController extends Controller
{
    public function index(Request $request, CashService $cash, TenantContext $ctx)
    {
        // Presupuesto cargado: convert (cobrarlo), edit (modificarlo) o copy (duplicarlo como uno nuevo)
        $quote = null;
        $quoteMode = in_array($request->input('mode'), ['edit', 'copy'], true) ? $request->input('mode') : 'convert';
        if ($request->filled('quote')) {
            $quote = Sale::where('type', 'quote')->with('items.product', 'customer')->find($request->integer('quote'));
            if ($quote && $quoteMode !== 'copy' && $quote->status !== 'quote') {
                return redirect()->route('quotes.show', $quote)->with('error', $quote->status === 'converted'
                    ? "El presupuesto #{$quote->number} ya se convirtió en venta."
                    : "El presupuesto #{$quote->number} está rechazado: reabrilo primero.");
            }
        }

        return Inertia::render('Pos/Index', [
            'priceLists' => PriceList::where('is_active', true)->orderByDesc('is_default')->get(['id', 'name', 'is_default', 'adjustment_percent']),
            'methods' => CashService::METHODS,
            'session' => $cash->openSessionFor($request->user(), $ctx->branchId())?->load('cashRegister:id,name'),
            'requireSession' => (bool) $ctx->setting('require_cash_session', true),
            'consumidorFinal' => Customer::where('name', 'Consumidor Final')->first(['id', 'name']),
            'quote' => $quote,
            'quoteMode' => $quote ? $quoteMode : null,
            'lastSale' => $request->session()->get('last_sale'),
        ]);
    }

    public function store(Request $request, SaleService $sales, TenantContext $ctx)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['sale', 'quote'])],
            // 'factura' (factura electrónica ARCA) se habilitará al integrar el webservice de ARCA
            'voucher_type' => ['nullable', Rule::in(['ticket'])],
            'quote_id' => ['nullable', Rule::exists('sales', 'id')->where('tenant_id', $ctx->id())->where('type', 'quote')],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('tenant_id', $ctx->id())],
            'price_list_id' => ['nullable', Rule::exists('price_lists', 'id')->where('tenant_id', $ctx->id())],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payments' => ['array'],
            'payments.*.method' => ['required', Rule::in(array_keys(CashService::METHODS))],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ]);

        $sale = $sales->create($data, $request->user());

        return redirect()->route('pos')
            ->with('success', ($sale->type === 'quote' ? "Presupuesto #{$sale->number} guardado" : "Venta #{$sale->number} registrada").($sale->quote_id ? ' a partir del presupuesto #'.$sale->quote()->value('number') : '').'. Total $ '.number_format((float) $sale->total, 2, ',', '.'))
            ->with('last_sale', ['id' => $sale->id, 'number' => $sale->number, 'type' => $sale->type, 'total' => (float) $sale->total, 'change' => (float) $sale->change_amount]);
    }
}
