<script setup>
import { useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import { money, date } from '@/lib/format';

const props = defineProps({ company: Object, settings: Object, priceActions: Object });
const c = props.company;
const form = useForm({
    name: c.name, legal_name: c.legal_name ?? '', cuit: c.cuit ?? '', tax_condition: c.tax_condition ?? '', address: c.address ?? '', phone: c.phone ?? '', email: c.email ?? '',
    settings: { ...props.settings },
});
const submit = () => form.put(route('settings.company.update'), { preserveScroll: true });
</script>

<template>
    <PageHeader title="Configuración de la empresa">
        <button class="btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
    </PageHeader>
    <div class="grid gap-4 xl:grid-cols-2">
        <div class="panel space-y-3 p-4">
            <h2 class="panel-title">Datos de la empresa</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <Field label="Nombre de fantasía" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
                <Field label="Razón social"><input v-model="form.legal_name" class="input" /></Field>
                <Field label="CUIT"><input v-model="form.cuit" class="input" /></Field>
                <Field label="Condición IVA"><select v-model="form.tax_condition" class="input"><option value="">—</option><option>Responsable Inscripto</option><option>Monotributo</option><option>Exento</option></select></Field>
                <Field label="Dirección"><input v-model="form.address" class="input" /></Field>
                <Field label="Teléfono"><input v-model="form.phone" class="input" /></Field>
                <Field label="Email"><input v-model="form.email" type="email" class="input" /></Field>
            </div>
            <div class="rounded-md bg-steel-50 p-3 text-sm text-steel-600">
                Plan <strong>{{ c.plan?.name ?? '—' }}</strong> ({{ money(c.plan?.price) }}/mes · hasta {{ c.plan?.max_branches }} sucursales y {{ c.plan?.max_users }} usuarios).
                <span v-if="c.status === 'trial'"> Período de prueba hasta el {{ date(c.trial_ends_at) }}.</span>
            </div>
        </div>

        <div class="panel space-y-3 p-4">
            <h2 class="panel-title">Precios</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <Field label="Margen por defecto %"><input v-model="form.settings.default_markup" type="number" step="0.01" class="input" /></Field>
                <Field label="IVA por defecto"><select v-model="form.settings.default_vat" class="input"><option v-for="v in [21, 10.5, 27, 0]" :key="v" :value="v">{{ v }}%</option></select></Field>
                <Field label="Redondear precios a">
                    <select v-model="form.settings.price_rounding" class="input">
                        <option :value="0">Sin redondeo</option><option :value="1">$1</option><option :value="5">$5</option><option :value="10">$10</option><option :value="50">$50</option><option :value="100">$100</option><option :value="500">$500</option>
                    </select>
                </Field>
                <Field label="Modo de redondeo"><select v-model="form.settings.rounding_mode" class="input"><option value="up">Siempre hacia arriba</option><option value="nearest">Al más cercano</option></select></Field>
                <Field label="Acción por defecto al cambiar costos"><select v-model="form.settings.default_price_action" class="input"><option v-for="(l, k) in priceActions" :key="k" :value="k">{{ l }}</option></select></Field>
                <Field label="Costo con varios proveedores">
                    <select v-model="form.settings.cost_update_policy" class="input"><option value="always">Tomar el de la última lista</option><option value="main_supplier">Solo el del proveedor principal</option><option value="best_cost">El menor entre proveedores</option></select>
                </Field>
                <Field label="Alertar variaciones mayores a %"><input v-model="form.settings.price_alert_threshold" type="number" min="1" class="input" /></Field>
            </div>
        </div>

        <div class="panel space-y-3 p-4">
            <h2 class="panel-title">Ventas y stock</h2>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.settings.require_cash_session" type="checkbox" class="checkbox" /> Exigir caja abierta para vender</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.settings.allow_negative_stock" type="checkbox" class="checkbox" /> Permitir vender sin stock (stock negativo)</label>
        </div>

        <div class="panel space-y-3 p-4">
            <h2 class="panel-title">Ticket</h2>
            <Field label="Encabezado"><textarea v-model="form.settings.ticket_header" rows="2" class="input" placeholder="Ej.: Horario de atención…" /></Field>
            <Field label="Pie"><textarea v-model="form.settings.ticket_footer" rows="2" class="input" /></Field>
        </div>
        <div class="panel space-y-3 p-4">
            <h2 class="panel-title">Presupuestos</h2>
            <Field label="Validez (días)" hint="Se puede cambiar en cada presupuesto"><input v-model="form.settings.quote_validity_days" type="number" min="1" max="365" class="input w-28" /></Field>
            <Field label="Condiciones comerciales" hint="Aparecen al pie del presupuesto en PDF y en el link que recibe el cliente"><textarea v-model="form.settings.quote_terms" rows="3" class="input" /></Field>
        </div>
    </div>
</template>
