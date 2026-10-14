<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Plus, Download, Upload, Printer, Search } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { money, qty, pct, date } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ products: Object, filters: Object, categories: Array, brands: Array, suppliers: Array });
const can = useCan();
const f = reactive({ search: '', category_id: '', brand_id: '', supplier_id: '', status: 'active', stock: '', sort: 'name', ...props.filters });
const apply = () => router.get(route('products.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, preserveScroll: true });
const selected = ref(new Set());
const toggle = (id) => {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
};
</script>

<template>
    <PageHeader title="Productos" :subtitle="`${products.total} productos`">
        <Link v-if="selected.size" :href="route('products.labels', { ids: [...selected].join(',') })" class="btn-secondary"><Printer class="size-4" /> Etiquetas ({{ selected.size }})</Link>
        <a :href="route('products.export', f)" class="btn-secondary"><Download class="size-4" /> Exportar Excel</a>
        <Link v-if="can('products.import')" :href="route('products.import')" class="btn-secondary"><Upload class="size-4" /> Importar</Link>
        <Link v-if="can('products.manage')" :href="route('products.create')" class="btn-primary"><Plus class="size-4" /> Nuevo producto</Link>
    </PageHeader>

    <div class="panel">
        <form class="flex flex-wrap items-end gap-2 border-b border-steel-200 px-4 py-3" @submit.prevent="apply">
            <div class="relative min-w-60 flex-1">
                <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-steel-400" />
                <input v-model="f.search" class="input pl-8" placeholder="Código, código de barras, código de proveedor o descripción" />
            </div>
            <select v-model="f.category_id" class="input w-auto" @change="apply"><option value="">Todas las categorías</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            <select v-model="f.brand_id" class="input w-auto" @change="apply"><option value="">Todas las marcas</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select>
            <select v-model="f.supplier_id" class="input w-auto" @change="apply"><option value="">Todos los proveedores</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
            <select v-model="f.stock" class="input w-auto" @change="apply">
                <option value="">Todo el stock</option><option value="low">Bajo mínimo</option><option value="zero">Sin stock</option><option value="outdated">Precio desactualizado</option>
            </select>
            <select v-model="f.status" class="input w-auto" @change="apply"><option value="active">Activos</option><option value="inactive">Inactivos</option><option value="all">Todos</option></select>
            <select v-model="f.sort" class="input w-auto" @change="apply">
                <option value="name">Por nombre</option><option value="code">Por código</option><option value="price_desc">Mayor precio</option><option value="price_asc">Menor precio</option><option value="updated">Precio actualizado recientemente</option><option value="stock">Menor stock</option>
            </select>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-8"></th><th>Producto</th><th>Categoría / marca</th><th>Proveedor</th>
                        <th v-if="can('products.view_cost')" class="num">Costo</th>
                        <th v-if="can('products.view_cost')" class="num">Margen</th>
                        <th class="num">Precio</th><th class="num">Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products.data" :key="p.id" :class="!p.is_active && 'opacity-60'">
                        <td><input type="checkbox" class="checkbox" :checked="selected.has(p.id)" @change="toggle(p.id)" :aria-label="'Seleccionar ' + p.name" /></td>
                        <td>
                            <Link :href="route('products.show', p.id)" class="font-medium link">{{ p.name }}</Link>
                            <div class="code">{{ p.code }}<span v-if="p.barcode"> · {{ p.barcode }}</span></div>
                        </td>
                        <td class="text-steel-600">{{ p.category ?? '—' }}<div class="text-xs text-steel-400">{{ p.brand }}</div></td>
                        <td class="text-steel-600">{{ p.supplier ?? '—' }}</td>
                        <td v-if="can('products.view_cost')" class="num text-steel-600">{{ money(p.cost) }}</td>
                        <td v-if="can('products.view_cost')" class="num">
                            <span :class="p.outdated ? 'badge-up' : 'text-steel-600'" :title="p.outdated ? `Margen objetivo ${pct(p.markup)} · sugerido ${money(p.suggested_price)}` : ''">{{ pct(p.margin) }}</span>
                        </td>
                        <td class="num font-semibold">{{ money(p.price) }}<div class="text-[11px] font-normal text-steel-400">{{ date(p.price_updated_at) }}</div></td>
                        <td class="num" :class="p.min_stock > 0 && p.stock <= p.min_stock ? 'font-semibold text-up' : ''">{{ qty(p.stock) }} <span class="text-xs text-steel-400">{{ p.unit }}</span></td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="8" class="py-8 text-center text-steel-500">No hay productos que coincidan con la búsqueda.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="products" />
    </div>
</template>
