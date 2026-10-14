<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import { money, qty, date } from '@/lib/format';

defineProps({ rows: Object, filters: Object, total: Number });
</script>

<template>
    <PageHeader title="Productos sin rotación" :subtitle="`Capital inmovilizado a costo: ${money(total)}`" :back="route('reports.index')" />
    <ReportFilters :filters="filters" :dates="false" route-name="reports.no-rotation" v-slot="{ f }">
        <div><label class="label">Sin ventas en los últimos</label><select v-model="f.days" class="input w-auto"><option :value="30">30 días</option><option :value="60">60 días</option><option :value="90">90 días</option><option :value="180">180 días</option><option :value="365">1 año</option></select></div>
    </ReportFilters>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Producto</th><th class="num">Stock</th><th class="num">Costo</th><th class="num">Inmovilizado</th><th>Última venta</th></tr></thead>
            <tbody>
                <tr v-for="r in rows.data" :key="r.id">
                    <td><Link :href="route('products.show', r.id)" class="link">{{ r.name }}</Link><div class="code">{{ r.code }}</div></td>
                    <td class="num">{{ qty(r.qty) }}</td><td class="num">{{ money(r.cost) }}</td><td class="num font-medium">{{ money(r.immobilized) }}</td><td>{{ r.last_sale ? date(r.last_sale) : 'Nunca' }}</td>
                </tr>
                <tr v-if="!rows.data.length"><td colspan="5" class="py-6 text-center text-steel-500">Todo el stock tuvo movimiento en el período.</td></tr>
            </tbody>
        </table>
        <Pagination :data="rows" />
    </div>
</template>
