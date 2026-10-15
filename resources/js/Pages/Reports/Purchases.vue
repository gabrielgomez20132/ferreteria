<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet, TrendingUp, TrendingDown } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import MonthBars from '@/Components/MonthBars.vue';
import { money, qty, pct, date } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ rows: Array, variation: Array, pending: Array, months: Array, kpis: Object, by: String, filters: Object, branches: Array });
const can = useCan();
const BY = { supplier: 'Proveedor', category: 'Rubro', brand: 'Marca', month: 'Mes', product: 'Producto' };
const tab = ref('grouped');
const setBy = (by) => router.get(route('reports.purchases'), { ...props.filters, by }, { preserveState: true, preserveScroll: true });
const exportUrl = computed(() => route('reports.purchases.export', { ...props.filters, by: props.by }));
const monthLabel = (ym) => new Date(ym + '-01T00:00:00').toLocaleDateString('es-AR', { month: 'long', year: 'numeric' });
const maxShare = computed(() => Math.max(1, ...props.rows.map((r) => r.share)));
</script>

<template>
    <PageHeader title="Compras" subtitle="Cuánto, a quién y a qué costo comprás" :back="route('reports.index')">
        <a :href="exportUrl" class="btn-secondary"><FileSpreadsheet class="size-4" /> Exportar Excel</a>
    </PageHeader>

    <ReportFilters :filters="filters" :branches="branches" route-name="reports.purchases" />

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Comprado en el período</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.total) }}</div>
            <div class="text-xs text-steel-500">{{ kpis.count }} {{ kpis.count === 1 ? 'factura' : 'facturas' }} · {{ kpis.suppliers }} {{ kpis.suppliers === 1 ? 'proveedor' : 'proveedores' }} · promedio {{ money(kpis.avg) }}</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Neto · IVA · percepciones · fletes</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.net) }}</div>
            <div class="text-xs text-steel-500">IVA {{ money(kpis.vat) }} · perc. {{ money(kpis.taxes) }} · fletes {{ money(kpis.extra) }}</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Compras vs costo de lo vendido</div>
            <div class="text-2xl font-semibold tabular-nums">{{ kpis.cover === null ? '—' : pct(kpis.cover) }}</div>
            <div class="text-xs text-steel-500">
                Costo vendido {{ money(kpis.cogs) }} ·
                <span v-if="kpis.cover !== null" :class="kpis.cover > 110 ? 'text-[#7a5b00]' : ''">{{ kpis.cover > 110 ? 'el stock está creciendo' : kpis.cover < 90 ? 'el stock está bajando' : 'equilibrado' }}</span>
            </div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Facturas impagas</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.to_pay) }}</div>
            <div class="text-xs" :class="kpis.overdue > 0 ? 'text-up' : 'text-steel-500'">Vencido {{ money(kpis.overdue) }}</div>
        </div>
    </div>

    <div class="panel mb-4 p-4">
        <div class="mb-2 text-sm font-medium">Últimos 12 meses</div>
        <MonthBars :months="months" :series="[{ key: 'purchases', label: 'Compras (con IVA)', color: '#2f5d8a' }, { key: 'cogs', label: 'Costo de lo vendido (sin IVA)', color: '#f2b705' }]" />
    </div>

    <nav class="mb-3 flex flex-wrap gap-1 rounded-lg bg-white p-1 shadow-xs">
        <button v-for="[k, t, n] in [['grouped', 'Compras agrupadas', rows.length], ['variation', 'Variación de costos', variation.length], ['pending', 'Facturas impagas', pending.length]]" :key="k"
            class="rounded-md px-3 py-1.5 text-sm font-medium" :class="tab === k ? 'bg-steel-800 text-white' : 'text-steel-600 hover:bg-steel-100'" @click="tab = k">
            {{ t }} <span class="opacity-70">{{ n }}</span>
        </button>
    </nav>

    <div v-if="tab === 'grouped'" class="panel overflow-x-auto">
        <div class="panel-head">
            <div class="flex flex-wrap gap-1">
                <button v-for="(l, k) in BY" :key="k" class="btn-sm rounded-md" :class="by === k ? 'bg-steel-800 text-white' : 'btn-ghost'" @click="setBy(k)">Por {{ l.toLowerCase() }}</button>
            </div>
        </div>
        <table class="table">
            <thead><tr><th>{{ BY[by] }}</th><th class="num">Facturas</th><th class="num">Cantidad</th><th class="num">Importe neto</th><th class="w-48">% del total</th></tr></thead>
            <tbody>
                <tr v-for="r in rows" :key="r.label + (r.code ?? '')">
                    <td>
                        <Link v-if="by === 'supplier'" :href="route('suppliers.show', r.supplier_id)" class="link">{{ r.label }}</Link>
                        <span v-else-if="by === 'month'" class="capitalize">{{ monthLabel(r.label) }}</span>
                        <template v-else>{{ r.label }}<div v-if="r.code" class="code">{{ r.code }}</div></template>
                    </td>
                    <td class="num">{{ r.invoices }}</td>
                    <td class="num">{{ qty(r.qty) }}</td>
                    <td class="num font-medium">{{ money(r.amount) }}</td>
                    <td>
                        <div class="flex items-center gap-2"><div class="h-1.5 flex-1 rounded bg-steel-100"><div class="h-1.5 rounded bg-link" :style="{ width: (r.share / maxShare) * 100 + '%' }" /></div><span class="w-12 text-right text-xs tabular-nums">{{ pct(r.share) }}</span></div>
                    </td>
                </tr>
                <tr v-if="!rows.length"><td colspan="5" class="py-8 text-center text-steel-500">No hay compras en este período.</td></tr>
            </tbody>
        </table>
    </div>

    <div v-else-if="tab === 'variation'" class="panel overflow-x-auto">
        <div class="panel-head">
            <h2 class="panel-title">Costo unitario: primera vs última compra del período</h2>
            <span class="flex gap-3 text-sm"><span class="flex items-center gap-1 text-up"><TrendingUp class="size-4" /> {{ kpis.cost_up }} subieron</span><span class="flex items-center gap-1 text-down"><TrendingDown class="size-4" /> {{ kpis.cost_down }} bajaron</span></span>
        </div>
        <table class="table">
            <thead><tr><th>Producto</th><th class="num">Compras</th><th class="num">Costo inicial</th><th class="num">Costo final</th><th class="num">Variación</th></tr></thead>
            <tbody>
                <tr v-for="r in variation" :key="r.product_id">
                    <td><Link :href="route('products.show', r.product_id)" class="link">{{ r.name }}</Link><div class="code">{{ r.code }}</div></td>
                    <td class="num">{{ r.purchases }}</td>
                    <td class="num">{{ money(r.first_cost) }}<div class="text-xs text-steel-500">{{ date(r.first_date) }}</div></td>
                    <td class="num">{{ money(r.last_cost) }}<div class="text-xs text-steel-500">{{ date(r.last_date) }}</div></td>
                    <td class="num font-semibold" :class="r.variation > 0 ? 'text-up' : 'text-down'">{{ pct(r.variation, true) }}</td>
                </tr>
                <tr v-if="!variation.length"><td colspan="5" class="py-8 text-center text-steel-500">Ningún producto se compró más de una vez con distinto costo en este período.</td></tr>
            </tbody>
        </table>
        <p class="border-t border-steel-200 px-4 py-2 text-xs text-steel-500">Costo neto por unidad de stock (con bonificaciones, sin IVA). Solo productos comprados dos o más veces en el período.</p>
    </div>

    <div v-else class="panel overflow-x-auto">
        <div class="panel-head">
            <h2 class="panel-title">Facturas de compra pendientes de pago</h2>
            <Link v-if="can('suppliers.manage')" :href="route('payment-orders.create')" class="btn-primary btn-sm">Nueva orden de pago</Link>
        </div>
        <table class="table">
            <thead><tr><th>Proveedor</th><th>Comprobante</th><th>Fecha</th><th>Vencimiento</th><th class="num">Total</th><th class="num">Pagado</th><th class="num">Pendiente</th></tr></thead>
            <tbody>
                <tr v-for="r in pending" :key="r.id">
                    <td><Link :href="route('suppliers.show', r.supplier_id)" class="link">{{ r.supplier }}</Link></td>
                    <td><Link :href="route('purchases.show', r.id)" class="link">{{ r.voucher_type }} {{ r.voucher_number || 's/n' }}</Link></td>
                    <td class="whitespace-nowrap">{{ date(r.date) }}</td>
                    <td class="whitespace-nowrap" :class="r.days_overdue > 0 && 'text-up'">{{ date(r.due_date) }}<div v-if="r.days_overdue > 0" class="text-xs">vencida hace {{ r.days_overdue }} días</div></td>
                    <td class="num">{{ money(r.total) }}</td>
                    <td class="num">{{ Number(r.paid_amount) ? money(r.paid_amount) : '' }}</td>
                    <td class="num font-semibold">{{ money(r.pending) }}</td>
                </tr>
                <tr v-if="!pending.length"><td colspan="7" class="py-8 text-center text-steel-500">No hay facturas impagas.</td></tr>
            </tbody>
        </table>
    </div>
</template>
