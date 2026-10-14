<script setup>
import { computed } from 'vue';

const props = defineProps({ status: String });
const MAP = {
    completed: ['Confirmada', 'badge-down'],
    confirmed: ['Confirmada', 'badge-down'],
    cancelled: ['Anulada', 'badge-up'],
    quote: ['Presupuesto', 'badge-blue'],
    open: ['Abierta', 'badge-down'],
    closed: ['Cerrada', 'badge-gray'],
    applied: ['Aplicada', 'badge-down'],
    reverted: ['Revertida', 'badge-gray'],
    uploaded: ['Pendiente de mapear', 'badge-warn'],
    processed: ['Lista para revisar', 'badge-warn'],
    in_transit: ['En tránsito', 'badge-warn'],
    received: ['Recibido', 'badge-down'],
    draft: ['Borrador', 'badge-gray'],
    sent: ['Enviado', 'badge-blue'],
    partial: ['Recibido parcial', 'badge-warn'],
    trial: ['Prueba', 'badge-warn'],
    active: ['Activa', 'badge-down'],
    suspended: ['Suspendida', 'badge-up'],
    matched: ['Vinculado', 'badge-blue'],
    unmatched: ['Sin vincular', 'badge-warn'],
    unchanged: ['Sin cambio', 'badge-gray'],
    error: ['Error', 'badge-up'],
    created: ['Alta nueva', 'badge-down'],
};
const s = computed(() => MAP[props.status] ?? [props.status, 'badge-gray']);
</script>

<template>
    <span :class="s[1]">{{ s[0] }}</span>
</template>
