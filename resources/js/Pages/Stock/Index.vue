<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { money, qty } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ products: Object, branches: Array, filters: Object, categories: Array });
const can = useCan();
const search = ref(props.filters.search ?? '');
const go = (p) => router.get(route('stock.index'), { ...props.filters, ...p }, { preserveState: true });
const cell = (p, b) => p.stocks[b.id];
const low = (p, b) => {
    const s = cell(p, b);
    const min = s?.min ?? p.min_stock;
    return min > 0 && (s?.qty ?? 0) <= min;
};
</script>

<template>
    <PageHeader title="Stock por sucursal">
        <Link v-if="can('stock.adjust')" :href="route('stock.adjust')" class="btn-secondary">Ajustar stock</Link>
        <Link v-if="can('stock.transfer')" :href="route('stock.transfers.create')" class="btn-primary">Transferir</Link>
    </PageHeader>
    <div class="panel">
        <div class="flex flex-wrap gap-2 border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="go({ search })"><input v-model="search" class="input w-72" placeholder="Buscar producto" /></form>
            <select class="input w-auto" v-sel="filters.category_id ?? ''" @change="go({ category_id: $event.target.value || undefined })"><option value="">Todas las categorías</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Producto</th><th>Ubicación</th><th v-for="b in branches" :key="b.id" class="num">{{ b.name }}</th><th class="num">Total</th><th v-if="can('products.view_cost')" class="num">Valor a costo</th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id">
                        <td><Link :href="route('products.show', p.id)" class="link">{{ p.name }}</Link><div class="code">{{ p.code }}</div></td>
                        <td class="text-xs text-steel-500">{{ p.location }}</td>
                        <td v-for="b in branches" :key="b.id" class="num" :class="low(p, b) && 'font-semibold text-up'">{{ qty(cell(p, b)?.qty ?? 0) }}</td>
                        <td class="num font-semibold">{{ qty(p.total) }} <span class="text-xs font-normal text-steel-400">{{ p.unit }}</span></td>
                        <td v-if="can('products.view_cost')" class="num text-steel-600">{{ money(p.total * p.cost) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="products" />
    </div>
</template>
