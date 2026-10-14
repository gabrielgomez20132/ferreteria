<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { qty, datetime, toNum } from '@/lib/format';

const props = defineProps({ transfer: Object, canReceive: Boolean });
const t = props.transfer;
const received = reactive(Object.fromEntries(t.items.map((i) => [i.id, toNum(i.quantity)])));
const receive = () => confirm('¿Confirmar la recepción? El stock se suma a esta sucursal.') && router.post(route('stock.transfers.receive', t.id), { received });
const cancel = () => confirm('¿Cancelar la transferencia? El stock vuelve a la sucursal de origen.') && router.post(route('stock.transfers.cancel', t.id));
</script>

<template>
    <PageHeader :title="`Transferencia #${t.number}`" :back="route('stock.transfers.index')">
        <template #subtitle>
            <p class="mt-0.5 flex items-center gap-2 text-sm text-steel-500">
                {{ t.from_branch.name }} → {{ t.to_branch.name }} · {{ datetime(t.created_at) }} · {{ t.user?.name }} <StatusBadge :status="t.status" />
                <span v-if="t.received_at">recibida {{ datetime(t.received_at) }} por {{ t.receiver?.name }}</span>
            </p>
        </template>
        <button v-if="t.status === 'in_transit'" class="btn-danger" @click="cancel">Cancelar</button>
        <button v-if="canReceive" class="btn-primary" @click="receive">Confirmar recepción</button>
    </PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Producto</th><th class="num">Enviado</th><th class="num">Recibido</th></tr></thead>
            <tbody>
                <tr v-for="i in t.items" :key="i.id">
                    <td>{{ i.product?.name }}<div class="code">{{ i.product?.code }}</div></td>
                    <td class="num">{{ qty(i.quantity) }}</td>
                    <td class="num">
                        <input v-if="canReceive" v-model="received[i.id]" type="number" min="0" step="any" class="input ml-auto w-24 text-right" />
                        <span v-else :class="i.received_quantity !== null && toNum(i.received_quantity) < toNum(i.quantity) && 'font-semibold text-up'">{{ i.received_quantity !== null ? qty(i.received_quantity) : '—' }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="t.notes" class="border-t border-steel-200 px-4 py-2.5 text-sm text-steel-600">{{ t.notes }}</p>
    </div>
</template>
