<script setup>
import { Link, router } from '@inertiajs/vue3';
import { Printer, PackageCheck, Mail, MessageCircle } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, qty, date, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ order: Object });
const can = useCan();
const o = props.order;
// WhatsApp: arma el texto del pedido y lo abre en WhatsApp Web / la app; el pedido queda como enviado
const whatsapp = () => {
    const phone = String(o.supplier?.phone ?? '').replace(/\D/g, '');
    const lines = o.items.map((i) => `• ${qty(i.quantity)} x ${i.description}${i.supplier_code ? ` (cód. ${i.supplier_code})` : ''}`);
    const text = [`Hola ${o.supplier.contact_name || o.supplier.name}, te paso el pedido N° ${o.number}${o.expected_date ? ` para el ${date(o.expected_date)}` : ''}:`, '', ...lines, '', o.notes || '', 'Gracias.']
        .filter((l, idx, arr) => l !== '' || arr[idx - 1] !== '')
        .join('\n');
    const number = phone ? (phone.startsWith('54') ? phone : `549${phone.replace(/^0/, '')}`) : '';
    window.open(`https://wa.me/${number}?text=${encodeURIComponent(text)}`, '_blank');
    if (o.status === 'draft' && can('purchases.manage')) router.post(route('purchase-orders.status', o.id), { status: 'sent' }, { preserveScroll: true });
};
const setStatus = (status, msg) => confirm(msg) && router.post(route('purchase-orders.status', o.id), { status });
const openPrint = () => window.open(route('purchase-orders.print', o.id), '_blank');
</script>

<template>
    <PageHeader :title="`Pedido #${o.number} · ${o.supplier.name}`" :back="route('purchase-orders.index')">
        <template #subtitle><p class="mt-0.5 flex items-center gap-2 text-sm text-steel-500">{{ date(o.created_at) }} · {{ o.branch?.name }} · {{ o.user?.name }} <StatusBadge :status="o.status" /></p></template>
        <button class="btn-secondary" @click="openPrint"><Printer class="size-4" /> Imprimir / PDF</button>
        <button v-if="o.status !== 'cancelled'" class="btn-secondary" @click="whatsapp" :title="o.supplier?.phone ? 'Enviar a ' + o.supplier.phone : 'El proveedor no tiene teléfono: elegís el contacto en WhatsApp'"><MessageCircle class="size-4" /> Enviar por WhatsApp</button>
        <button class="btn-secondary" disabled title="Próximamente: enviar el pedido por email al proveedor"><Mail class="size-4" /> Email <span class="badge-gray">Próximamente</span></button>
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
