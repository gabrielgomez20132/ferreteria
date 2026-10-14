<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import { money, toNum } from '@/lib/format';

const props = defineProps({ suppliers: Array, prefill: Array, supplierId: Number });
const form = useForm({ supplier_id: props.supplierId ?? '', expected_date: '', notes: '', items: props.prefill.map((i) => ({ ...i })) });
const add = (p) => {
    if (form.items.some((i) => i.product_id === p.id)) return;
    form.items.push({ product_id: p.id, code: p.code, name: p.name, supplier_code: p.supplier_code, quantity: 1, unit_cost: toNum(p.supplier_cost) || toNum(p.cost) });
};
const total = computed(() => form.items.reduce((a, i) => a + toNum(i.quantity) * toNum(i.unit_cost), 0));
const submit = () => form.transform((d) => ({ ...d, expected_date: d.expected_date || null })).post(route('purchase-orders.store'));
</script>

<template>
    <PageHeader title="Nuevo pedido a proveedor" :back="route('purchase-orders.index')">
        <button class="btn-primary" :disabled="form.processing || !form.supplier_id || !form.items.length" @click="submit">Guardar pedido</button>
    </PageHeader>
    <div class="panel mb-4 grid gap-3 p-4 sm:grid-cols-3">
        <Field label="Proveedor" :error="form.errors.supplier_id"><select v-model="form.supplier_id" class="input"><option value="">Elegí…</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select></Field>
        <Field label="Entrega estimada"><input v-model="form.expected_date" type="date" class="input" /></Field>
        <Field label="Observaciones"><input v-model="form.notes" class="input" /></Field>
    </div>
    <div class="panel">
        <div class="border-b border-steel-200 p-4"><ProductSearch :supplier-id="form.supplier_id ? Number(form.supplier_id) : null" show-cost @select="add" /></div>
        <table class="table">
            <thead><tr><th>Producto</th><th>Cód. proveedor</th><th class="num">Cantidad (unid. de stock)</th><th class="num">Costo estimado</th><th class="num">Subtotal</th><th></th></tr></thead>
            <tbody>
                <tr v-for="(i, idx) in form.items" :key="i.product_id">
                    <td>{{ i.name }}<div class="code">{{ i.code }}</div></td>
                    <td><input v-model="i.supplier_code" class="input w-32" /></td>
                    <td class="num"><input v-model="i.quantity" type="number" step="any" min="0" class="input w-24 text-right" /></td>
                    <td class="num"><input v-model="i.unit_cost" type="number" step="0.01" min="0" class="input w-28 text-right" /></td>
                    <td class="num">{{ money(toNum(i.quantity) * toNum(i.unit_cost)) }}</td>
                    <td><button class="text-steel-400 hover:text-up" @click="form.items.splice(idx, 1)" aria-label="Quitar"><X class="size-4" /></button></td>
                </tr>
                <tr v-if="!form.items.length"><td colspan="6" class="py-6 text-center text-steel-500">Agregá productos al pedido.</td></tr>
            </tbody>
            <tfoot><tr><td colspan="4" class="px-3 py-2 text-right font-medium">Total estimado</td><td class="num px-3 font-semibold">{{ money(total) }}</td><td /></tr></tfoot>
        </table>
    </div>
</template>
