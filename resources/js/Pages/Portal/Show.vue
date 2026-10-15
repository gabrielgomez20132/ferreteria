<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Download, Eye, FileText, Loader2, Phone, Printer, Receipt, X } from '@lucide/vue';
import TicketView from '@/Components/TicketView.vue';
import { getJson } from '@/lib/http';
import { money, date, datetime } from '@/lib/format';
import { downloadTicketPdf } from '@/lib/ticketPdf';
import { downloadStatementPdf, downloadReceiptPdf } from '@/lib/accountPdf';

/**
 * Portal del cliente (público, se entra con el link secreto que le comparte la ferretería).
 */
const props = defineProps({
    token: String,
    company: Object,
    customer: Object,
    summary: Object,
    movements: Array,
    previousBalance: Number,
    pending: Array,
    sales: Array,
    payments: Array,
    months: Number,
    methods: Object,
    generatedAt: String,
});

const TYPES = { sale: 'Venta', payment: 'Pago', return: 'Devolución', adjustment: 'Ajuste', cancel: 'Anulación' };
const tab = ref(props.pending.length ? 'pending' : 'sales');
const tabs = computed(() => [
    { k: 'pending', t: 'Pendientes de pago', n: props.pending.length },
    { k: 'sales', t: 'Comprobantes', n: props.sales.length },
    { k: 'payments', t: 'Pagos', n: props.payments.length },
    { k: 'movements', t: 'Cuenta corriente', n: null },
]);

const setMonths = (m) => router.get(route('portal.show', props.token), m === 12 ? {} : { months: m }, { preserveState: true, preserveScroll: true });

const daysFrom = (d) => Math.round((new Date(new Date().toDateString()) - new Date(d + 'T00:00:00')) / 86400000);
const dueLabel = (p) => {
    if (!p.due_date) return '';
    const d = daysFrom(p.due_date);
    if (d > 0) return `Vencido hace ${d} ${d === 1 ? 'día' : 'días'}`;
    if (d === 0) return 'Vence hoy';
    return `Vence en ${-d} ${d === -1 ? 'día' : 'días'}`;
};

// ---- comprobantes ----
const ticket = ref(null);
const loadingId = ref(null);
const busy = ref(false);
async function fetchSale(id) {
    loadingId.value = id;
    try {
        return await getJson(route('portal.sale', [props.token, id]));
    } catch {
        alert('No se pudo cargar el comprobante.');
        return null;
    } finally {
        loadingId.value = null;
    }
}
const openSale = async (id) => (ticket.value = await fetchSale(id));
async function pdfSale(id) {
    const data = ticket.value?.sale?.id === id ? ticket.value : await fetchSale(id);
    if (data) await downloadTicketPdf(data);
}
const printTicket = () => window.print();

async function statement() {
    busy.value = true;
    try {
        await downloadStatementPdf(props);
    } finally {
        busy.value = false;
    }
}
const receipt = (p) => downloadReceiptPdf({ company: props.company, customer: props.customer, payment: p, methods: props.methods });

const saleState = (s) => (s.status === 'cancelled' ? 'Anulado' : Number(s.returned_amount) > 0 ? (Number(s.returned_amount) >= Number(s.total) ? 'Devuelto' : 'Devolución parcial') : '');
const phoneDigits = computed(() => String(props.company.phone ?? '').replace(/\D/g, ''));
const whatsapp = computed(() => {
    if (!phoneDigits.value) return null;
    const n = phoneDigits.value.startsWith('54') ? phoneDigits.value : `549${phoneDigits.value.replace(/^0/, '')}`;
    return `https://wa.me/${n}?text=${encodeURIComponent(`Hola, soy ${props.customer.name}. Te consulto por mi cuenta corriente.`)}`;
});
</script>

<template>
    <Head :title="`Mi cuenta · ${company.name}`" />
    <div class="min-h-screen bg-steel-100 print:bg-white">
        <header class="bg-steel-800 text-white print:hidden">
            <div class="h-1.5" style="background: repeating-linear-gradient(-45deg, #F2B705 0 12px, #1F2A37 12px 24px)" />
            <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-4">
                <div>
                    <div class="text-lg font-semibold">{{ company.name }}</div>
                    <div class="text-sm text-steel-300">{{ [company.address, company.phone].filter(Boolean).join(' · ') }}</div>
                </div>
                <div class="flex gap-2">
                    <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="btn btn-sm bg-[#25D366] text-white hover:bg-[#1fb457]">Escribinos por WhatsApp</a>
                    <a v-else-if="company.phone" :href="`tel:${company.phone}`" class="btn btn-sm bg-white/10 text-white hover:bg-white/20"><Phone class="size-4" /> {{ company.phone }}</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-6 print:hidden">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-sm text-steel-500">Resumen de cuenta de</p>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ customer.name }}</h1>
                    <p class="text-sm text-steel-500">
                        <span v-if="customer.document_number">{{ customer.document_type }} {{ customer.document_number }} · </span>Actualizado {{ datetime(generatedAt) }}
                    </p>
                </div>
                <button class="btn-primary" :disabled="busy" @click="statement"><Download class="size-4" /> {{ busy ? 'Generando…' : 'Descargar resumen PDF' }}</button>
            </div>

            <div class="panel mb-4 grid grid-cols-2 divide-steel-200 md:grid-cols-4 md:divide-x">
                <div class="p-4">
                    <div class="text-sm text-steel-500">Saldo actual</div>
                    <div class="text-2xl font-semibold tabular-nums" :class="summary.balance > 0.009 ? 'text-up' : summary.balance < -0.009 ? 'text-down' : ''">{{ money(summary.balance) }}</div>
                    <div v-if="summary.balance < -0.009" class="text-xs text-down">Saldo a su favor</div>
                </div>
                <div class="p-4">
                    <div class="text-sm text-steel-500">Vencido</div>
                    <div class="text-2xl font-semibold tabular-nums" :class="summary.overdue > 0.009 && 'text-up'">{{ money(summary.overdue) }}</div>
                </div>
                <div class="border-t border-steel-200 p-4 md:border-t-0">
                    <div class="text-sm text-steel-500">A vencer</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ money(summary.not_due) }}</div>
                    <div v-if="summary.next_due" class="text-xs text-steel-500">Próximo vencimiento {{ date(summary.next_due) }}</div>
                </div>
                <div class="border-t border-steel-200 p-4 md:border-t-0">
                    <div class="text-sm text-steel-500">Crédito disponible</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ summary.available === null ? '—' : money(summary.available) }}</div>
                    <div v-if="summary.available !== null" class="text-xs text-steel-500">Límite {{ money(customer.credit_limit) }}</div>
                </div>
            </div>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <nav class="flex gap-1 overflow-x-auto rounded-lg bg-white p-1 shadow-xs">
                    <button
                        v-for="t in tabs"
                        :key="t.k"
                        class="rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap"
                        :class="tab === t.k ? 'bg-steel-800 text-white' : 'text-steel-600 hover:bg-steel-100'"
                        @click="tab = t.k"
                    >
                        {{ t.t }}<span v-if="t.n !== null" class="ml-1 opacity-70">{{ t.n }}</span>
                    </button>
                </nav>
                <label v-if="tab !== 'pending'" class="flex items-center gap-2 text-sm text-steel-600">
                    Período
                    <select class="input w-auto py-1" :value="months" @change="setMonths(Number($event.target.value))">
                        <option :value="3">Últimos 3 meses</option>
                        <option :value="6">Últimos 6 meses</option>
                        <option :value="12">Último año</option>
                        <option :value="24">Últimos 2 años</option>
                        <option :value="0">Todo</option>
                    </select>
                </label>
            </div>

            <!-- Pendientes -->
            <div v-if="tab === 'pending'" class="panel divide-y divide-steel-100 md:hidden">
                <div v-for="p in pending" :key="p.id" class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium">{{ p.description }}</div>
                            <div class="text-xs text-steel-500">{{ date(p.date) }} · importe {{ money(p.debit) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold tabular-nums" :class="p.overdue && 'text-up'">{{ money(p.pending) }}</div>
                            <div class="text-xs" :class="p.overdue ? 'text-up' : 'text-steel-500'">{{ dueLabel(p) }}</div>
                        </div>
                    </div>
                    <div v-if="p.sale_id" class="mt-2 flex gap-2">
                        <button class="btn-secondary btn-sm" @click="openSale(p.sale_id)"><Eye class="size-4" /> Ver</button>
                        <button class="btn-secondary btn-sm" @click="pdfSale(p.sale_id)"><Download class="size-4" /> PDF</button>
                    </div>
                </div>
                <div v-if="pending.length" class="flex justify-between bg-steel-50 p-4 font-semibold"><span>Total pendiente</span><span class="tabular-nums">{{ money(summary.overdue + summary.not_due) }}</span></div>
                <p v-else class="p-6 text-center text-steel-500">No tenés comprobantes pendientes de pago. ¡Gracias!</p>
            </div>
            <div v-if="tab === 'pending'" class="panel hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Comprobante</th><th>Vencimiento</th><th class="num">Importe</th><th class="num">Pendiente</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="p in pending" :key="p.id">
                            <td class="whitespace-nowrap">{{ date(p.date) }}</td>
                            <td>{{ p.description }}</td>
                            <td class="whitespace-nowrap" :class="p.overdue && 'text-up'">{{ date(p.due_date) }}<div class="text-xs">{{ dueLabel(p) }}</div></td>
                            <td class="num">{{ money(p.debit) }}</td>
                            <td class="num font-semibold" :class="p.overdue && 'text-up'">{{ money(p.pending) }}</td>
                            <td class="text-right whitespace-nowrap">
                                <template v-if="p.sale_id">
                                    <button class="btn-ghost btn-sm" :disabled="loadingId === p.sale_id" @click="openSale(p.sale_id)"><Eye class="size-4" /> Ver</button>
                                    <button class="btn-ghost btn-sm" @click="pdfSale(p.sale_id)"><Download class="size-4" /> PDF</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!pending.length"><td colspan="6" class="py-8 text-center text-steel-500">No tenés comprobantes pendientes de pago. ¡Gracias!</td></tr>
                    </tbody>
                    <tfoot v-if="pending.length">
                        <tr><td colspan="4" class="text-right font-medium">Total pendiente</td><td class="num font-semibold">{{ money(summary.overdue + summary.not_due) }}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>

            <!-- Comprobantes -->
            <div v-if="tab === 'sales'" class="panel divide-y divide-steel-100 md:hidden">
                <div v-for="s in sales" :key="s.id" class="flex items-center justify-between gap-3 p-4" :class="s.status === 'cancelled' && 'text-steel-400'">
                    <div>
                        <div class="font-medium">{{ s.voucher_type === 'arca' ? 'Factura' : 'Ticket' }} N° {{ String(s.number).padStart(8, '0') }}</div>
                        <div class="text-xs text-steel-500">{{ date(s.created_at) }} · {{ money(s.total) }}<span v-if="saleState(s)"> · {{ saleState(s) }}</span></div>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <button class="btn-secondary btn-sm" aria-label="Ver" @click="openSale(s.id)"><Eye class="size-4" /></button>
                        <button class="btn-secondary btn-sm" aria-label="Descargar PDF" @click="pdfSale(s.id)"><Download class="size-4" /></button>
                    </div>
                </div>
                <p v-if="!sales.length" class="p-6 text-center text-steel-500">No hay comprobantes en este período.</p>
            </div>
            <div v-if="tab === 'sales'" class="panel hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Comprobante</th><th>Sucursal</th><th class="num">Total</th><th class="num">A cuenta</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in sales" :key="s.id" :class="s.status === 'cancelled' && 'text-steel-400'">
                            <td class="whitespace-nowrap">{{ date(s.created_at) }}</td>
                            <td class="whitespace-nowrap">
                                {{ s.voucher_type === 'arca' ? 'Factura' : 'Ticket' }} N° {{ String(s.number).padStart(8, '0') }}
                                <span v-if="saleState(s)" class="badge-gray ml-1">{{ saleState(s) }}</span>
                            </td>
                            <td>{{ s.branch?.name }}</td>
                            <td class="num">{{ money(s.total) }}</td>
                            <td class="num">{{ Number(s.on_account_amount) ? money(s.on_account_amount) : '' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button class="btn-ghost btn-sm" :disabled="loadingId === s.id" @click="openSale(s.id)"><Eye class="size-4" /> Ver</button>
                                <button class="btn-ghost btn-sm" @click="pdfSale(s.id)"><Download class="size-4" /> PDF</button>
                            </td>
                        </tr>
                        <tr v-if="!sales.length"><td colspan="6" class="py-8 text-center text-steel-500">No hay comprobantes en este período.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagos -->
            <div v-if="tab === 'payments'" class="panel divide-y divide-steel-100 md:hidden">
                <div v-for="p in payments" :key="p.id" class="flex items-center justify-between gap-3 p-4">
                    <div>
                        <div class="font-semibold text-down tabular-nums">{{ money(p.credit) }}</div>
                        <div class="text-xs text-steel-500">{{ datetime(p.date) }} · {{ methods[p.method] ?? p.method ?? '—' }}</div>
                    </div>
                    <button class="btn-secondary btn-sm shrink-0" @click="receipt(p)"><Receipt class="size-4" /> Recibo</button>
                </div>
                <p v-if="!payments.length" class="p-6 text-center text-steel-500">No hay pagos en este período.</p>
            </div>
            <div v-if="tab === 'payments'" class="panel hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Medio de pago</th><th>Detalle</th><th class="num">Importe</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="p in payments" :key="p.id">
                            <td class="whitespace-nowrap">{{ datetime(p.date) }}</td>
                            <td>{{ methods[p.method] ?? p.method ?? '—' }}</td>
                            <td>{{ p.description }}</td>
                            <td class="num font-semibold text-down">{{ money(p.credit) }}</td>
                            <td class="text-right"><button class="btn-ghost btn-sm" @click="receipt(p)"><Receipt class="size-4" /> Recibo</button></td>
                        </tr>
                        <tr v-if="!payments.length"><td colspan="5" class="py-8 text-center text-steel-500">No hay pagos en este período.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Cuenta corriente -->
            <div v-if="tab === 'movements'" class="panel overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Vence</th><th class="num">Debe</th><th class="num">Haber</th><th class="num">Saldo</th></tr></thead>
                    <tbody>
                        <tr class="bg-steel-50 font-medium text-steel-600"><td></td><td>Saldo anterior</td><td></td><td></td><td></td><td class="num">{{ money(previousBalance) }}</td></tr>
                        <tr v-for="m in movements" :key="m.id">
                            <td class="whitespace-nowrap">{{ date(m.date) }}</td>
                            <td>
                                <span class="badge-gray mr-1">{{ TYPES[m.type] ?? m.type }}</span>{{ m.description }}
                                <button v-if="m.sale_id" class="link ml-1 text-xs" @click="openSale(m.sale_id)">ver</button>
                            </td>
                            <td class="whitespace-nowrap">{{ m.debit && m.due_date ? date(m.due_date) : '' }}</td>
                            <td class="num">{{ m.debit ? money(m.debit) : '' }}</td>
                            <td class="num">{{ m.credit ? money(m.credit) : '' }}</td>
                            <td class="num font-medium">{{ money(m.balance) }}</td>
                        </tr>
                        <tr v-if="!movements.length"><td colspan="6" class="py-8 text-center text-steel-500">Sin movimientos en este período.</td></tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-6 text-center text-xs text-steel-400">
                <FileText class="mr-1 inline size-3.5" />Este link es personal: no lo compartas. Ante cualquier diferencia, consultanos.
            </p>
        </main>

        <!-- Vista del comprobante -->
        <div v-if="ticket" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-steel-900/50 p-4 sm:pt-10 print:static print:block print:bg-white print:p-0" @mousedown.self="ticket = null">
            <div class="flex max-h-[calc(100vh-5rem)] w-full max-w-md flex-col rounded-lg bg-white shadow-xl print:max-h-none print:shadow-none" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b border-steel-200 px-4 py-3 print:hidden">
                    <h2 class="text-[15px] font-semibold">Comprobante</h2>
                    <button class="text-steel-400 hover:text-steel-800" aria-label="Cerrar" @click="ticket = null"><X class="size-5" /></button>
                </div>
                <div class="flex flex-1 justify-center overflow-y-auto bg-steel-100 p-4 print:bg-white print:p-0">
                    <TicketView v-bind="ticket" class="shadow-sm print:shadow-none" />
                </div>
                <div class="grid grid-cols-2 gap-2 border-t border-steel-200 p-4 print:hidden">
                    <button class="btn-secondary" @click="printTicket"><Printer class="size-4" /> Imprimir</button>
                    <button class="btn-primary" @click="pdfSale(ticket.sale.id)"><Download class="size-4" /> Descargar PDF</button>
                </div>
            </div>
        </div>
        <div v-if="loadingId" class="fixed right-4 bottom-4 z-50 flex items-center gap-2 rounded-md bg-steel-800 px-3 py-2 text-sm text-white shadow-lg"><Loader2 class="size-4 animate-spin" /> Cargando comprobante…</div>
    </div>
</template>
