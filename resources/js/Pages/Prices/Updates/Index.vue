<script setup>
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import { datetime } from '@/lib/format';

defineProps({ updates: Object, filters: Object });

const SOURCES = { supplier_import: 'Lista de proveedor', mass_update: 'Actualización masiva', purchase: 'Compra', manual: 'Edición manual', review: 'Revisión de precios' };
</script>

<template>
    <PageHeader title="Historial de cambios de precios" subtitle="Cada actualización queda registrada con el costo y precio anterior. Podés revertir la más reciente de cada producto." />
    <div class="panel">
        <div class="panel-head">
            <select class="input w-auto" v-sel="filters.source ?? ''" @change="router.get(route('prices.updates'), { source: $event.target.value || undefined })">
                <option value="">Todos los orígenes</option>
                <option v-for="(l, k) in SOURCES" :key="k" :value="k">{{ l }}</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Fecha</th><th>Origen</th><th>Detalle</th><th class="num">Productos</th><th class="num">Costo prom.</th><th class="num">Precio prom.</th><th>Usuario</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="u in updates.data" :key="u.id">
                        <td class="whitespace-nowrap">{{ datetime(u.created_at) }}</td>
                        <td><span class="badge-gray">{{ SOURCES[u.source] ?? u.source }}</span></td>
                        <td class="max-w-md"><Link :href="route('prices.updates.show', u.id)" class="link">{{ u.description }}</Link></td>
                        <td class="num">{{ u.products_count }}</td>
                        <td class="num"><Variation :value="u.avg_cost_variation" /></td>
                        <td class="num"><Variation :value="u.avg_price_variation" /></td>
                        <td>{{ u.user?.name }}</td>
                        <td><StatusBadge :status="u.status" /></td>
                    </tr>
                    <tr v-if="!updates.data.length"><td colspan="8" class="py-6 text-center text-steel-500">Sin actualizaciones registradas.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="updates" />
    </div>
</template>
