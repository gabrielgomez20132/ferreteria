<script setup>
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import CashSummary from '@/Components/CashSummary.vue';

defineProps({ session: Object, totals: Object, expected_cash: Number, by_type: Array, movements: Array, sales_count: Number, methods: Object, types: Object });
const print = () => window.print();
</script>

<template>
    <PageHeader :title="`${session.cash_register.name} · ${session.branch.name}`" :back="route('cash.history')">
        <template #subtitle><p class="mt-0.5"><StatusBadge :status="session.status" /></p></template>
        <button class="btn-secondary no-print" @click="print">Imprimir</button>
    </PageHeader>
    <CashSummary :session="session" :totals="totals" :expected="expected_cash" :movements="movements" :methods="methods" :types="types" :sales-count="sales_count" />
</template>
