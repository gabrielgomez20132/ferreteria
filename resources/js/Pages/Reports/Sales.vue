<script setup>
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import { money, qty, methods } from '@/lib/format';

const props = defineProps({ rows: Array, top: Array, totals: Object, group: String, filters: Object, branches: Array });
const max = computed(() => Math.max(1, ...props.rows.map((r) => Number(r.total))));
const label = (r) => (props.group === 'method' ? methods[r.label] ?? r.label : props.group === 'hour' ? `${r.label} h` : props.group === 'day' ? new Date(r.label + 'T00:00').toLocaleDateString('es-AR', { weekday: 'short', day: '2-digit', month: '2-digit' }) : r.label);
</script>

<template>
    <PageHeader title="Reporte de ventas" :back="route('reports.index')" />
    <ReportFilters :filters="{ group: 'day', ...filters }" :branches="branches" route-name="reports.sales" v-slot="{ f }">
        <div><label class="label">Agrupar por</label>
            <select v-model="f.group" class="input w-auto"><option value="day">Día</option><option value="month">Mes</option><option value="hour">Hora del día</option><option value="user">Vendedor</option><option value="branch">Sucursal</option><option value="method">Medio de pago</option></select>
        </div>
    </ReportFilters>
    <div class="panel mb-4 grid grid-cols-2 divide-x divide-steel-200 md:grid-cols-4">
        <div class="p-4"><div class="text-sm text-steel-500">Ventas</div><div class="text-xl font-semibold tabular-nums">{{ totals.count }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Facturado</div><div class="text-xl font-semibold tabular-nums">{{ money(totals.total) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Devoluciones</div><div class="text-xl font-semibold tabular-nums">{{ money(totals.returned) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Descuentos otorgados</div><div class="text-xl font-semibold tabular-nums">{{ money(totals.discounts) }}</div></div>
    </div>
    <div class="grid gap-4 xl:grid-cols-2">
        <div class="panel">
            <table class="table">
                <thead><tr><th>Período / grupo</th><th class="num">Ventas</th><th class="num">Total</th><th class="w-1/3"></th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.label">
                        <td>{{ label(r) }}</td><td class="num">{{ r.count }}</td><td class="num font-medium">{{ money(r.total) }}</td>
                        <td><div class="h-2 rounded-sm bg-steel-600" :style="{ width: (Number(r.total) / max) * 100 + '%' }" /></td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="4" class="py-6 text-center text-steel-500">Sin ventas en el período.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Productos más vendidos</h2></div>
            <table class="table">
                <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Total</th></tr></thead>
                <tbody><tr v-for="t in top" :key="t.product_id + t.description"><td>{{ t.description }}<div class="code">{{ t.code }}</div></td><td class="num">{{ qty(t.qty) }}</td><td class="num">{{ money(t.total) }}</td></tr></tbody>
            </table>
        </div>
    </div>
</template>
