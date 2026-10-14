<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, qty, toNum } from '@/lib/format';

const props = defineProps({ count: Object, items: Object, summary: Object, filters: Object });
const open = props.count.status === 'open';
const counts = reactive({});
const reset = () => props.items.data.forEach((i) => (counts[i.id] = i.counted_quantity === null ? '' : toNum(i.counted_quantity)));
reset();
watch(() => props.items, reset);
const search = ref(props.filters.search ?? '');
const dirty = () => props.items.data.some((i) => String(counts[i.id]) !== String(i.counted_quantity === null ? '' : toNum(i.counted_quantity)));

const go = (p) => router.get(route('stock.inventory.show', props.count.id), { ...props.filters, ...p }, { preserveState: true });
const save = () => router.put(route('stock.inventory.update', props.count.id), { counts: Object.fromEntries(Object.entries(counts).map(([k, v]) => [k, v === '' ? null : v])) }, { preserveScroll: true, preserveState: true });
const apply = () => confirm(`Se ajustará el stock de los ${props.summary.counted} productos contados. Los no contados no se modifican. ¿Continuar?`) && router.post(route('stock.inventory.apply', props.count.id));
const cancel = () => confirm('¿Cancelar este inventario?') && router.post(route('stock.inventory.cancel', props.count.id));
const diff = (i) => (counts[i.id] === '' ? null : toNum(counts[i.id]) - toNum(i.system_quantity));
</script>

<template>
    <PageHeader :title="`Inventario #${count.id} · ${count.category?.name ?? 'todo el stock'}`" :back="route('stock.inventory.index')">
        <template #subtitle><p class="mt-0.5 flex items-center gap-2 text-sm text-steel-500">{{ count.branch.name }} · {{ summary.counted }} de {{ summary.total }} contados · diferencia valorizada {{ money(summary.value_diff) }} <StatusBadge :status="count.status" /></p></template>
        <template v-if="open">
            <button class="btn-ghost" @click="cancel">Cancelar</button>
            <button class="btn-secondary" @click="save">Guardar conteo</button>
            <button class="btn-primary" :disabled="!Number(summary.counted)" @click="apply">Aplicar ajustes</button>
        </template>
    </PageHeader>
    <div class="panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="dirty() && save(); go({ search })"><input v-model="search" class="input w-72" placeholder="Código, código de barras o descripción" /></form>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :checked="!!filters.pending" @change="go({ pending: $event.target.checked ? 1 : undefined, only_diff: undefined })" /> Sin contar</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" :checked="!!filters.only_diff" @change="go({ only_diff: $event.target.checked ? 1 : undefined, pending: undefined })" /> Solo con diferencias</label>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Ubicación</th><th>Producto</th><th class="num">Sistema</th><th class="num w-32">Contado</th><th class="num">Diferencia</th></tr></thead>
                <tbody>
                    <tr v-for="i in items.data" :key="i.id">
                        <td class="text-xs text-steel-500">{{ i.product?.location }}</td>
                        <td>{{ i.product?.name }}<div class="code">{{ i.product?.code }}</div></td>
                        <td class="num">{{ qty(i.system_quantity) }}</td>
                        <td class="num">
                            <input v-if="open" v-model="counts[i.id]" type="number" min="0" step="any" class="input ml-auto w-24 text-right" @keydown.enter.prevent="$event.target.closest('tr').nextElementSibling?.querySelector('input')?.focus()" />
                            <span v-else>{{ qty(i.counted_quantity) }}</span>
                        </td>
                        <td class="num font-medium" :class="diff(i) < 0 ? 'text-up' : diff(i) > 0 ? 'text-down' : ''">{{ diff(i) === null ? '' : (diff(i) > 0 ? '+' : '') + qty(diff(i)) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="items" />
    </div>
</template>
