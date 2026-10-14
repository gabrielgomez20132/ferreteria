<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import { qty, toNum } from '@/lib/format';

const props = defineProps({ branches: Array });
const page = usePage();
const form = useForm({ to_branch_id: props.branches[0]?.id ?? '', notes: '', items: [] });
const add = (p) => !form.items.some((i) => i.product_id === p.id) && form.items.push({ product_id: p.id, name: p.name, code: p.code, unit: p.unit, stock: p.stock, quantity: 1 });
</script>

<template>
    <PageHeader title="Nueva transferencia" :subtitle="`Desde ${page.props.branch?.name}. El stock se descuenta al enviar y se suma cuando la sucursal destino confirma la recepción.`" :back="route('stock.transfers.index')">
        <button class="btn-primary" :disabled="form.processing || !form.items.length || !form.to_branch_id" @click="form.post(route('stock.transfers.store'))">Enviar</button>
    </PageHeader>
    <div v-if="!branches.length" class="panel p-6 text-sm text-steel-600">Necesitás al menos otra sucursal activa para transferir.</div>
    <template v-else>
        <div class="panel mb-4 grid gap-3 p-4 sm:grid-cols-2">
            <Field label="Hacia" :error="form.errors.to_branch_id"><select v-model="form.to_branch_id" class="input"><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></Field>
            <Field label="Observaciones"><input v-model="form.notes" class="input" /></Field>
        </div>
        <div class="panel">
            <div class="border-b border-steel-200 p-4"><ProductSearch @select="add" /></div>
            <table class="table">
                <thead><tr><th>Producto</th><th class="num">Disponible</th><th class="num">Enviar</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="(i, idx) in form.items" :key="i.product_id">
                        <td>{{ i.name }}<div class="code">{{ i.code }}</div></td>
                        <td class="num">{{ qty(i.stock) }} {{ i.unit }}</td>
                        <td class="num"><input v-model="i.quantity" type="number" step="any" min="0" class="input w-24 text-right" :class="toNum(i.quantity) > toNum(i.stock) && 'border-up'" /></td>
                        <td><button class="text-steel-400 hover:text-up" @click="form.items.splice(idx, 1)" aria-label="Quitar"><X class="size-4" /></button></td>
                    </tr>
                    <tr v-if="!form.items.length"><td colspan="4" class="py-6 text-center text-steel-500">Agregá los productos a enviar.</td></tr>
                </tbody>
            </table>
        </div>
    </template>
</template>
