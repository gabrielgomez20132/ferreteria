<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import { money, qty, pct, datetime, methods } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({
    scope: String, stats: Object, chart: Array, topProducts: Array, paymentMethods: Array,
    openCash: Array, pendingImports: Array, incomingTransfers: Array, recentPriceUpdates: Array,
});
const can = useCan();

const maxDay = computed(() => Math.max(1, ...props.chart.map((d) => d.total)));
const setScope = (s) => router.get(route('dashboard'), { scope: s }, { preserveScroll: true });

const alerts = computed(() => [
    props.stats.outdated_prices && { text: `${props.stats.outdated_prices} productos con precio por debajo del margen objetivo`, href: route('prices.review'), perm: 'prices.manage' },
    props.stats.low_stock && { text: `${props.stats.low_stock} productos en o por debajo del stock mínimo`, href: route('stock.low'), perm: 'stock.view' },
    props.stats.overdue_customers && { text: `${props.stats.overdue_customers} clientes con deuda vencida`, href: route('customers.index', { overdue: 1 }), perm: 'customers.view' },
    ...props.incomingTransfers.map((t) => ({ text: `Transferencia #${t.number} en camino desde ${t.from_branch.name}`, href: route('stock.transfers.show', t.id), perm: 'stock.transfer' })),
].filter((a) => a && can(a.perm)));
</script>

<template>
    <PageHeader title="Tablero">
        <div class="inline-flex rounded-md border border-steel-300 bg-white p-0.5 text-sm">
            <button class="rounded px-2.5 py-1" :class="scope !== 'all' ? 'bg-steel-800 text-white' : 'text-steel-600'" @click="setScope('branch')">Esta sucursal</button>
            <button class="rounded px-2.5 py-1" :class="scope === 'all' ? 'bg-steel-800 text-white' : 'text-steel-600'" @click="setScope('all')">Todas</button>
        </div>
    </PageHeader>

    <!-- Indicadores -->
    <div class="panel mb-4 grid grid-cols-2 divide-steel-200 md:grid-cols-4 md:divide-x">
        <div class="p-4">
            <div class="text-sm text-steel-500">Vendido hoy</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ money(stats.today_total) }}</div>
            <div class="text-xs text-steel-500">{{ stats.today_count }} ventas · margen {{ pct(stats.today_margin) }}</div>
        </div>
        <div class="p-4">
            <div class="text-sm text-steel-500">Vendido en el mes</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ money(stats.month_total) }}</div>
            <div class="text-xs text-steel-500">
                <span v-if="stats.month_vs_prev !== null" :class="stats.month_vs_prev >= 0 ? 'text-down' : 'text-up'">{{ pct(stats.month_vs_prev, true) }} vs. mes anterior · </span>
                ticket prom. {{ money(stats.month_ticket) }}
            </div>
        </div>
        <div class="border-t border-steel-200 p-4 md:border-t-0">
            <div class="text-sm text-steel-500">Utilidad bruta del mes</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ money(stats.month_gross) }}</div>
            <div class="text-xs text-steel-500">margen {{ pct(stats.month_margin) }} sobre ventas netas de IVA</div>
        </div>
        <div class="border-t border-steel-200 p-4 md:border-t-0">
            <div class="text-sm text-steel-500">Cuentas corrientes</div>
            <div class="mt-1 text-base font-semibold tabular-nums">A cobrar {{ money(stats.receivables) }}</div>
            <div class="text-base font-semibold tabular-nums text-steel-600">A pagar {{ money(stats.payables) }}</div>
        </div>
    </div>

    <div v-if="alerts.length" class="mb-4 space-y-1.5">
        <Link v-for="a in alerts" :key="a.text" :href="a.href" class="flex items-center gap-2 rounded-md border border-hazard-500/50 bg-hazard-300/20 px-3 py-2 text-sm hover:bg-hazard-300/35">
            <span class="size-2 rounded-full bg-hazard-500" /> {{ a.text }}
        </Link>
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <div class="panel xl:col-span-2">
            <div class="panel-head"><h2 class="panel-title">Ventas de los últimos 30 días</h2></div>
            <div class="flex h-48 items-end gap-[3px] px-4 pt-4 pb-2">
                <div v-for="d in chart" :key="d.date" class="group relative flex h-full flex-1 items-end">
                    <div class="w-full rounded-t-sm bg-steel-600 group-hover:bg-hazard-500" :style="{ height: Math.max(2, (d.total / maxDay) * 100) + '%' }" />
                    <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded bg-steel-900 px-2 py-1 text-xs whitespace-nowrap text-white group-hover:block">
                        {{ new Date(d.date + 'T00:00').toLocaleDateString('es-AR', { weekday: 'short', day: 'numeric', month: 'short' }) }}: {{ money(d.total) }} ({{ d.count }})
                    </div>
                </div>
            </div>
            <div class="flex justify-between px-4 pb-3 text-xs text-steel-400">
                <span>{{ new Date(chart[0].date + 'T00:00').toLocaleDateString('es-AR') }}</span><span>Hoy</span>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Cobrado hoy por medio de pago</h2></div>
            <div class="p-4">
                <div v-if="!paymentMethods.length" class="text-sm text-steel-500">Todavía no hay ventas hoy.</div>
                <div v-for="m in paymentMethods" :key="m.method" class="flex justify-between border-b border-steel-100 py-1.5 text-sm last:border-0">
                    <span>{{ methods[m.method] ?? m.method }}</span><span class="font-medium tabular-nums">{{ money(m.total) }}</span>
                </div>
                <div class="mt-4 text-sm font-medium">Cajas abiertas</div>
                <div v-if="!openCash.length" class="mt-1 text-sm text-steel-500">Ninguna caja abierta en esta sucursal.</div>
                <div v-for="c in openCash" :key="c.id" class="mt-1 text-sm text-steel-600">{{ c.cash_register.name }} — {{ c.user.name }} desde {{ datetime(c.opened_at) }}</div>
            </div>
        </div>

        <div class="panel xl:col-span-2">
            <div class="panel-head">
                <h2 class="panel-title">Más vendidos del mes</h2>
                <Link v-if="can('reports.view')" :href="route('reports.sales')" class="link text-sm">Ver reporte de ventas</Link>
            </div>
            <table class="table">
                <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Total</th></tr></thead>
                <tbody>
                    <tr v-for="p in topProducts" :key="p.product_id">
                        <td>{{ p.description }}</td><td class="num">{{ qty(p.qty) }}</td><td class="num">{{ money(p.total) }}</td>
                    </tr>
                    <tr v-if="!topProducts.length"><td colspan="3" class="text-steel-500">Sin ventas este mes.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-if="can('prices.manage')" class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Precios</h2>
                <Link :href="route('price-imports.index')" class="btn-primary btn-sm">Actualizar lista</Link>
            </div>
            <div class="divide-y divide-steel-100">
                <Link v-for="i in pendingImports" :key="'i' + i.id" :href="route('price-imports.show', i.id)" class="block px-4 py-2.5 text-sm hover:bg-steel-50">
                    <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ i.supplier.name }}</span><StatusBadge :status="i.status" /></div>
                    <div class="truncate text-xs text-steel-500">{{ i.file_name }}</div>
                </Link>
                <Link v-for="u in recentPriceUpdates" :key="'u' + u.id" :href="route('prices.updates.show', u.id)" class="block px-4 py-2.5 text-sm hover:bg-steel-50">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate">{{ u.description }}</span>
                        <Variation :value="u.avg_price_variation" />
                    </div>
                    <div class="text-xs text-steel-500">{{ u.products_count }} productos · {{ datetime(u.created_at) }}</div>
                </Link>
                <div v-if="!pendingImports.length && !recentPriceUpdates.length" class="px-4 py-3 text-sm text-steel-500">
                    Todavía no actualizaste precios. Subí la lista de un proveedor para empezar.
                </div>
            </div>
        </div>
    </div>
</template>
