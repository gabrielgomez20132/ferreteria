<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import { money } from '@/lib/format';

defineProps({ customers: Array, suppliers: Array, totals: Object });
const m = (v) => (Number(v) ? money(v) : '');
</script>

<template>
    <PageHeader title="Cuentas corrientes" :subtitle="`A cobrar ${money(totals.receivable)} · A pagar ${money(totals.payable)}`" :back="route('reports.index')" />
    <div class="panel mb-4 overflow-x-auto">
        <div class="panel-head"><h2 class="panel-title">Clientes: antigüedad de la deuda</h2></div>
        <table class="table">
            <thead><tr><th>Cliente</th><th>Teléfono</th><th class="num">A vencer</th><th class="num">1–30 días</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">+90</th><th class="num">Saldo</th></tr></thead>
            <tbody>
                <tr v-for="c in customers" :key="c.id">
                    <td><Link :href="route('customers.show', c.id)" class="link">{{ c.name }}</Link></td><td>{{ c.phone }}</td>
                    <td class="num">{{ m(c.not_due) }}</td><td class="num text-[#7a5b00]">{{ m(c.d30) }}</td><td class="num text-up">{{ m(c.d60) }}</td><td class="num text-up">{{ m(c.d90) }}</td><td class="num font-semibold text-up">{{ m(c.d90plus) }}</td>
                    <td class="num font-semibold">{{ money(c.balance) }}</td>
                </tr>
                <tr v-if="!customers.length"><td colspan="8" class="py-6 text-center text-steel-500">No hay deudas de clientes.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="panel overflow-x-auto">
        <div class="panel-head"><h2 class="panel-title">Proveedores</h2></div>
        <table class="table">
            <thead><tr><th>Proveedor</th><th>Teléfono</th><th class="num">Plazo</th><th class="num">Saldo</th></tr></thead>
            <tbody>
                <tr v-for="s in suppliers" :key="s.id"><td><Link :href="route('suppliers.show', s.id)" class="link">{{ s.name }}</Link></td><td>{{ s.phone }}</td><td class="num">{{ s.payment_term_days }} días</td><td class="num font-semibold">{{ money(s.balance) }}</td></tr>
                <tr v-if="!suppliers.length"><td colspan="4" class="py-6 text-center text-steel-500">Sin saldos con proveedores.</td></tr>
            </tbody>
        </table>
    </div>
</template>
