<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\PricingService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CompanySettingsController extends Controller
{
    public function edit(TenantContext $ctx)
    {
        return Inertia::render('Settings/Company', [
            'company' => $ctx->tenant->load('plan'),
            'settings' => $ctx->tenant->allSettings(),
            'priceActions' => PricingService::PRICE_ACTIONS,
        ]);
    }

    public function update(Request $request, TenantContext $ctx)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'tax_condition' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'settings' => ['required', 'array'],
            'settings.default_markup' => ['required', 'numeric', 'min:0'],
            'settings.default_vat' => ['required', 'numeric'],
            'settings.price_rounding' => ['required', 'numeric', Rule::in([0, 0.01, 0.1, 1, 5, 10, 50, 100, 500, 1000])],
            'settings.rounding_mode' => ['required', Rule::in(['up', 'nearest'])],
            'settings.price_alert_threshold' => ['required', 'numeric', 'min:1', 'max:500'],
            'settings.allow_negative_stock' => ['boolean'],
            'settings.require_cash_session' => ['boolean'],
            'settings.ticket_header' => ['nullable', 'string', 'max:500'],
            'settings.ticket_footer' => ['nullable', 'string', 'max:500'],
            'settings.cost_update_policy' => ['required', Rule::in(['always', 'main_supplier', 'best_cost'])],
            'settings.default_price_action' => ['required', Rule::in(array_keys(PricingService::PRICE_ACTIONS))],
        ]);
        $settings = array_merge(Tenant::DEFAULT_SETTINGS, $ctx->tenant->settings ?? [], $data['settings']);
        unset($data['settings']);
        $ctx->tenant->update($data + ['settings' => $settings]);

        return back()->with('success', 'Configuración guardada.');
    }
}
