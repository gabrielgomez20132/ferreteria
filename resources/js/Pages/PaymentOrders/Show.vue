<script setup>
import { router } from '@inertiajs/vue3';
import { Printer, Mail } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, date } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ order: Object, company: Object, methods: Object });
const can = useCan();
const o = props.order;
const print = () => window.print();
const cancel = () => confirm('¿Anular la orden de pago? Las facturas vuelven a quedar pendientes.') && router.post(route('payment-orders.cancel', o.id));
</script>

<template>
    <div class="no-print">
        <PageHeader :title="`Orden de pago #${o.number}`" :back="route('payment-orders.index')">
            <template #subtitle><p class="mt-0.5"><StatusBadge :status="o.status" /></p></template>
            <button class="btn-secondary" disabled title="Próximamente: enviar la orden de pago por email al proveedor"><Mail class="size-4" /> Enviar por email <span class="badge-gray">Próximamente</span></button>
            <button class="btn-secondary" @click="print"><Printer class="size-4" /> Imprimir / PDF</button>
            <button v-if="o.status === 'confirmed' && can('suppliers.manage')" class="btn-danger" @click="cancel">Anular</button>
        </PageHeader>
    </div>

    <!-- Documento imprimible -->
    <div class="panel mx-auto max-w-[190mm] p-8 text-sm print:border-0 print:p-0">
        <div class="flex justify-between border-b-2 border-steel-900 pb-3">
            <div>
                <div class="text-lg font-bold">{{ company.name }}</div>
                <div v-if="company.legal_name">{{ company.legal_name }}</div>
                <div v-if="company.cuit">CUIT {{ company.cuit }}</div>
                <div>{{ company.address }}</div>
            </div>
            <div class="text-right">
                <div class="text-lg font-bold">ORDEN DE PAGO N° {{ String(o.number).padStart(6, '0') }}</div>
                <div>Fecha: {{ date(o.date) }}</div>
                <div v-if="o.status === 'cancelled'" class="font-bold text-up">ANULADA</div>
            </div>
        </div>
        <div class="mt-3"><strong>Proveedor:</strong> {{ o.supplier.name }}<span v-if="o.supplier.cuit"> · CUIT {{ o.supplier.cuit }}</span></div>

        <div class="mt-4 font-semibold">Medios de pago</div>
        <table class="mt-1 w-full border-collapse">
            <thead><tr class="border-b border-steel-900 text-left"><th class="py-1">Medio</th><th class="py-1">Referencia</th><th class="py-1">Banco</th><th class="py-1">Fecha cobro</th><th class="py-1 text-right">Importe</th></tr></thead>
            <tbody>
                <tr v-for="l in o.lines" :key="l.id" class="border-b border-steel-200">
                    <td class="py-1">{{ methods[l.method] ?? l.method }}</td><td class="py-1">{{ l.reference }}</td><td class="py-1">{{ l.bank }}</td>
                    <td class="py-1">{{ l.check_date ? date(l.check_date) : '' }}</td><td class="py-1 text-right tabular-nums">{{ money(l.amount) }}</td>
                </tr>
            </tbody>
            <tfoot><tr class="font-bold"><td colspan="4" class="py-1.5 text-right">TOTAL</td><td class="py-1.5 text-right tabular-nums">{{ money(o.total) }}</td></tr></tfoot>
        </table>

        <div class="mt-4 font-semibold">Comprobantes cancelados</div>
        <table class="mt-1 w-full border-collapse">
            <thead><tr class="border-b border-steel-900 text-left"><th class="py-1">Comprobante</th><th class="py-1">Fecha</th><th class="py-1 text-right">Total factura</th><th class="py-1 text-right">Imputado</th></tr></thead>
            <tbody>
                <tr v-for="a in o.applications" :key="a.id" class="border-b border-steel-200">
                    <td class="py-1">{{ a.purchase?.voucher_type }} {{ a.purchase?.voucher_number || 's/n' }}</td><td class="py-1">{{ date(a.purchase?.date) }}</td>
                    <td class="py-1 text-right tabular-nums">{{ money(a.purchase?.total) }}</td><td class="py-1 text-right tabular-nums">{{ money(a.amount) }}</td>
                </tr>
                <tr v-if="!o.applications.length"><td colspan="4" class="py-1 text-steel-500">Pago a cuenta (sin imputar a facturas).</td></tr>
            </tbody>
        </table>
        <p v-if="o.notes" class="mt-3">{{ o.notes }}</p>
        <div class="mt-16 grid grid-cols-2 gap-16 text-center text-xs">
            <div class="border-t border-steel-900 pt-1">Firma y aclaración — {{ o.user?.name }}</div>
            <div class="border-t border-steel-900 pt-1">Recibí conforme — {{ o.supplier.name }}</div>
        </div>
    </div>
</template>
