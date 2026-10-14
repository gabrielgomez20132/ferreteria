<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, qty, date, datetime, toNum } from '@/lib/format';

const props = defineProps({ order: Object, requirements: Array, unitCost: Number });
const o = props.order;
const canComplete = computed(() => o.status === 'pending' && props.requirements.every((r) => r.missing <= 0));
const complete = () => confirm(`¿Completar la producción de ${qty(o.quantity)} ${o.product.name}? Se descuentan los insumos del stock.`) && router.post(route('stock.production.complete', o.id));
const cancel = () => confirm('¿Cancelar la orden?') && router.post(route('stock.production.cancel', o.id));
const print = () => window.print();
</script>

<template>
    <PageHeader :title="`Orden de producción #${o.number}`" :back="route('stock.production.index')">
        <template #subtitle>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-steel-500">
                {{ datetime(o.created_at) }} · {{ o.branch?.name }} · {{ o.user?.name }}<span v-if="o.due_date"> · para el {{ date(o.due_date) }}</span> <StatusBadge :status="o.status" :labels="{ completed: 'Completada' }" />
                <span v-if="o.completed_at">el {{ datetime(o.completed_at) }}</span>
            </p>
        </template>
        <button class="btn-secondary no-print" @click="print">Imprimir</button>
        <template v-if="o.status === 'pending'">
            <button class="btn-ghost no-print" @click="cancel">Cancelar orden</button>
            <button class="btn-primary no-print" :disabled="!canComplete" :title="canComplete ? '' : 'Faltan insumos en esta sucursal'" @click="complete">Completar producción</button>
        </template>
    </PageHeader>

    <div class="panel mb-4 grid grid-cols-2 divide-x divide-steel-200 md:grid-cols-3">
        <div class="p-4"><div class="text-sm text-steel-500">Producto</div><Link :href="route('products.show', o.product.id)" class="text-lg font-semibold link">{{ o.product.name }}</Link><div class="code">{{ o.product.code }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Cantidad</div><div class="text-2xl font-semibold tabular-nums">{{ qty(o.quantity) }} {{ o.product.unit?.abbreviation }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Costo unitario {{ o.status === 'completed' ? 'real' : 'estimado' }}</div><div class="text-2xl font-semibold tabular-nums">{{ money(o.unit_cost ?? unitCost) }}</div><div class="text-xs text-steel-500">total {{ money(toNum(o.unit_cost ?? unitCost) * toNum(o.quantity)) }}</div></div>
    </div>

    <div class="panel overflow-x-auto">
        <div class="panel-head"><h2 class="panel-title">Insumos</h2></div>
        <table class="table">
            <thead><tr><th>Insumo</th><th class="num">Por unidad</th><th class="num">Necesario</th><th class="num">Stock</th><th class="num">Falta</th></tr></thead>
            <tbody>
                <tr v-for="r in requirements" :key="r.id">
                    <td>{{ r.name }}<div class="code">{{ r.code }}</div></td>
                    <td class="num">{{ qty(r.required / toNum(o.quantity)) }} {{ r.unit }}</td>
                    <td class="num font-medium">{{ qty(r.required) }} {{ r.unit }}</td>
                    <td class="num">{{ qty(r.stock) }}</td>
                    <td class="num font-semibold" :class="o.status === 'pending' && r.missing > 0 ? 'text-up' : 'text-steel-400'">{{ o.status === 'pending' ? (r.missing > 0 ? qty(r.missing) : 'OK') : '—' }}</td>
                </tr>
            </tbody>
        </table>
        <p v-if="o.notes" class="border-t border-steel-200 px-4 py-2.5 text-sm text-steel-600">{{ o.notes }}</p>
    </div>
</template>
