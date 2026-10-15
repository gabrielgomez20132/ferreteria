<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ArrowDown, Eye, FileSpreadsheet, MessageCircle } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import Modal from '@/Components/Modal.vue';
import { money, qty, pct, date } from '@/lib/format';

const props = defineProps({ rows: Array, inactive: Array, kpis: Object, sort: String, inactiveDays: Number, detail: Object, filters: Object, branches: Array });

const tab = ref('ranking');
const search = ref('');
const params = (extra = {}) => {
    const { customer_id, ...rest } = props.filters;
    return Object.fromEntries(Object.entries({ ...rest, ...extra }).filter(([, v]) => v !== '' && v !== null && v !== undefined));
};
const sortBy = (sort) => router.get(route('reports.customers'), params({ sort }), { preserveState: true, preserveScroll: true });
const exportUrl = computed(() => route('reports.customers.export', params()));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return q ? props.rows.filter((r) => r.name.toLowerCase().includes(q)) : props.rows;
});
const maxTotal = computed(() => Math.max(1, ...props.rows.map((r) => r.total)));

// ---- detalle de un cliente (qué compra) ----
const showDetail = ref(!!props.detail);
watch(() => props.detail, (d) => (showDetail.value = !!d));
const openDetail = (id) => router.get(route('reports.customers'), params({ customer_id: id, sort: props.sort }), { only: ['detail', 'filters'], preserveState: true, preserveScroll: true });
const maxMonth = computed(() => Math.max(1, ...(props.detail?.months ?? []).map((m) => m.total)));
const monthLabel = (ym) => new Date(ym + '-01T00:00:00').toLocaleDateString('es-AR', { month: 'short' }).replace('.', '');

const wa = (phone, name) => {
    const d = String(phone ?? '').replace(/\D/g, '');
    if (!d) return null;
    const n = d.startsWith('54') ? d : `549${d.replace(/^0/, '')}`;
    return `https://wa.me/${n}?text=${encodeURIComponent(`Hola ${name}, ¿cómo estás? Hace un tiempo que no te vemos por la ferretería.`)}`;
};
const ABC = { A: 'bg-down-bg text-down', B: 'bg-hazard-300/40 text-[#7a5b00]', C: 'bg-steel-100 text-steel-600' };
const cols = [
    { k: 'count', t: 'Compras' },
    { k: 'total', t: 'Total' },
    { k: 'avg_ticket', t: 'Ticket prom.' },
    { k: 'gross', t: 'Ganancia' },
];
</script>

<template>
    <PageHeader title="Clientes" subtitle="Quiénes te compran, cuánto te dejan y quiénes dejaron de venir" :back="route('reports.index')">
        <a :href="exportUrl" class="btn-secondary"><FileSpreadsheet class="size-4" /> Exportar Excel</a>
    </PageHeader>

    <ReportFilters :filters="filters" :branches="branches" route-name="reports.customers">
        <template #default="{ f }">
            <div><label class="label">Inactivo si no compra hace</label><select v-model="f.inactive_days" v-sel="f.inactive_days" class="input w-auto"><option v-for="d in [30, 60, 90, 180, 365]" :key="d" :value="d">{{ d }} días</option></select></div>
        </template>
    </ReportFilters>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Clientes que compraron</div>
            <div class="text-2xl font-semibold tabular-nums">{{ kpis.customers }}</div>
            <div class="text-xs text-steel-500">{{ kpis.new }} nuevos · {{ kpis.a_count }} hacen el 80 % de la venta</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Venta a clientes identificados</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.identified_total) }}</div>
            <div class="text-xs text-steel-500">{{ pct(kpis.identified_share) }} del total · consumidor final {{ money(kpis.anonymous_total) }}</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">Ticket promedio · Ganancia bruta</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.avg_ticket) }}</div>
            <div class="text-xs text-steel-500">Ganancia {{ money(kpis.gross) }} (sin IVA)</div>
        </div>
        <div class="panel p-4">
            <div class="text-sm text-steel-500">A cobrar</div>
            <div class="text-2xl font-semibold tabular-nums">{{ money(kpis.receivable) }}</div>
            <div class="text-xs" :class="kpis.overdue > 0 ? 'text-up' : 'text-steel-500'">Vencido {{ money(kpis.overdue) }}</div>
        </div>
    </div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <nav class="flex gap-1 rounded-lg bg-white p-1 shadow-xs">
            <button class="rounded-md px-3 py-1.5 text-sm font-medium" :class="tab === 'ranking' ? 'bg-steel-800 text-white' : 'text-steel-600 hover:bg-steel-100'" @click="tab = 'ranking'">Ranking <span class="opacity-70">{{ rows.length }}</span></button>
            <button class="rounded-md px-3 py-1.5 text-sm font-medium" :class="tab === 'inactive' ? 'bg-steel-800 text-white' : 'text-steel-600 hover:bg-steel-100'" @click="tab = 'inactive'">Dejaron de comprar <span class="opacity-70">{{ inactive.length }}</span></button>
        </nav>
        <input v-if="tab === 'ranking'" v-model="search" class="input w-56" placeholder="Buscar cliente…" />
    </div>

    <div v-if="tab === 'ranking'" class="panel overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th class="text-center" title="A: suman el 80 % de la venta · B: hasta el 95 % · C: el resto">Clase</th>
                    <th v-for="c in cols" :key="c.k" class="num">
                        <button class="inline-flex items-center gap-0.5 hover:text-steel-900" :class="sort === c.k && 'text-steel-900'" @click="sortBy(c.k)">{{ c.t }}<ArrowDown v-if="sort === c.k" class="size-3" /></button>
                    </th>
                    <th class="num">Margen</th>
                    <th class="num" title="Días promedio entre compras">Compra cada</th>
                    <th class="num"><button class="inline-flex items-center gap-0.5 hover:text-steel-900" :class="sort === 'last_at' && 'text-steel-900'" @click="sortBy('last_at')">Última<ArrowDown v-if="sort === 'last_at'" class="size-3" /></button></th>
                    <th class="num"><button class="inline-flex items-center gap-0.5 hover:text-steel-900" :class="sort === 'balance' && 'text-steel-900'" @click="sortBy('balance')">Saldo<ArrowDown v-if="sort === 'balance'" class="size-3" /></button></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in filtered" :key="r.id">
                    <td class="min-w-48">
                        <Link :href="route('customers.show', r.id)" class="link">{{ r.name }}</Link>
                        <span v-if="r.is_new" class="badge-gray ml-1">Nuevo</span>
                    </td>
                    <td class="text-center"><span class="badge" :class="ABC[r.abc]">{{ r.abc }}</span></td>
                    <td class="num">{{ r.count }}</td>
                    <td class="num">
                        <div class="font-medium">{{ money(r.total) }}</div>
                        <div class="ml-auto mt-0.5 h-1 w-24 rounded bg-steel-100"><div class="h-1 rounded bg-hazard-500" :style="{ width: (r.total / maxTotal) * 100 + '%' }" /></div>
                        <div class="text-xs text-steel-500">{{ pct(r.share) }}</div>
                    </td>
                    <td class="num">{{ money(r.avg_ticket) }}</td>
                    <td class="num">{{ money(r.gross) }}</td>
                    <td class="num" :class="r.margin < 15 && 'text-up'">{{ pct(r.margin) }}</td>
                    <td class="num">{{ r.every_days === null ? '—' : qty(r.every_days) + ' días' }}</td>
                    <td class="num whitespace-nowrap">{{ date(r.last_at) }}</td>
                    <td class="num">
                        <div>{{ r.balance ? money(r.balance) : '' }}</div>
                        <div v-if="r.overdue" class="text-xs text-up">venc. {{ money(r.overdue) }}</div>
                    </td>
                    <td class="text-right"><button class="btn-ghost btn-sm" title="Qué compra" @click="openDetail(r.id)"><Eye class="size-4" /></button></td>
                </tr>
                <tr v-if="!filtered.length"><td colspan="11" class="py-8 text-center text-steel-500">No hay ventas a clientes identificados en este período.</td></tr>
            </tbody>
        </table>
        <p class="border-t border-steel-200 px-4 py-2 text-xs text-steel-500">
            Total = ventas menos devoluciones, con IVA. Ganancia y margen sin IVA. Las ventas a consumidor final ({{ kpis.anonymous_count }} tickets) no se incluyen en el ranking.
        </p>
    </div>

    <div v-else class="panel overflow-x-auto">
        <div class="panel-head">
            <h2 class="panel-title">Sin compras hace más de {{ inactiveDays }} días</h2>
            <span class="text-sm text-steel-500">Compraron en total {{ money(kpis.inactive_total) }}</span>
        </div>
        <table class="table">
            <thead><tr><th>Cliente</th><th>Teléfono</th><th class="num">Última compra</th><th class="num">Días sin comprar</th><th class="num">Compras</th><th class="num">Total histórico</th><th class="num">Saldo</th><th></th></tr></thead>
            <tbody>
                <tr v-for="r in inactive" :key="r.id">
                    <td><Link :href="route('customers.show', r.id)" class="link">{{ r.name }}</Link></td>
                    <td>{{ r.phone }}</td>
                    <td class="num">{{ date(r.last_at) }}</td>
                    <td class="num font-medium" :class="r.days > 180 && 'text-up'">{{ r.days }}</td>
                    <td class="num">{{ r.count }}</td>
                    <td class="num">{{ money(r.total) }}</td>
                    <td class="num">{{ r.balance ? money(r.balance) : '' }}</td>
                    <td class="text-right whitespace-nowrap">
                        <a v-if="wa(r.phone, r.name)" :href="wa(r.phone, r.name)" target="_blank" rel="noopener" class="btn-ghost btn-sm" title="Escribirle por WhatsApp"><MessageCircle class="size-4" /></a>
                    </td>
                </tr>
                <tr v-if="!inactive.length"><td colspan="8" class="py-8 text-center text-steel-500">Ningún cliente dejó de comprar. ¡Bien!</td></tr>
            </tbody>
        </table>
    </div>

    <Modal :show="showDetail && !!detail" :title="detail ? `Qué compra ${detail.customer.name}` : ''" width="max-w-3xl" @close="showDetail = false">
        <template v-if="detail">
            <div class="mb-1 text-sm font-medium">Compras de los últimos 12 meses</div>
            <div class="flex h-32 items-end gap-1.5 border-b border-steel-200">
                <div v-for="m in detail.months" :key="m.ym" class="flex flex-1 flex-col items-center justify-end" :title="`${m.ym}: ${money(m.total)}`">
                    <div class="w-full rounded-t bg-hazard-500" :style="{ height: Math.max(m.total ? 3 : 0, (m.total / maxMonth) * 112) + 'px' }" />
                </div>
            </div>
            <div class="flex gap-1.5 text-center text-[10px] text-steel-500"><div v-for="m in detail.months" :key="m.ym" class="flex-1">{{ monthLabel(m.ym) }}</div></div>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div>
                    <div class="mb-1 text-sm font-medium">Rubros <span class="font-normal text-steel-500">(en el período, sin IVA)</span></div>
                    <table class="table">
                        <tbody>
                            <tr v-for="c in detail.categories" :key="c.label"><td>{{ c.label }}</td><td class="num">{{ money(c.net) }}</td><td class="num text-steel-500">{{ c.net ? pct((c.gross / c.net) * 100) : '' }}</td></tr>
                            <tr v-if="!detail.categories.length"><td class="text-steel-500">Sin compras en el período.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <div class="mb-1 text-sm font-medium">Productos que más compra <span class="font-normal text-steel-500">(en el período)</span></div>
                    <table class="table">
                        <tbody>
                            <tr v-for="p in detail.products" :key="p.code + p.description"><td>{{ p.description }}<div class="code">{{ p.code }}</div></td><td class="num">{{ qty(p.qty) }}</td><td class="num">{{ money(p.total) }}</td></tr>
                            <tr v-if="!detail.products.length"><td class="text-steel-500">Sin compras en el período.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
        <template #footer>
            <Link v-if="detail" :href="route('customers.show', detail.customer.id)" class="btn-secondary">Ver cuenta corriente</Link>
            <button class="btn-primary" @click="showDetail = false">Cerrar</button>
        </template>
    </Modal>
</template>
