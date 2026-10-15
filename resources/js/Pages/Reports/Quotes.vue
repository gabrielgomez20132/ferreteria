<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { FileSpreadsheet, MessageCircle } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import MonthBars from '@/Components/MonthBars.vue';
import { money, qty, pct, date } from '@/lib/format';
import { waLink } from '@/lib/whatsapp';

const props = defineProps({ kpis: Object, bySeller: Array, byCustomer: Array, reasons: Array, products: Array, months: Array, followUp: Array, users: Array, filters: Object, branches: Array });
const tab = ref('sellers');
const exportUrl = computed(() => route('reports.quotes.export', props.filters));
const n = (v) => Number(v) || 0;
const days = (v) => (v === null || v === undefined ? '—' : `${(Math.round(Number(v) * 10) / 10).toString().replace('.', ',')} días`);
const maxReason = computed(() => Math.max(1, ...props.reasons.map((r) => n(r.count))));
const funnel = computed(() => {
    const k = props.kpis;
    const total = Math.max(1, n(k.count));
    return [
        { label: 'Convertidos', count: n(k.conv), amount: n(k.conv_amount), color: 'bg-down', pct: (n(k.conv) / total) * 100 },
        { label: 'Vigentes', count: n(k.pending), amount: n(k.pending_amount), color: 'bg-hazard-500', pct: (n(k.pending) / total) * 100 },
        { label: 'Vencidos sin respuesta', count: n(k.expired), amount: n(k.expired_amount), color: 'bg-steel-400', pct: (n(k.expired) / total) * 100 },
        { label: 'Rechazados', count: n(k.rejected), amount: n(k.rejected_amount), color: 'bg-up', pct: (n(k.rejected) / total) * 100 },
    ];
});
const daysLeft = (d) => {
    const t = new Date();
    return Math.round((new Date(d + 'T00:00:00') - new Date(t.getFullYear(), t.getMonth(), t.getDate())) / 86400000);
};
const followMsg = (q) => `Hola${q.customer ? ' ' + q.customer.split(' ')[0] : ''}, ¿pudiste ver el presupuesto N° ${q.number}? Los precios se mantienen hasta el ${date(q.valid_until)}. Cualquier duda te ayudo.`;
</script>

<template>
    <PageHeader title="Presupuestos" subtitle="Cuántos se concretan, quién cierra más y qué se pierde" :back="route('reports.index')">
        <a :href="exportUrl" class="btn-secondary"><FileSpreadsheet class="size-4" /> Exportar Excel</a>
    </PageHeader>

    <ReportFilters :filters="filters" :branches="branches" route-name="reports.quotes">
        <template #default="{ f }">
            <div><label class="label">Vendedor</label><select v-model="f.user_id" v-sel="f.user_id" class="input w-auto"><option value="">Todos</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
        </template>
    </ReportFilters>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Presupuestado</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.amount) }}</div>
            <div class="text-xs text-steel-500">{{ kpis.count }} presupuestos · promedio {{ money(n(kpis.count) ? n(kpis.amount) / n(kpis.count) : 0) }}</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Tasa de cierre</div>
            <div class="text-2xl font-semibold tabular-nums">{{ kpis.rate === null ? '—' : pct(kpis.rate) }}</div>
            <div class="text-xs text-steel-500">de los ya resueltos · {{ kpis.rate_amount === null ? '' : pct(kpis.rate_amount) + ' en importe' }}</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Vendido desde presupuestos</div>
            <div class="text-2xl font-semibold tabular-nums text-down">{{ money(kpis.sold) }}</div>
            <div class="text-xs text-steel-500">{{ kpis.conv }} ventas · cierran en {{ days(kpis.days_to_convert) }} promedio</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Perdido (rechazado + vencido)</div>
            <div class="text-2xl font-semibold tabular-nums text-up">{{ money(n(kpis.rejected_amount) + n(kpis.expired_amount)) }}</div>
            <div class="text-xs text-steel-500">Vigentes todavía: {{ money(kpis.pending_amount) }}</div>
        </div>
    </div>

    <div class="mb-4 grid gap-4 xl:grid-cols-[1fr_1fr]">
        <div class="panel p-4">
            <div class="mb-3 text-sm font-medium">Qué pasó con los presupuestos del período</div>
            <div class="flex h-4 overflow-hidden rounded-full bg-steel-100">
                <div v-for="s in funnel" :key="s.label" :class="s.color" :style="{ width: s.pct + '%' }" :title="`${s.label}: ${s.count}`" />
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <div v-for="s in funnel" :key="s.label" class="flex items-start gap-2 text-sm">
                    <span class="mt-1 size-2.5 shrink-0 rounded-sm" :class="s.color" />
                    <div><div>{{ s.label }}</div><div class="text-xs text-steel-500">{{ s.count }} · {{ money(s.amount) }} · {{ pct(s.pct) }}</div></div>
                </div>
            </div>
        </div>
        <div class="panel p-4">
            <div class="mb-2 text-sm font-medium">Últimos 12 meses</div>
            <MonthBars :months="months" :height="120" :series="[{ key: 'amount', label: 'Presupuestado', color: '#c3cad3' }, { key: 'conv_amount', label: 'Convertido en venta', color: '#087443' }]" />
        </div>
    </div>

    <nav class="mb-3 flex flex-wrap gap-1 rounded-lg bg-white p-1 shadow-xs">
        <button v-for="[k, t, c] in [['sellers', 'Por vendedor', bySeller.length], ['follow', 'Para hacer seguimiento', followUp.length], ['products', 'Productos', products.length], ['customers', 'Por cliente', byCustomer.length], ['reasons', 'Motivos de rechazo', reasons.length]]" :key="k"
            class="rounded-md px-3 py-1.5 text-sm font-medium" :class="tab === k ? 'bg-steel-800 text-white' : 'text-steel-600 hover:bg-steel-100'" @click="tab = k">
            {{ t }} <span class="opacity-70">{{ c }}</span>
        </button>
    </nav>

    <div v-if="tab === 'sellers'" class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Vendedor</th><th class="num">Presupuestos</th><th class="num">Importe</th><th class="num">Convertidos</th><th class="num">Importe convertido</th><th class="num">Tasa de cierre</th><th class="num">Rechazados</th><th class="num">Vencidos</th><th class="num">Vigentes</th><th class="num">Cierra en</th></tr></thead>
            <tbody>
                <tr v-for="r in bySeller" :key="r.id">
                    <td class="font-medium">{{ r.name }}</td>
                    <td class="num">{{ r.count }}</td>
                    <td class="num">{{ money(r.amount) }}</td>
                    <td class="num">{{ r.conv }}</td>
                    <td class="num text-down">{{ money(r.conv_amount) }}</td>
                    <td class="num font-semibold">{{ r.rate === null ? '—' : pct(r.rate) }}</td>
                    <td class="num">{{ r.rejected }}</td>
                    <td class="num">{{ r.expired }}</td>
                    <td class="num">{{ r.pending }}</td>
                    <td class="num">{{ days(r.days_to_convert) }}</td>
                </tr>
                <tr v-if="!bySeller.length"><td colspan="10" class="py-8 text-center text-steel-500">No hay presupuestos en este período.</td></tr>
            </tbody>
        </table>
        <p class="border-t border-steel-200 px-4 py-2 text-xs text-steel-500">La tasa de cierre se calcula sobre los presupuestos ya resueltos (convertidos, rechazados o vencidos); los vigentes todavía pueden cerrarse.</p>
    </div>

    <div v-else-if="tab === 'follow'" class="panel overflow-x-auto">
        <div class="panel-head"><h2 class="panel-title">Vigentes que vencen en los próximos 7 días</h2></div>
        <table class="table">
            <thead><tr><th>N°</th><th>Cliente</th><th>Vendedor</th><th>Vence</th><th>Seguimiento</th><th class="num">Total</th><th></th></tr></thead>
            <tbody>
                <tr v-for="q in followUp" :key="q.id">
                    <td><Link :href="route('quotes.show', q.id)" class="link font-medium">#{{ q.number }}</Link></td>
                    <td>{{ q.customer ?? 'Sin cliente' }}<div class="text-xs text-steel-500">{{ q.phone }}</div></td>
                    <td>{{ q.seller }}</td>
                    <td class="whitespace-nowrap" :class="daysLeft(q.valid_until) <= 1 && 'font-medium text-[#7a5b00]'">{{ date(q.valid_until) }}<div class="text-xs">{{ daysLeft(q.valid_until) === 0 ? 'hoy' : `en ${daysLeft(q.valid_until)} días` }}</div></td>
                    <td class="text-xs text-steel-500">{{ q.viewed_at ? 'Lo abrió' : q.sent_at ? 'Enviado, sin abrir' : 'No enviado' }}</td>
                    <td class="num font-medium">{{ money(q.total) }}</td>
                    <td class="text-right"><a v-if="q.phone" :href="waLink(q.phone, followMsg(q))" target="_blank" rel="noopener" class="btn-ghost btn-sm" title="Escribirle por WhatsApp"><MessageCircle class="size-4" /></a></td>
                </tr>
                <tr v-if="!followUp.length"><td colspan="7" class="py-8 text-center text-steel-500">No hay presupuestos por vencer esta semana.</td></tr>
            </tbody>
        </table>
    </div>

    <div v-else-if="tab === 'products'" class="panel overflow-x-auto">
        <div class="panel-head"><h2 class="panel-title">Lo que se presupuesta y no se vende</h2><span class="text-xs text-steel-500">Ordenado por importe perdido: revisá precio o stock</span></div>
        <table class="table">
            <thead><tr><th>Producto</th><th class="num">Presupuestos</th><th class="num">Convertidos</th><th class="num">Perdidos</th><th class="num">Cierre</th><th class="num">Cantidad</th><th class="num">Importe</th><th class="num">Importe perdido</th></tr></thead>
            <tbody>
                <tr v-for="p in products" :key="p.product_id + p.description">
                    <td><Link v-if="p.product_id" :href="route('products.show', p.product_id)" class="link">{{ p.description }}</Link><span v-else>{{ p.description }}</span><div class="code">{{ p.code }}</div></td>
                    <td class="num">{{ p.quotes }}</td>
                    <td class="num">{{ p.converted }}</td>
                    <td class="num">{{ p.lost }}</td>
                    <td class="num" :class="n(p.converted) + n(p.lost) && n(p.converted) / (n(p.converted) + n(p.lost)) < 0.3 ? 'text-up' : ''">{{ n(p.converted) + n(p.lost) ? pct((n(p.converted) / (n(p.converted) + n(p.lost))) * 100) : '—' }}</td>
                    <td class="num">{{ qty(p.qty) }}</td>
                    <td class="num">{{ money(p.amount) }}</td>
                    <td class="num font-semibold text-up">{{ n(p.lost_amount) ? money(p.lost_amount) : '' }}</td>
                </tr>
                <tr v-if="!products.length"><td colspan="8" class="py-8 text-center text-steel-500">No hay presupuestos en este período.</td></tr>
            </tbody>
        </table>
    </div>

    <div v-else-if="tab === 'customers'" class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Cliente</th><th class="num">Presupuestos</th><th class="num">Importe</th><th class="num">Convertidos</th><th class="num">Importe convertido</th><th class="num">Tasa de cierre</th><th class="num">Vigentes</th></tr></thead>
            <tbody>
                <tr v-for="r in byCustomer" :key="r.id ?? 'none'">
                    <td><Link v-if="r.id" :href="route('customers.show', r.id)" class="link">{{ r.name }}</Link><span v-else class="text-steel-500">{{ r.name }}</span></td>
                    <td class="num">{{ r.count }}</td>
                    <td class="num">{{ money(r.amount) }}</td>
                    <td class="num">{{ r.conv }}</td>
                    <td class="num text-down">{{ money(r.conv_amount) }}</td>
                    <td class="num font-semibold">{{ r.rate === null ? '—' : pct(r.rate) }}</td>
                    <td class="num">{{ r.pending }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-else class="panel p-4">
        <div v-for="r in reasons" :key="r.reason" class="mb-3">
            <div class="flex justify-between text-sm"><span>{{ r.reason }}</span><span class="text-steel-500">{{ r.count }} · {{ money(r.amount) }}</span></div>
            <div class="mt-1 h-2 rounded bg-steel-100"><div class="h-2 rounded bg-up" :style="{ width: (n(r.count) / maxReason) * 100 + '%' }" /></div>
        </div>
        <p v-if="!reasons.length" class="py-6 text-center text-sm text-steel-500">No hay presupuestos rechazados en este período.</p>
    </div>
</template>
