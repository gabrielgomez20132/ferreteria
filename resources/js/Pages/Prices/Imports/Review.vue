<script setup>
import { computed, ref, reactive } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Search, Link2, Unlink, PackagePlus, Download, Printer, AlertTriangle } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Variation from '@/Components/Variation.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import { money, pct, datetime, toNum } from '@/lib/format';

const props = defineProps({
    import: Object, rows: Object, filters: Object, distribution: Array, selectedCount: Number,
    priceActions: Object, categories: Array, brands: Array, units: Array,
});
const imp = computed(() => props.import);
const editable = computed(() => imp.value.status === 'processed');

const TABS = [
    { key: 'all', label: 'Todas', count: () => imp.value.total_rows },
    { key: 'increase', label: 'Suben', count: () => imp.value.increased_rows },
    { key: 'decrease', label: 'Bajan', count: () => imp.value.decreased_rows },
    { key: 'alerts', label: 'Alertas', count: () => imp.value.alert_rows },
    { key: 'unmatched', label: 'Sin vincular', count: () => imp.value.unmatched_rows },
    { key: 'unchanged', label: 'Sin cambio', count: () => imp.value.unchanged_rows },
    { key: 'errors', label: 'Con error' },
    { key: 'created', label: 'Altas nuevas' },
];
const current = computed(() => props.filters.filter ?? 'all');
const search = ref(props.filters.search ?? '');
const sort = ref(props.filters.sort ?? 'row_number');

const go = (params) =>
    router.get(route('price-imports.review', imp.value.id), { ...props.filters, ...params, page: undefined }, { preserveState: true, preserveScroll: true });

// Franja de distribución: de las bajas (verde) a las subas fuertes (rojo)
const SEG_COLORS = ['#087443', '#3f9c6b', '#c3cad3', '#f2d27a', '#f2b705', '#e07a2f', '#c4441c', '#8f1d12'];
const distTotal = computed(() => props.distribution.reduce((a, d) => a + d.count, 0));

// Selección de filas
const toggle = (row) =>
    router.post(route('price-imports.select', imp.value.id), { ids: [row.id], selected: !row.selected }, { preserveScroll: true, preserveState: true });
const selectFilter = (selected) =>
    router.post(route('price-imports.select', imp.value.id), { selected, filter: current.value === 'all' ? null : current.value }, { preserveScroll: true, preserveState: true });

// Selección local de filas sin vincular (para dar de alta)
const unmatchedSel = reactive(new Set());
const toggleUnmatched = (id) => (unmatchedSel.has(id) ? unmatchedSel.delete(id) : unmatchedSel.add(id));

// Vincular
const linking = ref(null);
const linkPack = ref(1);
const linkTo = (product) =>
    router.post(route('price-imports.link', [imp.value.id, linking.value.id]), { product_id: product.id, pack_quantity: linkPack.value }, {
        preserveScroll: true,
        onSuccess: () => (linking.value = null),
    });
const unlink = (row) => router.post(route('price-imports.unlink', [imp.value.id, row.id]), {}, { preserveScroll: true });

// Alta de productos
const showCreate = ref(false);
const createForm = useForm({ all_unmatched: false, row_ids: [], category_id: '', brand_id: '', unit_id: '', markup: 40, vat_rate: 21, code_strategy: 'supplier_code', code_prefix: '' });
const openCreate = (all) => {
    createForm.all_unmatched = all;
    createForm.row_ids = [...unmatchedSel];
    showCreate.value = true;
};
const submitCreate = () =>
    createForm.post(route('price-imports.create-products', imp.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            unmatchedSel.clear();
        },
    });

// Aplicar
const showApply = ref(false);
const applyForm = useForm({
    price_action: imp.value.options?.price_action ?? 'keep_markup',
    custom_markup: imp.value.options?.custom_markup ?? null,
    cost_policy: imp.value.options?.cost_policy ?? 'always',
});
const submitApply = () => applyForm.post(route('price-imports.apply', imp.value.id), { onSuccess: () => (showApply.value = false) });

const priceDiff = (r) => (toNum(r.current_price) > 0 ? ((toNum(r.suggested_price) - toNum(r.current_price)) / toNum(r.current_price)) * 100 : null);
</script>

<template>
    <PageHeader :title="`Lista de ${imp.supplier.name}`" :back="route('price-imports.index')">
        <template #subtitle>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-steel-500">
                <span>{{ imp.file_name }}</span><StatusBadge :status="imp.status" />
                <span v-if="imp.applied_at">aplicada el {{ datetime(imp.applied_at) }}</span>
            </p>
        </template>
        <a :href="route('price-imports.export', imp.id)" class="btn-secondary"><Download class="size-4" /> Exportar revisión</a>
        <Link v-if="editable" :href="route('price-imports.show', imp.id)" class="btn-secondary">Cambiar columnas</Link>
        <button v-if="editable" class="btn-primary" :disabled="!selectedCount && !imp.unchanged_rows" @click="showApply = true">
            Aplicar {{ selectedCount }} cambios
        </button>
    </PageHeader>

    <div v-if="imp.status === 'applied'" class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-down/30 bg-down-bg px-4 py-3 text-sm">
        <span class="font-medium text-down">Lista aplicada.</span>
        <span class="text-steel-700">Los costos del proveedor y los precios seleccionados ya están actualizados.</span>
        <div class="ml-auto flex gap-2">
            <Link v-if="imp.price_update_id" :href="route('products.labels', { price_update_id: imp.price_update_id })" class="btn-secondary btn-sm"><Printer class="size-3.5" /> Imprimir etiquetas</Link>
            <Link v-if="imp.price_update_id" :href="route('prices.updates.show', imp.price_update_id)" class="btn-secondary btn-sm">Ver cambios aplicados</Link>
        </div>
    </div>

    <!-- Resumen -->
    <div class="panel mb-4">
        <div class="grid grid-cols-2 divide-steel-200 border-b border-steel-200 sm:grid-cols-4 lg:grid-cols-7 lg:divide-x">
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Artículos en la lista</div><div class="text-lg font-semibold tabular-nums">{{ imp.total_rows }}</div></div>
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Vinculados</div><div class="text-lg font-semibold tabular-nums">{{ imp.matched_rows }}</div></div>
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Suben</div><div class="text-lg font-semibold tabular-nums text-up">{{ imp.increased_rows }}</div></div>
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Bajan</div><div class="text-lg font-semibold tabular-nums text-down">{{ imp.decreased_rows }}</div></div>
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Sin vincular</div><div class="text-lg font-semibold tabular-nums">{{ imp.unmatched_rows }}</div></div>
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Alertas</div><div class="text-lg font-semibold tabular-nums" :class="imp.alert_rows && 'text-[#7a5b00]'">{{ imp.alert_rows }}</div></div>
            <div class="px-4 py-3"><div class="text-xs text-steel-500">Variación promedio</div><div class="text-lg font-semibold tabular-nums">{{ pct(imp.avg_variation, true) }}</div></div>
        </div>
        <div v-if="distTotal" class="px-4 py-3">
            <div class="flex h-7 w-full overflow-hidden rounded">
                <div
                    v-for="(d, i) in distribution" :key="d.label" v-show="d.count"
                    :style="{ width: (d.count / distTotal) * 100 + '%', background: SEG_COLORS[i] }"
                    class="flex min-w-0 items-center justify-center text-[11px] font-medium"
                    :class="i === 2 || i === 3 || i === 4 ? 'text-steel-900' : 'text-white'"
                    :title="`${d.label}: ${d.count}`"
                >
                    <span class="truncate px-1">{{ d.count }}</span>
                </div>
            </div>
            <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-steel-500">
                <span v-for="(d, i) in distribution" :key="d.label" class="flex items-center gap-1">
                    <span class="size-2.5 rounded-sm" :style="{ background: SEG_COLORS[i] }" />{{ d.label }}
                </span>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="flex flex-wrap items-center gap-1 border-b border-steel-200 px-2 pt-2">
            <button
                v-for="t in TABS" :key="t.key" @click="go({ filter: t.key })"
                class="-mb-px rounded-t-md border-b-2 px-3 py-2 text-sm"
                :class="current === t.key ? 'border-hazard-500 font-medium text-steel-900' : 'border-transparent text-steel-500 hover:text-steel-800'"
            >
                {{ t.label }}<span v-if="t.count" class="ml-1 text-xs text-steel-400">{{ t.count() }}</span>
            </button>
        </div>
        <div class="flex flex-wrap items-center gap-2 px-4 py-3">
            <form class="relative w-full max-w-xs" @submit.prevent="go({ search })">
                <Search class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-steel-400" />
                <input v-model="search" class="input pl-8" placeholder="Código o descripción del proveedor" />
            </form>
            <select v-model="sort" class="input w-auto" @change="go({ sort })">
                <option value="row_number">Orden de la lista</option>
                <option value="variation_desc">Mayor suba primero</option>
                <option value="variation_asc">Mayor baja primero</option>
            </select>
            <template v-if="editable">
                <div class="ml-auto flex flex-wrap gap-2">
                    <template v-if="current === 'unmatched'">
                        <button class="btn-secondary btn-sm" :disabled="!unmatchedSel.size" @click="openCreate(false)"><PackagePlus class="size-3.5" /> Dar de alta seleccionados ({{ unmatchedSel.size }})</button>
                        <button class="btn-secondary btn-sm" :disabled="!imp.unmatched_rows" @click="openCreate(true)">Dar de alta todos</button>
                    </template>
                    <template v-else>
                        <button class="btn-secondary btn-sm" @click="selectFilter(true)">Seleccionar {{ current === 'all' ? 'todos' : 'los de esta vista' }}</button>
                        <button class="btn-secondary btn-sm" @click="selectFilter(false)">Quitar selección</button>
                    </template>
                </div>
            </template>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="w-8"></th>
                        <th>Artículo del proveedor</th>
                        <th>Mi producto</th>
                        <th class="num">Precio lista</th>
                        <th class="num">Costo actual</th>
                        <th class="num">Costo nuevo</th>
                        <th class="num">Var.</th>
                        <th class="num">Precio venta</th>
                        <th class="num">Sugerido</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows.data" :key="r.id" :class="r.status === 'matched' && !r.selected && editable ? 'opacity-55' : ''">
                        <td>
                            <input v-if="r.status === 'matched'" type="checkbox" class="checkbox" :checked="r.selected" :disabled="!editable" @change="toggle(r)" :aria-label="'Aplicar fila ' + r.row_number" />
                            <input v-else-if="r.status === 'unmatched' && editable" type="checkbox" class="checkbox" :checked="unmatchedSel.has(r.id)" @change="toggleUnmatched(r.id)" :aria-label="'Seleccionar fila ' + r.row_number" />
                        </td>
                        <td class="max-w-72">
                            <div class="truncate" :title="r.description">{{ r.description ?? '—' }}</div>
                            <div class="code">Fila {{ r.row_number }} · {{ r.supplier_code ?? r.barcode }}<span v-if="r.brand"> · {{ r.brand }}</span></div>
                        </td>
                        <td class="max-w-64">
                            <template v-if="r.product">
                                <div class="flex items-center gap-1.5">
                                    <Link :href="route('products.show', r.product.id)" class="truncate link" :title="r.product.name">{{ r.product.name }}</Link>
                                    <button v-if="editable && r.status !== 'created'" class="text-steel-400 hover:text-up" title="Desvincular" @click="unlink(r)"><Unlink class="size-3.5" /></button>
                                </div>
                                <div class="code">
                                    {{ r.product.code }}
                                    <span v-if="r.match_type === 'barcode'"> · por cód. barras</span>
                                    <span v-else-if="r.match_type === 'internal_code'"> · por cód. interno</span>
                                    <span v-else-if="r.match_type === 'manual'"> · vinculado a mano</span>
                                </div>
                            </template>
                            <template v-else>
                                <StatusBadge :status="r.status" />
                                <span v-if="r.error" class="ml-1 text-xs text-up">{{ r.error }}</span>
                                <button v-if="editable && r.status === 'unmatched'" class="ml-2 inline-flex items-center gap-1 text-xs link" @click="linking = r; linkPack = 1"><Link2 class="size-3.5" /> Vincular</button>
                            </template>
                        </td>
                        <td class="num">{{ money(r.list_price) }}</td>
                        <td class="num text-steel-500">{{ r.product ? money(r.current_cost) : '' }}</td>
                        <td class="num font-medium">{{ money(r.new_cost) }}</td>
                        <td class="num">
                            <span v-if="r.is_alert" title="Variación fuera de lo normal: revisá que la lista no tenga un error"><AlertTriangle class="mr-1 inline size-3.5 text-hazard-600" /></span>
                            <Variation v-if="r.product" :value="r.variation_percent" />
                        </td>
                        <td class="num text-steel-500">{{ r.product ? money(r.current_price) : '' }}</td>
                        <td class="num">
                            <template v-if="r.product && r.status !== 'created'">
                                <div class="font-medium">{{ money(r.suggested_price) }}</div>
                                <div v-if="priceDiff(r) !== null && Math.abs(priceDiff(r)) >= 0.01" class="text-[11px]" :class="priceDiff(r) > 0 ? 'text-up' : 'text-down'">{{ pct(priceDiff(r), true) }}</div>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!rows.data.length"><td colspan="9" class="py-8 text-center text-steel-500">No hay filas en esta vista.</td></tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="rows" />
    </div>

    <!-- Vincular fila -->
    <Modal :show="!!linking" title="Vincular con un producto" width="max-w-xl" @close="linking = null">
        <div v-if="linking" class="space-y-3">
            <div class="rounded-md bg-steel-50 p-3 text-sm">
                <div class="font-medium">{{ linking.description }}</div>
                <div class="code">Código del proveedor: {{ linking.supplier_code }} · Precio lista {{ money(linking.list_price) }}</div>
            </div>
            <Field label="Unidades de mi producto que trae cada unidad del proveedor" hint="Ej.: el proveedor cotiza el rollo de 100 m y vos vendés por metro → 100">
                <input v-model="linkPack" type="number" min="0.0001" step="any" class="input w-32" />
            </Field>
            <Field label="Buscá tu producto">
                <ProductSearch autofocus @select="linkTo" />
            </Field>
            <p class="text-xs text-steel-500">El código del proveedor queda guardado: la próxima lista se vincula sola.</p>
        </div>
    </Modal>

    <!-- Alta de productos -->
    <Modal :show="showCreate" title="Dar de alta productos nuevos" @close="showCreate = false">
        <div class="space-y-3">
            <p class="text-sm text-steel-600">
                Se crearán {{ createForm.all_unmatched ? imp.unmatched_rows : createForm.row_ids.length }} productos con la descripción y el costo de la lista, vinculados a {{ imp.supplier.name }}.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Categoría"><select v-model="createForm.category_id" class="input"><option value="">Sin categoría</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
                <Field label="Marca"><select v-model="createForm.brand_id" class="input"><option value="">Sin marca</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <Field label="Unidad"><select v-model="createForm.unit_id" class="input"><option value="">—</option><option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }}</option></select></Field>
                <Field label="Margen %" :error="createForm.errors.markup"><input v-model="createForm.markup" type="number" step="0.01" class="input" /></Field>
                <Field label="IVA %"><select v-model="createForm.vat_rate" class="input"><option v-for="v in [21, 10.5, 27, 0]" :key="v" :value="v">{{ v }}%</option></select></Field>
                <Field label="Código interno">
                    <select v-model="createForm.code_strategy" class="input">
                        <option value="supplier_code">Igual al del proveedor</option>
                        <option value="sequence">Numeración automática</option>
                    </select>
                </Field>
                <Field v-if="createForm.code_strategy === 'supplier_code'" label="Prefijo (opcional)" hint="Ej.: DN-"><input v-model="createForm.code_prefix" class="input" maxlength="10" /></Field>
            </div>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showCreate = false">Cancelar</button>
            <button class="btn-primary" :disabled="createForm.processing" @click="submitCreate">Dar de alta</button>
        </template>
    </Modal>

    <!-- Aplicar -->
    <Modal :show="showApply" title="Aplicar la lista" @close="showApply = false">
        <div class="space-y-3 text-sm">
            <p>
                Se actualizará el costo de <strong>{{ selectedCount }}</strong> productos seleccionados y se registrará el precio de lista de
                {{ imp.supplier.name }} para todos los artículos vinculados.
            </p>
            <Field label="Precio de venta">
                <select v-model="applyForm.price_action" class="input">
                    <option v-for="(l, k) in priceActions" :key="k" :value="k">{{ l }}</option>
                </select>
            </Field>
            <Field v-if="applyForm.price_action === 'custom_markup'" label="Margen %"><input v-model="applyForm.custom_markup" type="number" step="0.01" class="input" /></Field>
            <Field label="Costo del producto">
                <select v-model="applyForm.cost_policy" class="input">
                    <option value="always">Tomar siempre el costo de esta lista</option>
                    <option value="main_supplier">Solo si es su proveedor principal</option>
                    <option value="best_cost">Usar el menor costo entre proveedores</option>
                </select>
            </Field>
            <p class="rounded-md bg-steel-50 p-2.5 text-xs text-steel-600">Los cambios quedan registrados en el historial y se pueden revertir.</p>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showApply = false">Cancelar</button>
            <button class="btn-primary" :disabled="applyForm.processing" @click="submitApply">{{ applyForm.processing ? 'Aplicando…' : 'Aplicar cambios' }}</button>
        </template>
    </Modal>
</template>
