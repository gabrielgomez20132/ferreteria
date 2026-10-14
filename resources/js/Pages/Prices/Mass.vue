<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import Variation from '@/Components/Variation.vue';
import { money, pct, toNum } from '@/lib/format';

const props = defineProps({ categories: Array, brands: Array, suppliers: Array, priceActions: Object, preview: Array, total: Number, form: Object });

const form = useForm({
    filters: { search: '', category_id: '', brand_id: '', supplier_id: '', ...(props.form?.filters || {}) },
    target: props.form?.target ?? 'cost',
    percent: props.form?.percent ?? 10,
    price_action: props.form?.price_action ?? 'keep_markup',
    custom_markup: props.form?.custom_markup ?? null,
    excluded: [],
});
const excluded = ref(new Set());

const previewRun = () => form.post(route('prices.mass.preview'), { preserveScroll: true, preserveState: true, onSuccess: () => excluded.value.clear() });
const apply = () => {
    const n = props.total - excluded.value.size;
    if (!confirm(`¿Aplicar el cambio a ${n} productos?`)) return;
    form.transform((d) => ({ ...d, excluded: [...excluded.value] })).post(route('prices.mass.apply'));
};
const toggle = (id) => (excluded.value.has(id) ? excluded.value.delete(id) : excluded.value.add(id), (excluded.value = new Set(excluded.value)));
const varOf = (a, b) => (toNum(a) > 0 ? ((toNum(b) - toNum(a)) / toNum(a)) * 100 : null);
const percentLabel = computed(() => ({ cost: 'Variación del costo %', price: 'Variación del precio %', markup: 'Margen a fijar %' })[form.target]);
</script>

<template>
    <PageHeader title="Actualización masiva de precios" subtitle="Aplicá un aumento o una baja a un grupo de productos: por proveedor, categoría, marca o búsqueda. Primero ves el resultado, después confirmás." />

    <div class="grid gap-4 xl:grid-cols-[340px_1fr]">
        <form class="panel h-fit space-y-3 p-4" @submit.prevent="previewRun">
            <h2 class="panel-title">Qué productos</h2>
            <Field label="Proveedor"><select v-model="form.filters.supplier_id" class="input"><option value="">Todos</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
            <Field label="Categoría"><select v-model="form.filters.category_id" class="input"><option value="">Todas</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></Field>
            <Field label="Marca"><select v-model="form.filters.brand_id" class="input"><option value="">Todas</option><option v-for="b in brands" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            <Field label="Descripción o código contiene"><input v-model="form.filters.search" class="input" placeholder="Ej.: cable" /></Field>

            <h2 class="panel-title pt-2">Qué cambio</h2>
            <div class="grid grid-cols-3 gap-1 rounded-md bg-steel-100 p-1 text-sm">
                <button v-for="(l, k) in { cost: 'Costo', price: 'Precio', markup: 'Margen' }" :key="k" type="button" class="rounded py-1" :class="form.target === k ? 'bg-white font-medium shadow-sm' : 'text-steel-600'" @click="form.target = k">{{ l }}</button>
            </div>
            <Field :label="percentLabel" :error="form.errors.percent" :hint="form.target === 'markup' ? 'Fija este margen y recalcula el precio desde el costo' : 'Usá números negativos para bajar'">
                <input v-model="form.percent" type="number" step="0.01" class="input" required />
            </Field>
            <Field v-if="form.target === 'cost'" label="Precio de venta">
                <select v-model="form.price_action" class="input"><option v-for="(l, k) in priceActions" :key="k" :value="k">{{ l }}</option></select>
            </Field>
            <Field v-if="form.target === 'cost' && form.price_action === 'custom_markup'" label="Margen %"><input v-model="form.custom_markup" type="number" step="0.01" class="input" /></Field>
            <button class="btn-dark w-full" :disabled="form.processing">Ver resultado</button>
        </form>

        <div class="panel min-w-0">
            <div class="panel-head">
                <h2 class="panel-title">{{ preview ? `Resultado: ${total} productos` : 'Vista previa' }}</h2>
                <button v-if="preview?.length" class="btn-primary" :disabled="form.processing" @click="apply">Aplicar a {{ total - excluded.size }} productos</button>
            </div>
            <div v-if="!preview" class="p-8 text-center text-sm text-steel-500">Elegí los filtros y el porcentaje, y tocá “Ver resultado”.</div>
            <div v-else class="overflow-x-auto">
                <p v-if="total > preview.length" class="px-4 pt-3 text-xs text-steel-500">Se muestran los primeros {{ preview.length }}; el cambio se aplica a los {{ total }}.</p>
                <table class="table">
                    <thead><tr><th class="w-8"></th><th>Producto</th><th class="num">Costo</th><th class="num">Costo nuevo</th><th class="num">Precio</th><th class="num">Precio nuevo</th><th class="num">Var.</th><th class="num">Margen</th></tr></thead>
                    <tbody>
                        <tr v-for="p in preview" :key="p.id" :class="excluded.has(p.id) && 'opacity-45'">
                            <td><input type="checkbox" class="checkbox" :checked="!excluded.has(p.id)" @change="toggle(p.id)" :aria-label="'Incluir ' + p.name" /></td>
                            <td>{{ p.name }}<div class="code">{{ p.code }}</div></td>
                            <td class="num text-steel-500">{{ money(p.old_cost) }}</td>
                            <td class="num">{{ money(p.new_cost) }}</td>
                            <td class="num text-steel-500">{{ money(p.old_price) }}</td>
                            <td class="num font-medium">{{ money(p.new_price) }}</td>
                            <td class="num"><Variation :value="varOf(p.old_price, p.new_price)" /></td>
                            <td class="num">{{ pct(p.new_markup) }}</td>
                        </tr>
                        <tr v-if="!preview.length"><td colspan="8" class="py-6 text-center text-steel-500">Ningún producto coincide con los filtros.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
