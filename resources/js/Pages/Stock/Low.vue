<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { qty } from '@/lib/format';
import { useCan } from '@/lib/can';

defineProps({ products: Object });
const can = useCan();
</script>

<template>
    <PageHeader title="Faltantes" subtitle="Productos en o por debajo del stock mínimo en esta sucursal.">
        <Link v-if="can('purchases.view')" :href="route('purchases.suggestions')" class="btn-primary">Ver sugerencias de compra</Link>
    </PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Producto</th><th>Proveedor</th><th>Ubicación</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Faltan</th></tr></thead>
            <tbody>
                <tr v-for="p in products.data" :key="p.id">
                    <td><Link :href="route('products.show', p.id)" class="link">{{ p.name }}</Link><div class="code">{{ p.code }}</div></td>
                    <td>{{ p.supplier?.name ?? '—' }}</td>
                    <td class="text-xs text-steel-500">{{ p.location }}</td>
                    <td class="num font-semibold text-up">{{ qty(p.branch_stock) }} <span class="text-xs font-normal text-steel-400">{{ p.unit?.abbreviation }}</span></td>
                    <td class="num">{{ qty(p.branch_min) }}</td>
                    <td class="num">{{ qty(Math.max(0, p.branch_min - p.branch_stock)) }}</td>
                </tr>
                <tr v-if="!products.data.length"><td colspan="6" class="py-8 text-center text-steel-500">No hay faltantes. Todo el stock está por encima del mínimo.</td></tr>
            </tbody>
        </table>
        <Pagination :data="products" />
    </div>
</template>
