<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import { money, pct, roundPrice, toNum } from '@/lib/format';

const props = defineProps({ product: Object, categories: Array, brands: Array, units: Array, suppliers: Array, priceLists: Array, rounding: Object, initialStock: Boolean });
const p = props.product;
const editing = !!p.id;

const form = useForm({
    code: p.code ?? '', barcode: p.barcode ?? '', name: p.name ?? '', description: p.description ?? '',
    category_id: p.category_id ?? '', brand_id: p.brand_id ?? '', unit_id: p.unit_id ?? '', supplier_id: p.supplier_id ?? '',
    cost: toNum(p.cost), vat_rate: toNum(p.vat_rate ?? 21), markup: toNum(p.markup), price: toNum(p.price),
    allow_fraction: !!p.allow_fraction, purchase_unit_name: p.purchase_unit_name ?? '', purchase_unit_factor: toNum(p.purchase_unit_factor) || 1,
    min_stock: toNum(p.min_stock), location: p.location ?? '', track_stock: p.track_stock ?? true, is_active: p.is_active ?? true, notes: p.notes ?? '',
    codes: (p.codes ?? []).map((c) => ({ code: c.code, type: c.type })),
    list_prices: Object.fromEntries(props.priceLists.map((l) => [l.id, (p.prices ?? []).find((x) => x.price_list_id === l.id)?.price ?? ''])),
    supplier_products: (p.supplier_products ?? []).map((s) => ({ supplier_id: s.supplier_id, supplier_code: s.supplier_code, list_price: toNum(s.list_price), pack_quantity: toNum(s.pack_quantity) || 1 })),
    initial_stock: '',
    type: p.type ?? 'simple',
    components: (p.components ?? []).map((c) => ({ component_id: c.component_id, code: c.component?.code, name: c.component?.name, cost: toNum(c.component?.cost), quantity: toNum(c.quantity) })),
});

// Kits y productos fabricados: componentes / insumos
const TYPES = {
    simple: ['Producto simple', 'Se compra y se vende tal cual.'],
    kit: ['Kit / combo', 'Se vende como una unidad y descuenta el stock de cada componente. No lleva stock propio.'],
    manufactured: ['Producto fabricado', 'Tiene stock propio y se produce con una orden de producción que consume los insumos.'],
};
const addComponent = (c) => {
    if (c.id === p.id || form.components.some((x) => x.component_id === c.id)) return;
    form.components.push({ component_id: c.id, code: c.code, name: c.name, cost: toNum(c.cost), quantity: 1 });
};
const componentsCost = computed(() => form.components.reduce((a, c) => a + toNum(c.cost) * toNum(c.quantity), 0));
const useComponentsCost = () => {
    form.cost = Math.round(componentsCost.value * 10000) / 10000;
    calcPrice();
};

// Precio ↔ margen: al cambiar costo o margen se recalcula el precio; al editar el precio, el margen
const calcPrice = () => (form.price = roundPrice(toNum(form.cost) * (1 + toNum(form.markup) / 100) * (1 + toNum(form.vat_rate) / 100), props.rounding.step, props.rounding.mode));
const calcMarkup = () => {
    const net = toNum(form.price) / (1 + toNum(form.vat_rate) / 100);
    form.markup = toNum(form.cost) > 0 ? Math.round((net / toNum(form.cost) - 1) * 10000) / 100 : 0;
};
const realMargin = computed(() => {
    const net = toNum(form.price) / (1 + toNum(form.vat_rate) / 100);
    return net > 0 ? ((net - toNum(form.cost)) / net) * 100 : 0;
});
watch(() => form.category_id, (id) => {
    const c = props.categories.find((x) => x.id === Number(id));
    if (!editing && c?.default_markup) {
        form.markup = toNum(c.default_markup);
        calcPrice();
    }
});
const unit = computed(() => props.units.find((u) => u.id === Number(form.unit_id)));
watch(unit, (u) => !editing && u && (form.allow_fraction = !!u.allows_decimals));

const addSupplier = () => form.supplier_products.push({ supplier_id: form.supplier_id || props.suppliers[0]?.id, supplier_code: '', list_price: 0, pack_quantity: toNum(form.purchase_unit_factor) || 1 });
const spCost = (sp) => {
    const s = props.suppliers.find((x) => x.id === Number(sp.supplier_id));
    if (!s || !toNum(sp.list_price)) return null;
    let c = toNum(sp.list_price);
    for (const d of [s.discount_1, s.discount_2, s.discount_3]) c *= 1 - toNum(d) / 100;
    if (s.prices_include_vat) c /= 1 + toNum(form.vat_rate) / 100;
    return c / (toNum(sp.pack_quantity) || 1);
};
const useSupplierCost = (sp) => {
    form.cost = Math.round(spCost(sp) * 10000) / 10000;
    calcPrice();
};

const submit = () => {
    form.transform((d) => ({
        ...d,
        category_id: d.category_id || null, brand_id: d.brand_id || null, unit_id: d.unit_id || null, supplier_id: d.supplier_id || null,
        initial_stock: d.initial_stock === '' ? null : d.initial_stock,
        components: d.type === 'simple' ? [] : d.components.map((c) => ({ component_id: c.component_id, quantity: c.quantity })),
    }));
    editing ? form.put(route('products.update', p.id)) : form.post(route('products.store'));
};
</script>

<template>
    <PageHeader :title="editing ? `Editar ${p.name}` : 'Nuevo producto'" :back="editing ? route('products.show', p.id) : route('products.index')">
        <button class="btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
    </PageHeader>

    <form class="grid gap-4 xl:grid-cols-[1fr_380px]" @submit.prevent="submit">
        <div class="space-y-4">
            <div class="panel grid gap-3 p-4 sm:grid-cols-3">
                <Field label="Código interno *" :error="form.errors.code"><input v-model="form.code" class="input" /></Field>
                <Field label="Código de barras" :error="form.errors.barcode"><input v-model="form.barcode" class="input" /></Field>
                <Field label="Ubicación"><input v-model="form.location" class="input" placeholder="Pasillo 3 - Estante B" /></Field>
                <Field class="sm:col-span-3" label="Descripción *" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
                <Field label="Categoría"><select v-model="form.category_id" class="input"><option value="">—</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
                <Field label="Marca"><select v-model="form.brand_id" class="input"><option value="">—</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
                <Field label="Proveedor principal"><select v-model="form.supplier_id" class="input"><option value="">—</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
            </div>

            <div class="panel p-4">
                <h2 class="panel-title mb-3">Tipo de producto</h2>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label v-for="(t, k) in TYPES" :key="k" class="cursor-pointer rounded-md border p-3 text-sm" :class="form.type === k ? 'border-steel-800 bg-steel-50' : 'border-steel-200 hover:border-steel-400'">
                        <input v-model="form.type" type="radio" :value="k" class="sr-only" />
                        <div class="font-medium">{{ t[0] }}</div>
                        <div class="mt-0.5 text-xs text-steel-500">{{ t[1] }}</div>
                    </label>
                </div>
                <div v-if="form.type !== 'simple'" class="mt-4">
                    <div class="mb-2 text-sm font-medium">{{ form.type === 'kit' ? 'Componentes del kit' : 'Insumos por unidad producida' }}</div>
                    <ProductSearch show-cost placeholder="Agregar componente…" @select="addComponent" />
                    <table v-if="form.components.length" class="table mt-2">
                        <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Costo</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="(c, i) in form.components" :key="c.component_id">
                                <td>{{ c.name }}<div class="code">{{ c.code }}</div></td>
                                <td class="num"><input v-model="c.quantity" type="number" step="any" min="0.0001" class="input ml-auto w-24 text-right" /></td>
                                <td class="num">{{ money(toNum(c.cost) * toNum(c.quantity)) }}</td>
                                <td><button type="button" class="text-steel-400 hover:text-up" @click="form.components.splice(i, 1)" aria-label="Quitar"><X class="size-4" /></button></td>
                            </tr>
                        </tbody>
                        <tfoot><tr><td colspan="2" class="px-3 py-2 text-right text-sm">Costo de componentes</td><td class="num px-3 font-semibold">{{ money(componentsCost) }}</td><td><button type="button" class="text-xs whitespace-nowrap link" @click="useComponentsCost">Usar como costo</button></td></tr></tfoot>
                    </table>
                    <p v-if="form.errors.components" class="mt-1 text-xs text-up">{{ form.errors.components }}</p>
                </div>
            </div>

            <div class="panel p-4">
                <h2 class="panel-title mb-3">Unidad de venta y compra</h2>
                <div class="grid gap-3 sm:grid-cols-4">
                    <Field label="Se vende por"><select v-model="form.unit_id" class="input"><option value="">—</option><option v-for="u in units" :key="u.id" :value="u.id">{{ u.name }} ({{ u.abbreviation }})</option></select></Field>
                    <Field label="Se compra por" hint="Ej.: Rollo, Caja, Barra"><input v-model="form.purchase_unit_name" class="input" /></Field>
                    <Field :label="`Contiene (${unit?.abbreviation ?? 'unidades'})`" :error="form.errors.purchase_unit_factor"><input v-model="form.purchase_unit_factor" type="number" step="any" min="0.0001" class="input" /></Field>
                    <Field label="Stock mínimo"><input v-model="form.min_stock" type="number" step="any" min="0" class="input" /></Field>
                </div>
                <div class="mt-3 flex flex-wrap gap-5 text-sm">
                    <label class="flex items-center gap-2"><input v-model="form.allow_fraction" type="checkbox" class="checkbox" /> Se puede vender fraccionado (metros, kilos, litros)</label>
                    <label class="flex items-center gap-2"><input v-model="form.track_stock" type="checkbox" class="checkbox" /> Controlar stock</label>
                    <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" class="checkbox" /> Activo</label>
                </div>
                <p v-if="form.purchase_unit_name && toNum(form.purchase_unit_factor) > 1" class="mt-2 text-xs text-steel-500">
                    Al comprar 1 {{ form.purchase_unit_name }} ingresan {{ form.purchase_unit_factor }} {{ unit?.abbreviation ?? 'unidades' }} al stock.
                </p>
            </div>

            <div class="panel p-4">
                <div class="mb-3 flex items-center justify-between"><h2 class="panel-title">Proveedores y códigos</h2><button type="button" class="btn-secondary btn-sm" @click="addSupplier"><Plus class="size-3.5" /> Proveedor</button></div>
                <div v-for="(sp, i) in form.supplier_products" :key="i" class="mb-2 grid grid-cols-[1fr_120px_120px_90px_auto] items-end gap-2">
                    <Field label="Proveedor"><select v-model="sp.supplier_id" class="input"><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
                    <Field label="Su código"><input v-model="sp.supplier_code" class="input" /></Field>
                    <Field label="Precio lista"><input v-model="sp.list_price" type="number" step="0.0001" class="input" /></Field>
                    <Field label="Unid. x bulto"><input v-model="sp.pack_quantity" type="number" step="any" class="input" /></Field>
                    <div class="flex items-center gap-1 pb-1">
                        <button v-if="spCost(sp)" type="button" class="text-xs whitespace-nowrap link" @click="useSupplierCost(sp)" :title="'Costo neto ' + money(spCost(sp))">Usar {{ money(spCost(sp)) }}</button>
                        <button type="button" class="text-steel-400 hover:text-up" @click="form.supplier_products.splice(i, 1)" aria-label="Quitar"><X class="size-4" /></button>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between"><span class="text-sm font-medium">Códigos alternativos</span><button type="button" class="btn-ghost btn-sm" @click="form.codes.push({ code: '', type: 'barcode' })"><Plus class="size-3.5" /> Código</button></div>
                <div v-for="(c, i) in form.codes" :key="'c' + i" class="mt-2 flex gap-2">
                    <input v-model="c.code" class="input" placeholder="Código" />
                    <select v-model="c.type" class="input w-48"><option value="barcode">Código de barras</option><option value="alternative">Código alternativo</option></select>
                    <button type="button" class="text-steel-400 hover:text-up" @click="form.codes.splice(i, 1)" aria-label="Quitar"><X class="size-4" /></button>
                </div>
            </div>

            <Field label="Notas internas"><textarea v-model="form.notes" rows="2" class="input" /></Field>
        </div>

        <div class="space-y-4">
            <div class="panel space-y-3 p-4">
                <h2 class="panel-title">Precio</h2>
                <div class="grid grid-cols-2 gap-3">
                    <Field label="Costo neto (sin IVA)" :error="form.errors.cost"><input v-model="form.cost" type="number" step="0.0001" min="0" class="input" @input="calcPrice" /></Field>
                    <Field label="IVA"><select v-model="form.vat_rate" class="input" @change="calcPrice"><option v-for="v in [21, 10.5, 27, 5, 2.5, 0]" :key="v" :value="v">{{ v }}%</option></select></Field>
                    <Field label="Margen objetivo % s/costo" :error="form.errors.markup"><input v-model="form.markup" type="number" step="0.01" class="input" @input="calcPrice" /></Field>
                    <Field label="Precio final" :error="form.errors.price"><input v-model="form.price" type="number" step="0.01" min="0" class="input text-lg font-semibold" @input="calcMarkup" /></Field>
                </div>
                <p class="text-xs text-steel-500">Margen real sobre el precio sin IVA: {{ pct(realMargin) }}. Redondeo configurado: {{ rounding.step ? 'a $' + rounding.step : 'sin redondeo' }}.</p>
            </div>
            <div v-if="priceLists.length" class="panel space-y-2 p-4">
                <h2 class="panel-title">Precios especiales por lista</h2>
                <p class="text-xs text-steel-500">Vacío = se calcula con el ajuste de la lista.</p>
                <Field v-for="l in priceLists" :key="l.id" :label="`${l.name} (${toNum(l.adjustment_percent) > 0 ? '+' : ''}${toNum(l.adjustment_percent)}% → ${money(roundPrice(toNum(form.price) * (1 + toNum(l.adjustment_percent) / 100), rounding.step, rounding.mode))})`">
                    <input v-model="form.list_prices[l.id]" type="number" step="0.01" min="0" class="input" />
                </Field>
            </div>
            <div v-if="initialStock && form.type !== 'kit'" class="panel p-4">
                <Field label="Stock inicial en esta sucursal"><input v-model="form.initial_stock" type="number" step="any" class="input" /></Field>
            </div>
        </div>
    </form>
</template>
