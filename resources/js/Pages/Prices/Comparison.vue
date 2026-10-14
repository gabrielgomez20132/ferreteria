<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { money, pct, date } from '@/lib/format';

const props = defineProps({ products: Object, summary: Array, filters: Object, categories: Array });
const search = ref(props.filters.search ?? '');
const go = (p) => router.get(route('prices.comparison'), { ...props.filters, ...p }, { preserveState: true });
</script>

<template>
    <PageHeader title="Comparar costos entre proveedores" subtitle="Para cada artículo que comprás a más de un proveedor, quién te lo deja más barato hoy." />

    <div class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="s in summary" :key="s.id" class="panel px-4 py-3">
            <div class="font-medium">{{ s.name }}</div>
            <div class="text-xs text-steel-500">{{ s.products }} artículos vinculados · lista del {{ date(s.last_update) }}</div>
        </div>
    </div>

    <div class="panel">
        <div class="flex flex-wrap items-end gap-3 border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="go({ search })"><label class="label">Buscar</label><input v-model="search" class="input w-64" placeholder="Descripción o código" /></form>
            <div><label class="label">Categoría</label><select class="input w-auto" v-sel="filters.category_id ?? ''" @change="go({ category_id: $event.target.value || undefined })"><option value="">Todas</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" class="checkbox" :checked="filters.only_multi !== '0'" @change="go({ only_multi: $event.target.checked ? 1 : 0 })" /> Solo con 2 o más proveedores</label>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Producto</th><th>Proveedor principal</th><th class="num">Mi costo</th><th>Ofertas (de menor a mayor costo)</th><th class="num">Ahorro posible</th></tr></thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id">
                        <td><Link :href="route('products.show', p.id)" class="link">{{ p.name }}</Link><div class="code">{{ p.code }}</div></td>
                        <td>{{ p.main_supplier ?? '—' }}</td>
                        <td class="num">{{ money(p.cost) }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="(o, i) in p.offers" :key="i" class="rounded border px-2 py-0.5 text-xs" :class="i === 0 ? 'border-down/40 bg-down-bg text-down' : 'border-steel-200 text-steel-600'" :title="`Cód. ${o.supplier_code ?? '—'} · lista ${money(o.list_price)} · ${date(o.updated)}`">
                                    {{ o.supplier }} <strong class="tabular-nums">{{ money(o.cost) }}</strong>
                                </span>
                            </div>
                        </td>
                        <td class="num"><span v-if="p.saving_percent > 0" class="badge-down">{{ pct(p.saving_percent) }}</span></td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="5" class="py-8 text-center text-steel-500">No hay productos con más de un proveedor vinculado.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="products" />
    </div>
</template>
