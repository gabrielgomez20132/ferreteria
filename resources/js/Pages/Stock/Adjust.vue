<script setup>
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import { qty, toNum } from '@/lib/format';

const form = useForm({ reason: '', items: [] });
const add = (p) => !form.items.some((i) => i.product_id === p.id) && form.items.push({ product_id: p.id, name: p.name, code: p.code, unit: p.unit, current: p.stock, mode: 'subtract', quantity: 1 });
const result = (i) => (i.mode === 'set' ? toNum(i.quantity) : toNum(i.current) + (i.mode === 'add' ? 1 : -1) * toNum(i.quantity));
const REASONS = ['Rotura', 'Pérdida / robo', 'Vencimiento', 'Error de carga', 'Consumo interno', 'Diferencia de conteo'];
</script>

<template>
    <PageHeader title="Ajuste de stock" subtitle="Correcciones por rotura, pérdida o diferencias. Queda registrado con su motivo y usuario." :back="route('stock.movements')">
        <button class="btn-primary" :disabled="form.processing || !form.items.length || !form.reason" @click="form.post(route('stock.adjust.store'))">Registrar ajuste</button>
    </PageHeader>
    <div class="panel mb-4 p-4">
        <Field label="Motivo" :error="form.errors.reason">
            <input v-model="form.reason" class="input" list="reasons" placeholder="Ej.: rotura en depósito" />
            <datalist id="reasons"><option v-for="r in REASONS" :key="r" :value="r" /></datalist>
        </Field>
    </div>
    <div class="panel">
        <div class="border-b border-steel-200 p-4"><ProductSearch @select="add" /></div>
        <table class="table">
            <thead><tr><th>Producto</th><th class="num">Stock actual</th><th>Operación</th><th class="num">Cantidad</th><th class="num">Queda</th><th></th></tr></thead>
            <tbody>
                <tr v-for="(i, idx) in form.items" :key="i.product_id">
                    <td>{{ i.name }}<div class="code">{{ i.code }}</div></td>
                    <td class="num">{{ qty(i.current) }} {{ i.unit }}</td>
                    <td><select v-model="i.mode" class="input w-40"><option value="subtract">Restar</option><option value="add">Sumar</option><option value="set">Fijar en</option></select></td>
                    <td class="num"><input v-model="i.quantity" type="number" step="any" min="0" class="input w-24 text-right" /></td>
                    <td class="num font-semibold" :class="result(i) < 0 && 'text-up'">{{ qty(result(i)) }}</td>
                    <td><button class="text-steel-400 hover:text-up" @click="form.items.splice(idx, 1)" aria-label="Quitar"><X class="size-4" /></button></td>
                </tr>
                <tr v-if="!form.items.length"><td colspan="6" class="py-6 text-center text-steel-500">Buscá los productos a ajustar.</td></tr>
            </tbody>
        </table>
    </div>
</template>
