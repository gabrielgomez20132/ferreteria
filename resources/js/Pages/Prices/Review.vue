<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { money, pct, datetime, toNum } from '@/lib/format';

const props = defineProps({ products: Object, filters: Object, suppliers: Array, categories: Array });
const selected = ref(new Set());
const tolerance = ref(props.filters.tolerance ?? 2);

const go = (p) => router.get(route('prices.review'), { ...props.filters, ...p }, { preserveState: true, preserveScroll: true });
const toggle = (id) => {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
};
const toggleAll = (e) => (selected.value = new Set(e.target.checked ? props.products.data.map((p) => p.id) : []));
const apply = (all = false) => {
    const n = all ? props.products.total : selected.value.size;
    if (!confirm(`¿Llevar ${n} productos a su precio sugerido?`)) return;
    router.post(route('prices.review.apply'), { ids: [...selected.value], all, tolerance: tolerance.value }, { preserveScroll: true, onSuccess: () => (selected.value = new Set()) });
};
const gap = (p) => (toNum(p.suggested) > 0 ? ((toNum(p.price) - toNum(p.suggested)) / toNum(p.suggested)) * 100 : 0);
</script>

<template>
    <PageHeader title="Precios a revisar" subtitle="Productos cuyo precio de venta quedó por debajo de su margen objetivo porque subió el costo y no se actualizó el precio.">
        <button class="btn-secondary" :disabled="!selected.size" @click="apply(false)">Actualizar seleccionados ({{ selected.size }})</button>
        <button class="btn-primary" :disabled="!products.total" @click="apply(true)">Actualizar todos ({{ products.total }})</button>
    </PageHeader>

    <div class="panel">
        <div class="flex flex-wrap items-end gap-3 border-b border-steel-200 px-4 py-3">
            <div><label class="label">Proveedor</label><select class="input w-auto" v-sel="filters.supplier_id ?? ''" @change="go({ supplier_id: $event.target.value || undefined })"><option value="">Todos</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
            <div><label class="label">Categoría</label><select class="input w-auto" v-sel="filters.category_id ?? ''" @change="go({ category_id: $event.target.value || undefined })"><option value="">Todas</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
            <div><label class="label">Tolerancia %</label><input v-model="tolerance" type="number" min="0" step="0.5" class="input w-24" @change="go({ tolerance })" /></div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-8"><input type="checkbox" class="checkbox" @change="toggleAll" aria-label="Seleccionar página" /></th>
                        <th>Producto</th><th>Proveedor</th><th class="num">Costo</th><th class="num">Margen objetivo</th><th class="num">Margen real</th>
                        <th class="num">Precio actual</th><th class="num">Precio sugerido</th><th class="num">Desfase</th><th>Costo actualizado</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id">
                        <td><input type="checkbox" class="checkbox" :checked="selected.has(p.id)" @change="toggle(p.id)" :aria-label="'Seleccionar ' + p.name" /></td>
                        <td><Link :href="route('products.show', p.id)" class="link">{{ p.name }}</Link><div class="code">{{ p.code }}</div></td>
                        <td>{{ p.supplier }}</td>
                        <td class="num">{{ money(p.cost) }}</td>
                        <td class="num">{{ pct(p.markup) }}</td>
                        <td class="num text-up">{{ pct(p.margin) }}</td>
                        <td class="num">{{ money(p.price) }}</td>
                        <td class="num font-semibold">{{ money(p.suggested) }}</td>
                        <td class="num"><span class="badge-up">{{ pct(gap(p)) }}</span></td>
                        <td class="text-xs whitespace-nowrap text-steel-500">{{ datetime(p.cost_updated_at) }}</td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="10" class="py-8 text-center text-steel-500">Todos los precios están al día con su margen objetivo.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="products" />
    </div>
</template>
