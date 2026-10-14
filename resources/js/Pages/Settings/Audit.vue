<script setup>
import { router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { datetime } from '@/lib/format';

defineProps({ logs: Object, filters: Object });
const ACTIONS = { created: 'Alta', updated: 'Modificación', deleted: 'Baja' };
const MODELS = { Product: 'Producto', Customer: 'Cliente', Supplier: 'Proveedor' };
const fmt = (v) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));
const go = (p) => router.get(route('audit.index'), p, { preserveState: true });
</script>

<template>
    <PageHeader title="Auditoría" subtitle="Quién cambió qué y cuándo en productos, clientes y proveedores. Los cambios de precios se registran en el historial de precios." />
    <div class="panel">
        <div class="flex gap-2 border-b border-steel-200 px-4 py-3">
            <select class="input w-auto" v-sel="filters.type ?? ''" @change="go({ ...filters, type: $event.target.value || undefined })"><option value="">Todo</option><option v-for="(l, k) in MODELS" :key="k" :value="k">{{ l }}</option></select>
            <select class="input w-auto" v-sel="filters.action ?? ''" @change="go({ ...filters, action: $event.target.value || undefined })"><option value="">Todas las acciones</option><option v-for="(l, k) in ACTIONS" :key="k" :value="k">{{ l }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Registro</th><th>Cambios</th></tr></thead>
                <tbody>
                    <tr v-for="l in logs.data" :key="l.id">
                        <td class="whitespace-nowrap">{{ datetime(l.created_at) }}</td>
                        <td>{{ l.user ?? 'Sistema' }}<div class="text-xs text-steel-400">{{ l.ip }}</div></td>
                        <td>{{ ACTIONS[l.action] ?? l.action }}</td>
                        <td>{{ MODELS[l.model] ?? l.model }} #{{ l.model_id }}</td>
                        <td class="max-w-xl text-xs">
                            <template v-if="l.action === 'updated'">
                                <div v-for="(v, k) in l.new" :key="k"><span class="font-medium">{{ k }}</span>: <span class="text-steel-500 line-through">{{ fmt(l.old?.[k]) }}</span> → {{ fmt(v) }}</div>
                            </template>
                            <span v-else class="text-steel-500">{{ fmt((l.new ?? l.old)?.name ?? '') }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="logs" />
    </div>
</template>
