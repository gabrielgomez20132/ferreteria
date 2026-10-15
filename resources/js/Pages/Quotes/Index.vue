<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, MessageCircle, Download, ShoppingCart, BarChart3 } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import QuoteStateBadge from '@/Components/QuoteStateBadge.vue';
import { money, date, pct } from '@/lib/format';
import { getJson, postJson } from '@/lib/http';
import { downloadQuotePdf } from '@/lib/quotePdf';
import { waLink } from '@/lib/whatsapp';
import { quoteMessage } from '@/lib/quoteMessage';
import { useCan } from '@/lib/can';

const props = defineProps({ quotes: Object, counts: Object, filters: Object, kpis: Object, users: Array, branches: Array, states: Object, today: String });
const can = useCan();
const f = reactive({ search: '', from: '', to: '', user_id: '', ...props.filters });
const apply = () => router.get(route('quotes.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)), { preserveState: true });
const setState = (s) => {
    f.state = s;
    apply();
};
const TABS = [
    ['open', 'Abiertos'],
    ['pending', 'Vigentes'],
    ['expired', 'Vencidos'],
    ['converted', 'Convertidos'],
    ['rejected', 'Rechazados'],
    ['all', 'Todos'],
];

const daysTo = (d) => Math.round((new Date(d + 'T00:00:00') - new Date(props.today + 'T00:00:00')) / 86400000);
const validity = (q) => {
    if (!q.valid_until || !['pending', 'expired'].includes(q.quote_state)) return '';
    const n = daysTo(q.valid_until);
    if (n < 0) return `vencido hace ${-n} ${n === -1 ? 'día' : 'días'}`;
    if (n === 0) return 'vence hoy';
    return `vence en ${n} ${n === 1 ? 'día' : 'días'}`;
};

const busy = ref(null);
async function pdf(q) {
    busy.value = q.id;
    try {
        await downloadQuotePdf(await getJson(route('quotes.pdf', q.id)));
    } finally {
        busy.value = null;
    }
}
async function whatsapp(q) {
    const win = window.open('', '_blank');
    const data = await getJson(route('quotes.pdf', q.id));
    win.location.href = waLink(q.customer?.phone, quoteMessage(data.quote, data.company, data.publicUrl));
    postJson(route('quotes.sent', q.id)).catch(() => {});
}
</script>

<template>
    <PageHeader title="Presupuestos" subtitle="Seguí cada presupuesto hasta que se convierte en venta">
        <Link v-if="can('reports.view')" :href="route('reports.quotes')" class="btn-secondary"><BarChart3 class="size-4" /> Reporte</Link>
        <Link v-if="can('sales.create')" :href="route('pos')" class="btn-primary"><Plus class="size-4" /> Nuevo presupuesto</Link>
    </PageHeader>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Vigentes por cobrar</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.pending_amount) }}</div>
            <div class="text-xs text-steel-500">{{ counts.pending }} presupuestos vigentes</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Vencen en 3 días o menos</div>
            <div class="text-2xl font-semibold tabular-nums" :class="kpis.expiring > 0 && 'text-[#7a5b00]'">{{ kpis.expiring }}</div>
            <div class="text-xs text-steel-500">Buen momento para llamar al cliente</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Tasa de cierre (90 días)</div>
            <div class="text-2xl font-semibold tabular-nums">{{ kpis.conversion === null ? '—' : pct(kpis.conversion) }}</div>
            <div class="text-xs text-steel-500">{{ kpis.conversion_amount === null ? '' : pct(kpis.conversion_amount) + ' del importe presupuestado' }}</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Vencidos sin respuesta</div>
            <div class="text-2xl font-semibold tabular-nums">{{ counts.expired }}</div>
            <div class="text-xs text-steel-500">Reabrilos o marcalos como rechazados</div>
        </div>
    </div>

    <div class="mb-3 flex flex-wrap gap-1 rounded-lg bg-white p-1 shadow-xs">
        <button
            v-for="[k, t] in TABS"
            :key="k"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="f.state === k ? 'bg-steel-800 text-white' : 'text-steel-600 hover:bg-steel-100'"
            @click="setState(k)"
        >
            {{ t }} <span class="opacity-70">{{ counts[k] }}</span>
        </button>
    </div>

    <div class="panel">
        <form class="flex flex-wrap items-end gap-2 border-b border-steel-200 px-4 py-3" @submit.prevent="apply">
            <div><label class="label">N° o cliente</label><input v-model="f.search" class="input w-44" /></div>
            <div><label class="label">Desde</label><input v-model="f.from" type="date" class="input" /></div>
            <div><label class="label">Hasta</label><input v-model="f.to" type="date" class="input" /></div>
            <div><label class="label">Sucursal</label><select v-model="f.branch_id" v-sel="f.branch_id" class="input w-auto"><option value="all">Todas</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
            <div><label class="label">Vendedor</label><select v-model="f.user_id" v-sel="f.user_id" class="input w-auto"><option value="">Todos</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
            <button class="btn-dark">Filtrar</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>Vendedor</th><th>Validez</th><th class="num">Total</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="q in quotes.data" :key="q.id">
                        <td><Link :href="route('quotes.show', q.id)" class="link font-medium">#{{ q.number }}</Link></td>
                        <td class="whitespace-nowrap">{{ date(q.created_at) }}</td>
                        <td>{{ q.customer?.name ?? 'Sin cliente' }}<div v-if="q.notes" class="max-w-64 truncate text-xs text-steel-500">{{ q.notes }}</div></td>
                        <td>{{ q.user?.name }}</td>
                        <td class="whitespace-nowrap">
                            {{ date(q.valid_until) }}
                            <div class="text-xs" :class="q.quote_state === 'expired' ? 'text-steel-500' : daysTo(q.valid_until) <= 3 ? 'text-[#7a5b00] font-medium' : 'text-steel-500'">{{ validity(q) }}</div>
                        </td>
                        <td class="num font-medium">{{ money(q.total) }}</td>
                        <td>
                            <QuoteStateBadge :state="q.quote_state" />
                            <div v-if="q.converted_sale" class="text-xs"><Link :href="route('sales.show', q.converted_sale.id)" class="link">venta #{{ q.converted_sale.number }}</Link></div>
                            <div v-else-if="q.reject_reason" class="max-w-40 truncate text-xs text-steel-500">{{ q.reject_reason }}</div>
                            <div v-else-if="q.viewed_at" class="text-xs text-steel-500">visto por el cliente</div>
                            <div v-else-if="q.sent_at" class="text-xs text-steel-500">enviado</div>
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <button class="btn-ghost btn-sm" title="Descargar PDF" :disabled="busy === q.id" @click="pdf(q)"><Download class="size-4" /></button>
                            <button v-if="['pending', 'expired'].includes(q.quote_state)" class="btn-ghost btn-sm" title="Enviar por WhatsApp" @click="whatsapp(q)"><MessageCircle class="size-4" /></button>
                            <Link v-if="['pending', 'expired'].includes(q.quote_state) && can('sales.create')" :href="route('pos', { quote: q.id })" class="btn-secondary btn-sm"><ShoppingCart class="size-4" /> Cobrar</Link>
                        </td>
                    </tr>
                    <tr v-if="!quotes.data.length"><td colspan="8" class="py-8 text-center text-steel-500">No hay presupuestos con estos filtros.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="quotes" />
    </div>
</template>
