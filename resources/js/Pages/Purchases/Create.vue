<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import Variation from '@/Components/Variation.vue';
import { money, round2, toNum, today } from '@/lib/format';

const props = defineProps({ suppliers: Array, order: Object, priceActions: Object, defaultPriceAction: String });

const todayStr = today();
const form = useForm({
    supplier_id: props.order?.supplier_id ?? '',
    purchase_order_id: props.order?.id ?? null,
    voucher_type: 'FA', voucher_number: "", date: todayStr, due_date: '',
    other_taxes: 0, extra_costs: 0, update_costs: true, price_action: props.defaultPriceAction || 'keep_markup', notes: '',
    items: (props.order?.items ?? [])
        .filter((i) => toNum(i.quantity) > toNum(i.received_quantity))
        .map((i) => ({
            product_id: i.product_id, purchase_order_item_id: i.id, code: i.product.code, name: i.product.name,
            // el pedido está en unidades de stock: se recibe con factor 1
            quantity: toNum(i.quantity) - toNum(i.received_quantity), unit_factor: 1,
            unit_label: null, unit_cost: toNum(i.unit_cost),
            discount_percent: 0, vat_rate: toNum(i.product.vat_rate), current_cost: toNum(i.product.cost),
        })),
});

const supplier = computed(() => props.suppliers.find((s) => s.id === Number(form.supplier_id)));
watch(supplier, (s) => {
    if (s && !form.due_date) {
        const days = Math.round((new Date(form.date + 'T00:00') - new Date(today() + 'T00:00')) / 864e5);
        form.due_date = today(days + Number(s.payment_term_days || 0));
    }
});

function add(p) {
    const factor = toNum(p.purchase_unit_factor) || 1;
    form.items.push({
        product_id: p.id, code: p.code, name: p.name, quantity: 1, unit_factor: factor, unit_label: p.purchase_unit_name,
        unit_cost: round2((toNum(p.supplier_cost) || toNum(p.cost)) * factor), discount_percent: 0, vat_rate: p.vat_rate, current_cost: toNum(p.cost),
    });
}
const lineNet = (i) => round2(toNum(i.quantity) * toNum(i.unit_cost) * (1 - toNum(i.discount_percent) / 100));
const subtotal = computed(() => round2(form.items.reduce((a, i) => a + lineNet(i), 0)));
const vat = computed(() => round2(form.items.reduce((a, i) => a + (lineNet(i) * toNum(i.vat_rate)) / 100, 0)));
const total = computed(() => round2(subtotal.value + vat.value + toNum(form.other_taxes) + toNum(form.extra_costs)));
// Costo resultante por unidad de stock (incluye prorrateo de fletes)
const newCost = (i) => {
    const share = subtotal.value > 0 ? (toNum(form.extra_costs) * lineNet(i)) / subtotal.value : 0;
    const q = toNum(i.quantity) * toNum(i.unit_factor);
    return q > 0 ? (lineNet(i) + share) / q : 0;
};
const varOf = (i) => (i.current_cost > 0 ? ((newCost(i) - i.current_cost) / i.current_cost) * 100 : null);
const submit = () => form.transform((d) => ({ ...d, due_date: d.due_date || null })).post(route('purchases.store'));
</script>

<template>
    <PageHeader :title="order ? `Recepción del pedido #${order.number}` : 'Cargar compra'" subtitle="Ingresa la mercadería al stock de esta sucursal, actualiza costos y registra la deuda con el proveedor." :back="route('purchases.index')">
        <button class="btn-primary" :disabled="form.processing || !form.items.length || !form.supplier_id" @click="submit">Confirmar compra</button>
    </PageHeader>

    <div class="grid gap-4 xl:grid-cols-[1fr_320px]">
        <div class="space-y-4">
            <div class="panel grid gap-3 p-4 sm:grid-cols-5">
                <Field class="sm:col-span-2" label="Proveedor" :error="form.errors.supplier_id">
                    <select v-model="form.supplier_id" class="input" :disabled="!!order"><option value="">Elegí…</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </Field>
                <Field label="Comprobante"><select v-model="form.voucher_type" class="input"><option value="FA">Factura A</option><option value="FB">Factura B</option><option value="FC">Factura C</option><option value="REMITO">Remito</option><option value="TICKET">Ticket</option></select></Field>
                <Field label="Número"><input v-model="form.voucher_number" class="input" placeholder="0001-00001234" /></Field>
                <Field label="Fecha"><input v-model="form.date" type="date" class="input" /></Field>
            </div>

            <div class="panel">
                <div class="border-b border-steel-200 p-4">
                    <ProductSearch :supplier-id="form.supplier_id ? Number(form.supplier_id) : null" show-cost placeholder="Buscar producto por código, código del proveedor o descripción…" @select="add" />
                </div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">x Unid.</th><th class="num">Costo unit. neto</th><th class="num">Bonif. %</th><th class="num">IVA</th><th class="num">Subtotal</th><th class="num">Nuevo costo</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="(i, idx) in form.items" :key="idx">
                                <td>{{ i.name }}<div class="code">{{ i.code }}<span v-if="i.unit_label"> · por {{ i.unit_label }}</span></div></td>
                                <td class="num"><input v-model="i.quantity" type="number" step="any" min="0" class="input w-20 text-right" /></td>
                                <td class="num"><input v-model="i.unit_factor" type="number" step="any" min="0.0001" class="input w-20 text-right" title="Unidades de stock por cada unidad comprada" /></td>
                                <td class="num"><input v-model="i.unit_cost" type="number" step="0.0001" min="0" class="input w-28 text-right" /></td>
                                <td class="num"><input v-model="i.discount_percent" type="number" step="0.01" min="0" max="100" class="input w-16 text-right" /></td>
                                <td class="num"><select v-model="i.vat_rate" class="input w-20"><option v-for="v in [21, 10.5, 27, 0]" :key="v" :value="v">{{ v }}%</option></select></td>
                                <td class="num">{{ money(lineNet(i)) }}</td>
                                <td class="num"><div>{{ money(newCost(i)) }}</div><Variation :value="varOf(i)" /></td>
                                <td><button class="text-steel-400 hover:text-up" @click="form.items.splice(idx, 1)" aria-label="Quitar"><X class="size-4" /></button></td>
                            </tr>
                            <tr v-if="!form.items.length"><td colspan="9" class="py-8 text-center text-steel-500">Agregá los productos de la factura.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="panel space-y-2 p-4 text-sm">
                <div class="flex justify-between"><span>Subtotal neto</span><span class="tabular-nums">{{ money(subtotal) }}</span></div>
                <div class="flex justify-between"><span>IVA</span><span class="tabular-nums">{{ money(vat) }}</span></div>
                <div class="flex items-center justify-between gap-2"><span>Percepciones</span><input v-model="form.other_taxes" type="number" step="0.01" min="0" class="input w-32 text-right" /></div>
                <div class="flex items-center justify-between gap-2"><span>Flete / gastos <span class="block text-xs text-steel-500">se suman al costo</span></span><input v-model="form.extra_costs" type="number" step="0.01" min="0" class="input w-32 text-right" /></div>
                <div class="flex justify-between border-t border-steel-200 pt-2 text-base font-semibold"><span>Total</span><span class="tabular-nums">{{ money(total) }}</span></div>
            </div>
            <div class="panel space-y-3 p-4">
                <Field label="Vencimiento del pago"><input v-model="form.due_date" type="date" class="input" /></Field>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.update_costs" type="checkbox" class="checkbox" /> Actualizar el costo de los productos</label>
                <Field v-if="form.update_costs" label="Precio de venta">
                    <select v-model="form.price_action" class="input"><option v-for="(l, k) in priceActions" :key="k" :value="k">{{ l }}</option></select>
                </Field>
                <Field label="Observaciones"><textarea v-model="form.notes" rows="2" class="input" /></Field>
                <p v-if="Object.keys(form.errors).length" class="text-xs text-up">{{ Object.values(form.errors)[0] }}</p>
            </div>
        </div>
    </div>
</template>
