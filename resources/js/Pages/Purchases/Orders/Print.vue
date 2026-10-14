<script setup>
import { Head } from '@inertiajs/vue3';
import { qty, date } from '@/lib/format';

defineProps({ order: Object, company: Object });
const print = () => window.print();
</script>

<template>
    <Head :title="`Pedido #${order.number}`" />
    <div class="no-print flex justify-center gap-2 bg-steel-100 p-3"><button class="btn-primary" @click="print">Imprimir o guardar PDF</button></div>
    <div class="mx-auto max-w-[190mm] bg-white p-[10mm] text-sm">
        <div class="flex justify-between border-b-2 border-steel-900 pb-3">
            <div>
                <div class="text-lg font-bold">{{ company.name }}</div>
                <div>{{ company.address }}</div>
                <div>{{ company.phone }} · {{ company.email }}</div>
                <div v-if="company.cuit">CUIT {{ company.cuit }}</div>
            </div>
            <div class="text-right">
                <div class="text-lg font-bold">Pedido de compra N° {{ order.number }}</div>
                <div>Fecha: {{ date(order.created_at) }}</div>
                <div v-if="order.expected_date">Entrega: {{ date(order.expected_date) }}</div>
                <div>Entregar en: {{ order.branch?.name }} {{ order.branch?.address }}</div>
            </div>
        </div>
        <div class="mt-3"><strong>Proveedor:</strong> {{ order.supplier.name }} <span v-if="order.supplier.cuit">· CUIT {{ order.supplier.cuit }}</span></div>
        <table class="mt-4 w-full border-collapse text-sm">
            <thead><tr class="border-b border-steel-900"><th class="py-1 text-left">Código</th><th class="py-1 text-left">Descripción</th><th class="py-1 text-right">Cantidad</th></tr></thead>
            <tbody>
                <tr v-for="i in order.items" :key="i.id" class="border-b border-steel-200">
                    <td class="py-1">{{ i.supplier_code || '—' }}</td><td class="py-1">{{ i.description }}</td><td class="py-1 text-right">{{ qty(i.quantity) }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="order.notes" class="mt-4">{{ order.notes }}</p>
    </div>
</template>
