<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import TicketView from '@/Components/TicketView.vue';

const props = defineProps({ sale: Object, company: Object, settings: Object, methods: Object });
const print = () => window.print();
const close = () => window.close();
onMounted(() => setTimeout(print, 300));
</script>

<template>
    <Head :title="`Ticket #${sale.number}`" />
    <div class="no-print flex justify-center gap-2 bg-steel-100 p-3">
        <button class="btn-primary" @click="print">Imprimir</button>
        <button class="btn-secondary" @click="close">Cerrar</button>
    </div>
    <TicketView :sale="sale" :company="company" :settings="settings" :methods="methods" class="mx-auto" />
</template>

<style>
@media print {
    @page { size: 80mm auto; margin: 0; }
}
</style>
