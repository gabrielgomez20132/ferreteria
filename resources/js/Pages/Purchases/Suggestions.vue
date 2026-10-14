<script setup>
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Wand2 } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import { money, qty } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ items: Array, suppliers: Array, filters: Object });
const can = useCan();
const qtys = reactive(Object.fromEntries(props.items.map((i) => [i.id, i.suggested_quantity])));
const picked = reactive(new Set(props.items.map((i) => i.id)));
const autoOrders = () =>
    confirm('Se crea un pedido en borrador por proveedor con todo lo que está bajo mínimo (descontando lo ya pedido). ¿Continuar?') &&
    router.post(route('purchases.auto-orders'), { supplier_id: props.filters.supplier_id || undefined });
const toggle = (id) => (picked.has(id) ? picked.delete(id) : picked.add(id));
const orderUrl = computed(() => {
    const items = {};
    for (const i of props.items) if (picked.has(i.id) && Number(qtys[i.id]) > 0) items[i.id] = qtys[i.id];
    return route('purchase-orders.create', { supplier_id: props.filters.supplier_id || undefined, items });
});
</script>

<template>
    <PageHeader title="Sugerencias de compra" subtitle="Productos en o por debajo del stock mínimo de esta sucursal. La cantidad sugerida cubre el máximo, el doble del mínimo o lo vendido en 30 días.">
        <select class="input w-auto" v-sel="filters.supplier_id ?? ''" @change="router.get(route('purchases.suggestions'), { supplier_id: $event.target.value || undefined })">
            <option value="">Todos los proveedores</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
        <Link v-if="can('purchases.manage') && filters.supplier_id && picked.size" :href="orderUrl" class="btn-secondary">Armar pedido con la selección ({{ picked.size }})</Link>
        <button v-if="can('purchases.manage') && items.length" class="btn-primary" @click="autoOrders"><Wand2 class="size-4" /> Generar pedidos automáticos</button>
    </PageHeader>
    <p v-if="!filters.supplier_id" class="mb-3 text-sm text-steel-500">"Generar pedidos automáticos" arma un pedido por proveedor (el principal de cada producto o el más barato). Para elegir artículos a mano, filtrá por proveedor.</p>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th class="w-8"></th><th>Producto</th><th>Proveedor</th><th class="num">Stock</th><th class="num">Mínimo</th><th class="num">Vendido 30 días</th><th>Mejor costo</th><th class="num w-32">Pedir</th></tr></thead>
            <tbody>
                <tr v-for="i in items" :key="i.id">
                    <td><input type="checkbox" class="checkbox" :checked="picked.has(i.id)" @change="toggle(i.id)" :aria-label="'Incluir ' + i.name" /></td>
                    <td><Link :href="route('products.show', i.id)" class="link">{{ i.name }}</Link><div class="code">{{ i.code }}</div></td>
                    <td>{{ i.supplier?.name ?? '—' }}</td>
                    <td class="num font-semibold text-up">{{ qty(i.stock) }}</td>
                    <td class="num">{{ qty(i.min_stock) }}</td>
                    <td class="num">{{ qty(i.sold_30d) }}</td>
                    <td><span v-if="i.best_supplier">{{ i.best_supplier.name }} · {{ money(i.best_supplier.cost) }}</span></td>
                    <td class="num"><input v-model="qtys[i.id]" type="number" min="0" step="any" class="input w-24 text-right" /></td>
                </tr>
                <tr v-if="!items.length"><td colspan="8" class="py-8 text-center text-steel-500">No hay productos por debajo del mínimo.</td></tr>
            </tbody>
        </table>
    </div>
</template>
