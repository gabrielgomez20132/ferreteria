<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import ReportFilters from '@/Components/ReportFilters.vue';
import { money, pct } from '@/lib/format';

defineProps({ rows: Object, filters: Object });
</script>

<template>
    <PageHeader title="Productos con bajo margen" subtitle="Margen real = (precio sin IVA − costo) / precio sin IVA" :back="route('reports.index')" />
    <ReportFilters :filters="filters" :dates="false" route-name="reports.low-margin" v-slot="{ f }">
        <div><label class="label">Margen menor a %</label><input v-model="f.threshold" type="number" min="0" step="1" class="input w-24" /></div>
    </ReportFilters>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Producto</th><th>Proveedor</th><th class="num">Costo</th><th class="num">Precio</th><th class="num">Margen objetivo</th><th class="num">Margen real</th></tr></thead>
            <tbody>
                <tr v-for="r in rows.data" :key="r.id">
                    <td><Link :href="route('products.show', r.id)" class="link">{{ r.name }}</Link><div class="code">{{ r.code }}</div></td>
                    <td>{{ r.supplier ?? '—' }}</td><td class="num">{{ money(r.cost) }}</td><td class="num">{{ money(r.price) }}</td>
                    <td class="num">{{ pct(r.markup) }} s/costo</td><td class="num"><span class="badge-up">{{ pct(r.margin) }}</span></td>
                </tr>
                <tr v-if="!rows.data.length"><td colspan="6" class="py-6 text-center text-steel-500">Ningún producto por debajo del umbral.</td></tr>
            </tbody>
        </table>
        <Pagination :data="rows" />
    </div>
</template>
