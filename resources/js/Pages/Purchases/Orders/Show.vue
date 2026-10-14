<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Printer, PackageCheck } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, qty, date, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ order: Object });
const can = useCan();
const o = props.order;
const setStatus = (status, msg) => confirm(msg) && router.post(route('purchase-orders.status', o.id), { status });
const openPrint = () => window.open(route('purchase-orders.print', o.id), '_blank');
</script>

<template>
    <PageHeader :title="`Pedido #${o.number} · ${o.supplier.name}`" :back="route('purchase-orders.index')">
        <template #subtitle><p class="mt-0.5 flex items-center gap-2 text-sm text-steel-500">{{ date(o.created_at) }} · {{ o.branch?.name }} · {{ o.user?.name }} <StatusBadge :status="o.status" /></p></template>
        <button class="btn-secondary" @click="openPrint"><Printer class="size-4" /> Imprimir / PDF</button>
        <template v-if="can('purchases.manage')">
            <button v-if="o.status === 'draft'" class="btn-secondary" @click="setStatus('sent', '¿Marcar como enviado al proveedor?')">Marcar enviado</button>
            <Link v-if="['draft', 'sent', 'partial'].includes(o.status)" :href="route('purchases.create', { order: o.id })" class="btn-primary"><PackageCheck class="size-4" /> Recibir mercadería</Link>
            <button v-if="o.status === 'partial'" class="btn-secondary" @click="setStatus('received', '¿Cerrar el pedido aunque falten ítems?')">Cerrar pedido</button>
            <button v-if="['draft', 'sent'].includes(o.status)" class="btn-danger" @click="setStatus('cancelled', '¿Cancelar el pedido?')">Cancelar</button>
        </template>
    </PageHeader>
    <div class="grid gap-4 lg:grid-cols-[1fr_300px]">
        <div class="panel overflow-x-auto">
            <table class="table">
                <thead><tr><th>Producto</th><th>Cód. proveedor</th><th class="num">Pedido</th><th class="num">Recibido</th><th class="num">Pendiente</th><th class="num">Costo est.</th></tr></thead>
                <tbody>
                    <tr v-for="i in o.items" :key="i.id">
                        <td>{{ i.description }}<div class="code">{{ i.product?.code }}</div></td>
                        <td>{{ i.supplier_code }}</td>
                        <td class="num">{{ qty(i.quantity) }}</td>
                        <td class="num">{{ qty(i.received_quantity) }}</td>
                        <td class="num font-medium" :class="toNum(i.quantity) - toNum(i.received_quantity) > 0 && 'text-up'">{{ qty(Math.max(0, toNum(i.quantity) - toNum(i.received_quantity))) }}</td>
                        <td class="num">{{ money(i.unit_cost) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="panel p-4 text-sm">
            <div class="font-medium">Recepciones</div>
            <div v-for="p in o.purchases" :key="p.id" class="mt-1 flex justify-between">
                <Link :href="route('purchases.show', p.id)" class="link">{{ p.voucher_type }} {{ p.voucher_number || 's/n' }}</Link><span class="tabular-nums">{{ money(p.total) }}</span>
            </div>
            <p v-if="!o.purchases.length" class="mt-1 text-steel-500">Todavía no se recibió mercadería.</p>
            <p v-if="o.notes" class="mt-3 text-steel-600">{{ o.notes }}</p>
        </div>
    </div>
</template>
