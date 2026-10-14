<script setup>
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import { money, qty } from '@/lib/format';

const props = defineProps({ rows: Array, byBranch: Array, filters: Object, branches: Array });
const sum = (k) => props.rows.reduce((a, r) => a + Number(r[k]), 0);
const totals = computed(() => ({ cost: sum('at_cost'), avg: sum('at_avg'), price: sum('at_price') }));
</script>

<template>
    <PageHeader title="Stock valorizado" :back="route('reports.index')" />
    <ReportFilters :filters="filters" :branches="branches" :dates="false" route-name="reports.valuation" />
    <div class="panel mb-4 grid grid-cols-1 divide-steel-200 sm:grid-cols-3 sm:divide-x">
        <div class="p-4"><div class="text-sm text-steel-500">A costo de reposición</div><div class="text-2xl font-semibold tabular-nums">{{ money(totals.cost) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">A costo promedio</div><div class="text-2xl font-semibold tabular-nums">{{ money(totals.avg) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">A precio de venta</div><div class="text-2xl font-semibold tabular-nums">{{ money(totals.price) }}</div></div>
    </div>
    <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
        <div class="panel overflow-x-auto">
            <table class="table">
                <thead><tr><th>Categoría</th><th class="num">Productos</th><th class="num">Unidades</th><th class="num">A costo</th><th class="num">A precio</th></tr></thead>
                <tbody><tr v-for="r in rows" :key="r.label"><td>{{ r.label }}</td><td class="num">{{ r.products }}</td><td class="num">{{ qty(r.qty) }}</td><td class="num font-medium">{{ money(r.at_cost) }}</td><td class="num">{{ money(r.at_price) }}</td></tr></tbody>
            </table>
        </div>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Por sucursal</h2></div>
            <table class="table"><tbody><tr v-for="b in byBranch" :key="b.label"><td>{{ b.label }}</td><td class="num">{{ money(b.at_cost) }}</td></tr></tbody></table>
        </div>
    </div>
</template>
