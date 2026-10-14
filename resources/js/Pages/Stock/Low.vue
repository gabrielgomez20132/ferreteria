<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { qty } from '@/lib/format';
import { useCan } from '@/lib/can';

defineProps({ products: Object, mode: String, counts: Object });
const can = useCan();
</script>

<template>
    <PageHeader title="Alertas de reposición" :subtitle="mode === 'over' ? 'Productos que superan el stock máximo configurado en esta sucursal (sobrestock).' : 'Productos en o por debajo del stock mínimo de esta sucursal.'">
        <Link v-if="can('purchases.view') && mode !== 'over'" :href="route('purchases.suggestions')" class="btn-primary">Ver sugerencias de compra</Link>
    </PageHeader>
    <div class="mb-3 flex gap-1 border-b border-steel-200">
        <Link :href="route('stock.low')" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="mode !== 'over' ? 'border-hazard-500 font-medium' : 'border-transparent text-steel-500'">Bajo mínimo <span class="text-xs text-steel-400">{{ counts.low }}</span></Link>
        <Link :href="route('stock.low', { mode: 'over' })" class="-mb-px border-b-2 px-3 py-2 text-sm" :class="mode === 'over' ? 'border-hazard-500 font-medium' : 'border-transparent text-steel-500'">Sobre máximo <span class="text-xs text-steel-400">{{ counts.over }}</span></Link>
    </div>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Producto</th><th>Proveedor</th><th>Ubicación</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Máximo</th><th class="num">{{ mode === 'over' ? 'Excedente' : 'Faltan' }}</th></tr></thead>
            <tbody>
                <tr v-for="p in products.data" :key="p.id">
                    <td><Link :href="route('products.show', p.id)" class="link">{{ p.name }}</Link><div class="code">{{ p.code }}</div></td>
                    <td>{{ p.supplier?.name ?? '—' }}</td>
                    <td class="text-xs text-steel-500">{{ p.location }}</td>
                    <td class="num font-semibold" :class="mode === 'over' ? 'text-[#7a5b00]' : 'text-up'">{{ qty(p.branch_stock) }} <span class="text-xs font-normal text-steel-400">{{ p.unit?.abbreviation }}</span></td>
                    <td class="num">{{ qty(p.branch_min) }}</td>
                    <td class="num">{{ p.branch_max !== null ? qty(p.branch_max) : '—' }}</td>
                    <td class="num">{{ mode === 'over' ? qty(p.branch_stock - p.branch_max) : qty(Math.max(0, p.branch_min - p.branch_stock)) }}</td>
                </tr>
                <tr v-if="!products.data.length"><td colspan="7" class="py-8 text-center text-steel-500">{{ mode === 'over' ? 'Ningún producto supera su stock máximo.' : 'No hay faltantes. Todo el stock está por encima del mínimo.' }}</td></tr>
            </tbody>
        </table>
        <Pagination :data="products" />
    </div>
    <p class="mt-3 text-xs text-steel-500">El mínimo y el máximo se configuran por sucursal desde la ficha del producto (pestaña Stock → "Mínimos y ubicación").</p>
</template>
