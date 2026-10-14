<script setup>
import PageHeader from '@/Components/PageHeader.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import { money, pct } from '@/lib/format';

const props = defineProps({ rows: Array, filters: Object, branches: Array });
const monthName = (ym) => new Date(ym + '-01T00:00').toLocaleDateString('es-AR', { month: 'short', year: '2-digit' });
const sum = (k) => props.rows.reduce((a, r) => a + r[k], 0);
</script>

<template>
    <PageHeader title="Estado de resultados" subtitle="Simplificado: ventas netas de IVA, costo de mercadería vendida y gastos operativos registrados." :back="route('reports.index')" />
    <ReportFilters :filters="filters" :branches="branches" :dates="false" route-name="reports.results" v-slot="{ f }">
        <div><label class="label">Meses</label><select v-model="f.months" class="input w-auto"><option :value="3">3</option><option :value="6">6</option><option :value="12">12</option></select></div>
    </ReportFilters>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th></th><th v-for="r in rows" :key="r.month" class="num capitalize">{{ monthName(r.month) }}</th><th class="num">Total</th></tr></thead>
            <tbody>
                <tr><td>Ventas netas</td><td v-for="r in rows" :key="r.month" class="num">{{ money(r.net_sales) }}</td><td class="num font-medium">{{ money(sum('net_sales')) }}</td></tr>
                <tr><td>Costo de mercadería</td><td v-for="r in rows" :key="r.month" class="num text-steel-500">− {{ money(r.cogs) }}</td><td class="num">− {{ money(sum('cogs')) }}</td></tr>
                <tr class="font-medium"><td>Utilidad bruta</td><td v-for="r in rows" :key="r.month" class="num">{{ money(r.gross) }}<div class="text-[11px] font-normal text-steel-500">{{ pct(r.gross_margin) }}</div></td><td class="num">{{ money(sum('gross')) }}</td></tr>
                <tr><td>Gastos operativos</td><td v-for="r in rows" :key="r.month" class="num text-steel-500" :title="r.expenses_detail.map((e) => e.category + ': ' + money(e.total)).join('\n')">− {{ money(r.expenses) }}</td><td class="num">− {{ money(sum('expenses')) }}</td></tr>
                <tr class="text-base font-semibold"><td>Resultado</td><td v-for="r in rows" :key="r.month" class="num" :class="r.result < 0 && 'text-up'">{{ money(r.result) }}</td><td class="num" :class="sum('result') < 0 && 'text-up'">{{ money(sum('result')) }}</td></tr>
            </tbody>
        </table>
    </div>
</template>
