<script setup>
import { computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import { money, toNum } from '@/lib/format';

const props = defineProps({ import: Object, preview: Object, priceActions: Object });
const imp = props.import;
const o = imp.options || {};

const FIELDS = [
    { key: 'code', label: 'Código del proveedor', required: true },
    { key: 'price', label: 'Precio', required: true },
    { key: 'description', label: 'Descripción' },
    { key: 'barcode', label: 'Código de barras' },
    { key: 'brand', label: 'Marca' },
    { key: 'pack', label: 'Unidades por bulto' },
];

const form = useForm({
    mapping: Object.fromEntries(FIELDS.map((f) => [f.key, props.preview.suggested?.[f.key] ?? ''])),
    options: {
        header_row: props.preview.header_row ?? o.header_row ?? 1,
        prices_include_vat: !!o.prices_include_vat,
        default_vat: o.default_vat ?? 21,
        use_supplier_discounts: o.use_supplier_discounts ?? true,
        extra_discount_1: o.extra_discount_1 ?? 0,
        extra_discount_2: o.extra_discount_2 ?? 0,
        surcharge_percent: o.surcharge_percent ?? 0,
        exchange_rate: o.exchange_rate ?? 1,
        match_by_barcode: o.match_by_barcode ?? true,
        match_by_internal_code: o.match_by_internal_code ?? false,
        price_action: o.price_action ?? 'keep_markup',
        custom_markup: o.custom_markup ?? null,
        cost_policy: o.cost_policy ?? 'always',
        alert_threshold: o.alert_threshold ?? 15,
    },
});

// Al cambiar la fila de encabezado se vuelven a reconocer las columnas por su título
const PATTERNS = {
    barcode: /(ean|barra|gtin|upc)/i,
    code: /^(c[oó]d(igo|\.)?|art(i|í)culo|ref(erencia)?|sku|item)/i,
    description: /(desc|detalle|producto|nombre)/i,
    price: /(precio|p\.? ?lista|lista|neto|costo|importe|valor|\$)/i,
    brand: /(marca)/i,
    pack: /(bulto|pack|x ?caja|unid(ades)? ?x|cant\.? ?x)/i,
};
watch(
    () => form.options.header_row,
    (n) => {
        const row = props.preview.rows.find((r) => r.number === n);
        if (!row) return;
        const found = {};
        for (const [col, val] of Object.entries(row.cells)) {
            if (typeof val !== 'string') continue;
            for (const [field, re] of Object.entries(PATTERNS)) {
                if (!found[field] && re.test(val)) {
                    found[field] = col;
                    break;
                }
            }
        }
        if (Object.keys(found).length >= 2) for (const f of FIELDS) form.mapping[f.key] = found[f.key] ?? '';
    },
);

const colField = computed(() => {
    const m = {};
    for (const f of FIELDS) if (form.mapping[f.key]) m[form.mapping[f.key]] = f.label;
    return m;
});

const supplierDiscounts = computed(() => [imp.supplier.discount_1, imp.supplier.discount_2, imp.supplier.discount_3].map(toNum).filter((x) => x));

// Ejemplo de cálculo con la primera fila de datos
const sample = computed(() => {
    const row = props.preview.rows.find((r) => r.number > form.options.header_row && r.cells[form.mapping.price] !== undefined && r.cells[form.mapping.price] !== null);
    if (!row || !form.mapping.price) return null;
    const raw = row.cells[form.mapping.price];
    let price = typeof raw === 'number' ? raw : parseFloat(String(raw).replace(/[^\d,.-]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.'));
    if (!Number.isFinite(price)) return { row: row.number, error: `“${raw}” no es un precio válido` };
    const steps = [{ label: 'Precio de lista', value: price }];
    let c = price * (toNum(form.options.exchange_rate) || 1);
    if (toNum(form.options.exchange_rate) !== 1) steps.push({ label: `× cotización ${form.options.exchange_rate}`, value: c });
    const discounts = [...(form.options.use_supplier_discounts ? supplierDiscounts.value : []), toNum(form.options.extra_discount_1), toNum(form.options.extra_discount_2)].filter((d) => d);
    for (const d of discounts) {
        c *= 1 - d / 100;
        steps.push({ label: `− ${d}%`, value: c });
    }
    if (toNum(form.options.surcharge_percent)) {
        c *= 1 + toNum(form.options.surcharge_percent) / 100;
        steps.push({ label: `+ ${form.options.surcharge_percent}% recargo`, value: c });
    }
    if (form.options.prices_include_vat) {
        c /= 1 + toNum(form.options.default_vat) / 100;
        steps.push({ label: `sin IVA (${form.options.default_vat}%)`, value: c });
    }
    const pack = toNum(row.cells[form.mapping.pack]) || 1;
    if (pack > 1) {
        c /= pack;
        steps.push({ label: `÷ ${pack} unidades`, value: c });
    }
    return { row: row.number, code: row.cells[form.mapping.code], desc: row.cells[form.mapping.description], steps, cost: c };
});

const display = (v) => (v === null || v === undefined ? '' : typeof v === 'number' ? v.toLocaleString('es-AR', { maximumFractionDigits: 4 }) : v);
const submit = () => form.post(route('price-imports.process', imp.id));
const cancel = () => confirm('¿Descartar esta importación?') && router.post(route('price-imports.cancel', imp.id));
</script>

<template>
    <PageHeader :title="`Lista de ${imp.supplier.name}`" :subtitle="`Paso 2 de 3 · Indicá qué columna es cada dato. Archivo: ${imp.file_name}`" :back="route('price-imports.index')">
        <button class="btn-ghost" @click="cancel">Descartar</button>
        <button class="btn-primary" :disabled="form.processing || !form.mapping.price || (!form.mapping.code && !form.mapping.barcode)" @click="submit">
            {{ form.processing ? 'Procesando la lista…' : 'Procesar lista' }}
        </button>
    </PageHeader>

    <div class="grid gap-4 xl:grid-cols-[1fr_340px]">
        <div class="min-w-0 space-y-4">
            <div class="panel p-4">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 2xl:grid-cols-6">
                    <Field v-for="f in FIELDS" :key="f.key" :label="f.label + (f.required ? ' *' : '')" :error="form.errors['mapping.' + f.key]">
                        <select v-model="form.mapping[f.key]" class="input" :class="f.required && !form.mapping[f.key] && 'border-hazard-500'">
                            <option value="">—</option>
                            <option v-for="c in preview.columns" :key="c" :value="c">Columna {{ c }}</option>
                        </select>
                    </Field>
                </div>
                <p class="mt-2 text-xs text-steel-500">Hacé clic en el número de una fila para marcarla como encabezado: las filas anteriores se ignoran.</p>
            </div>

            <div class="panel overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table text-xs">
                        <thead>
                            <tr>
                                <th class="w-12">Fila</th>
                                <th v-for="c in preview.columns" :key="c" :class="colField[c] && 'bg-hazard-300/40 text-steel-900'">
                                    {{ c }}<div v-if="colField[c]" class="font-medium">{{ colField[c] }}</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in preview.rows" :key="r.number" :class="r.number <= form.options.header_row ? 'text-steel-400' : ''">
                                <td>
                                    <button type="button" class="rounded px-1.5 py-0.5 hover:bg-steel-200" :class="r.number === form.options.header_row && 'bg-steel-800 text-white hover:bg-steel-700'" @click="form.options.header_row = r.number" :title="'Usar fila ' + r.number + ' como encabezado'">
                                        {{ r.number }}
                                    </button>
                                </td>
                                <td v-for="c in preview.columns" :key="c" class="max-w-64 truncate" :class="[colField[c] && r.number > form.options.header_row && 'bg-hazard-300/10', r.number === form.options.header_row && 'font-semibold']">
                                    {{ display(r.cells[c]) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-steel-200 px-4 py-2 text-xs text-steel-500">Vista previa de las primeras {{ preview.rows.length }} filas.</div>
            </div>
        </div>

        <div class="space-y-4">
            <div v-if="sample" class="panel p-4">
                <h3 class="text-sm font-semibold">Así se calcula el costo (fila {{ sample.row }})</h3>
                <p v-if="sample.error" class="mt-1 text-sm text-up">{{ sample.error }}</p>
                <template v-else>
                    <p class="mt-0.5 truncate text-xs text-steel-500">{{ sample.code }} {{ sample.desc }}</p>
                    <div class="mt-2 space-y-0.5 text-sm">
                        <div v-for="(s, i) in sample.steps" :key="i" class="flex justify-between"><span class="text-steel-600">{{ s.label }}</span><span class="tabular-nums">{{ money(s.value) }}</span></div>
                        <div class="mt-1 flex justify-between border-t border-steel-200 pt-1 font-semibold"><span>Costo neto</span><span class="tabular-nums">{{ money(sample.cost) }}</span></div>
                    </div>
                </template>
            </div>

            <div class="panel space-y-3 p-4">
                <h3 class="text-sm font-semibold">Cómo leer los precios</h3>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.options.prices_include_vat" type="checkbox" class="checkbox" /> Los precios incluyen IVA</label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.options.use_supplier_discounts" type="checkbox" class="checkbox" />
                    Aplicar bonificaciones del proveedor <span class="text-steel-500">({{ supplierDiscounts.length ? supplierDiscounts.join('% + ') + '%' : 'ninguna' }})</span>
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <Field label="Bonif. extra 1 %"><input v-model="form.options.extra_discount_1" type="number" step="0.01" class="input" /></Field>
                    <Field label="Bonif. extra 2 %"><input v-model="form.options.extra_discount_2" type="number" step="0.01" class="input" /></Field>
                    <Field label="Recargo % (flete)"><input v-model="form.options.surcharge_percent" type="number" step="0.01" class="input" /></Field>
                    <Field label="Cotización" hint="Para listas en USD"><input v-model="form.options.exchange_rate" type="number" step="0.0001" min="0.0001" class="input" /></Field>
                </div>
            </div>

            <div class="panel space-y-3 p-4">
                <h3 class="text-sm font-semibold">Cómo encontrar tus productos</h3>
                <p class="text-xs text-steel-500">Siempre se busca primero por el código del proveedor ya vinculado.</p>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.options.match_by_barcode" type="checkbox" class="checkbox" /> También por código de barras</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.options.match_by_internal_code" type="checkbox" class="checkbox" /> También por mi código interno</label>
            </div>

            <div class="panel space-y-3 p-4">
                <h3 class="text-sm font-semibold">Qué hacer con los precios de venta</h3>
                <Field label="Cuando cambia el costo">
                    <select v-model="form.options.price_action" class="input">
                        <option v-for="(l, k) in priceActions" :key="k" :value="k">{{ l }}</option>
                    </select>
                </Field>
                <Field v-if="form.options.price_action === 'custom_markup'" label="Margen a aplicar %"><input v-model="form.options.custom_markup" type="number" step="0.01" class="input" /></Field>
                <Field label="Costo del producto" hint="Si un artículo lo comprás a varios proveedores">
                    <select v-model="form.options.cost_policy" class="input">
                        <option value="always">Tomar siempre el costo de esta lista</option>
                        <option value="main_supplier">Solo si es su proveedor principal</option>
                        <option value="best_cost">Usar el menor costo entre proveedores</option>
                    </select>
                </Field>
                <Field label="Marcar alerta si varía más de %"><input v-model="form.options.alert_threshold" type="number" step="1" min="1" class="input" /></Field>
                <p class="text-xs text-steel-500">Nada se modifica todavía: en el siguiente paso revisás cada cambio antes de aplicarlo.</p>
            </div>
        </div>
    </div>
</template>
