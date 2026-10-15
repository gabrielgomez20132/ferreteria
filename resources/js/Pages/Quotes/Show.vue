<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ShoppingCart, Pencil, Copy, Download, Printer, MessageCircle, Link2, XCircle, RotateCcw, Receipt, CalendarClock, Eye, Send } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import TicketModal from '@/Components/TicketModal.vue';
import QuoteStateBadge from '@/Components/QuoteStateBadge.vue';
import { money, qty, date, datetime, today, toNum } from '@/lib/format';
import { postJson } from '@/lib/http';
import { downloadQuotePdf, openQuotePdf } from '@/lib/quotePdf';
import { quoteMessage } from '@/lib/quoteMessage';
import { waLink } from '@/lib/whatsapp';
import { useCan } from '@/lib/can';

const props = defineProps({ quote: Object, publicUrl: String, company: Object, settings: Object, rejectReasons: Array, states: Object });
const can = useCan();
const q = computed(() => props.quote);
const open = computed(() => ['pending', 'expired'].includes(q.value.quote_state));
const pdfData = () => ({ quote: q.value, company: props.company, settings: props.settings, publicUrl: props.publicUrl });

const busy = ref(false);
async function pdf(print = false) {
    busy.value = true;
    try {
        await (print ? openQuotePdf(pdfData()) : downloadQuotePdf(pdfData()));
    } finally {
        busy.value = false;
    }
}
function whatsapp() {
    window.open(waLink(q.value.customer?.phone, quoteMessage(q.value, props.company, props.publicUrl)), '_blank');
    postJson(route('quotes.sent', q.value.id)).then(() => router.reload({ only: ['quote'] })).catch(() => {});
}
const copied = ref(false);
async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.publicUrl);
    } catch {
        window.prompt('Copiá el link:', props.publicUrl);
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

// ---- rechazar ----
const showReject = ref(false);
const reject = useForm({ reason: '', detail: '' });
const doReject = () =>
    reject
        .transform((d) => ({ reason: d.reason === 'Otro' || !d.reason ? d.detail || 'Otro' : d.detail ? `${d.reason}: ${d.detail}` : d.reason }))
        .post(route('quotes.reject', q.value.id), { preserveScroll: true, onSuccess: () => (showReject.value = false) });
const reopen = () => router.post(route('quotes.reopen', q.value.id), {}, { preserveScroll: true });

// ---- validez y condiciones ----
const showDetails = ref(false);
const details = useForm({ valid_until: q.value.valid_until, terms: q.value.terms ?? '', notes: q.value.notes ?? '' });
const openDetails = () => {
    details.valid_until = q.value.valid_until;
    details.terms = q.value.terms ?? '';
    details.notes = q.value.notes ?? '';
    showDetails.value = true;
};
const extend = (days) => (details.valid_until = today(days));
const saveDetails = () => details.patch(route('quotes.details', q.value.id), { preserveScroll: true, onSuccess: () => (showDetails.value = false) });

const showTicket = ref(false);
const daysLeft = computed(() => (q.value.valid_until ? Math.round((new Date(q.value.valid_until + 'T00:00:00') - new Date(today() + 'T00:00:00')) / 86400000) : null));
const margin = computed(() => {
    const net = q.value.items.reduce((a, i) => a + (toNum(i.subtotal) * (1 - toNum(q.value.discount_percent) / 100)) / (1 + toNum(i.vat_rate) / 100), 0);
    const cost = q.value.items.reduce((a, i) => a + toNum(i.quantity) * toNum(i.unit_cost), 0);
    return net > 0 ? ((net - cost) / net) * 100 : 0;
});
</script>

<template>
    <PageHeader :title="`Presupuesto #${q.number}`" :back="route('quotes.index')">
        <template #subtitle>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-steel-500">
                {{ datetime(q.created_at) }} · {{ q.user?.name }} · {{ q.branch?.name }} <QuoteStateBadge :state="q.quote_state" />
            </p>
        </template>
        <button class="btn-secondary" :disabled="busy" @click="pdf(true)"><Printer class="size-4" /> Imprimir</button>
        <button class="btn-secondary" :disabled="busy" @click="pdf(false)"><Download class="size-4" /> PDF</button>
        <button class="btn-secondary" @click="whatsapp"><MessageCircle class="size-4" /> WhatsApp</button>
        <template v-if="can('sales.create')">
            <Link :href="route('pos', { quote: q.id, mode: 'copy' })" class="btn-secondary"><Copy class="size-4" /> Duplicar</Link>
            <Link v-if="open" :href="route('pos', { quote: q.id, mode: 'edit' })" class="btn-secondary"><Pencil class="size-4" /> Editar</Link>
            <Link v-if="open" :href="route('pos', { quote: q.id })" class="btn-primary"><ShoppingCart class="size-4" /> Convertir en venta</Link>
        </template>
    </PageHeader>

    <!-- Estado -->
    <div v-if="q.quote_state === 'converted'" class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-down/30 bg-down-bg px-4 py-3 text-sm text-down">
        <Receipt class="size-4" /> Convertido en la
        <Link :href="route('sales.show', q.converted_sale.id)" class="font-semibold underline">venta #{{ q.converted_sale.number }}</Link>
        el {{ datetime(q.converted_at) }} por {{ money(q.converted_sale.total) }}.
    </div>
    <div v-else-if="q.quote_state === 'rejected'" class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-up/30 bg-up-bg px-4 py-3 text-sm text-up">
        <XCircle class="size-4" /> Rechazado el {{ date(q.rejected_at) }}<span v-if="q.reject_reason">: {{ q.reject_reason }}</span>
        <button v-if="can('sales.create')" class="btn-secondary btn-sm ml-auto" @click="reopen"><RotateCcw class="size-4" /> Reabrir</button>
    </div>
    <div v-else-if="q.quote_state === 'expired'" class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-hazard-500/60 bg-hazard-300/25 px-4 py-3 text-sm">
        <CalendarClock class="size-4" /> Venció el {{ date(q.valid_until) }}. Si lo cobrás, se usan los precios actuales. Podés extender la validez para mantener los precios.
        <button v-if="can('sales.create')" class="btn-secondary btn-sm ml-auto" @click="openDetails">Extender validez</button>
        <button v-if="can('sales.create')" class="btn-ghost btn-sm text-up" @click="showReject = true">Marcar rechazado</button>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1fr_340px]">
        <div class="space-y-4">
            <div class="panel overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">P. unitario</th><th class="num">Bonif.</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                        <tr v-for="i in q.items" :key="i.id">
                            <td>{{ i.description }}<div class="code">{{ i.code }}</div></td>
                            <td class="num">{{ qty(i.quantity) }}</td>
                            <td class="num">{{ money(i.unit_price) }}</td>
                            <td class="num">{{ toNum(i.discount_percent) ? toNum(i.discount_percent) + '%' : '' }}</td>
                            <td class="num font-medium">{{ money(i.subtotal) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <template v-if="toNum(q.discount_amount)">
                            <tr><td colspan="4" class="text-right">Subtotal</td><td class="num">{{ money(q.subtotal) }}</td></tr>
                            <tr><td colspan="4" class="text-right">Descuento {{ toNum(q.discount_percent) }}%</td><td class="num">-{{ money(q.discount_amount) }}</td></tr>
                        </template>
                        <tr><td colspan="4" class="text-right text-base font-semibold">Total</td><td class="num text-base font-semibold">{{ money(q.total) }}</td></tr>
                    </tfoot>
                </table>
            </div>
            <div v-if="q.notes || q.terms" class="panel grid gap-4 p-4 text-sm md:grid-cols-2">
                <div v-if="q.notes"><div class="font-medium">Observaciones</div><p class="whitespace-pre-line text-steel-600">{{ q.notes }}</p></div>
                <div v-if="q.terms"><div class="font-medium">Condiciones</div><p class="whitespace-pre-line text-steel-600">{{ q.terms }}</p></div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="panel p-4 text-sm">
                <div class="mb-1 text-steel-500">Cliente</div>
                <template v-if="q.customer">
                    <Link :href="route('customers.show', q.customer.id)" class="link text-base font-semibold">{{ q.customer.name }}</Link>
                    <div class="text-steel-600">{{ q.customer.document_type }} {{ q.customer.document_number }} · {{ q.customer.tax_condition }}</div>
                    <div v-if="q.customer.phone" class="text-steel-600">{{ q.customer.phone }}</div>
                </template>
                <div v-else class="text-steel-600">Sin cliente asignado (podés asignarlo con Editar)</div>

                <div class="mt-4 grid grid-cols-2 gap-y-2">
                    <div class="text-steel-500">Válido hasta</div>
                    <div class="text-right font-medium">
                        {{ date(q.valid_until) }}
                        <div v-if="q.quote_state === 'pending' && daysLeft !== null" class="text-xs font-normal" :class="daysLeft <= 3 ? 'text-[#7a5b00]' : 'text-steel-500'">{{ daysLeft === 0 ? 'vence hoy' : daysLeft === 1 ? 'queda 1 día' : `quedan ${daysLeft} días` }}</div>
                    </div>
                    <div class="text-steel-500">Lista de precios</div><div class="text-right">{{ q.price_list?.name ?? '—' }}</div>
                    <div v-if="can('reports.view')" class="text-steel-500">Margen estimado</div><div v-if="can('reports.view')" class="text-right">{{ margin.toFixed(1).replace('.', ',') }}%</div>
                </div>
                <button v-if="can('sales.create') && open" class="btn-secondary btn-sm mt-3 w-full" @click="openDetails"><CalendarClock class="size-4" /> Validez, condiciones y observaciones</button>
                <button v-if="can('sales.create') && q.quote_state === 'pending'" class="btn-ghost btn-sm mt-1 w-full text-up" @click="showReject = true"><XCircle class="size-4" /> Marcar como rechazado</button>
            </div>

            <div class="panel p-4 text-sm">
                <div class="mb-1 flex items-center gap-2 font-medium"><Link2 class="size-4 text-steel-500" /> Link para el cliente</div>
                <p class="text-steel-600">El cliente lo abre desde el celular, lo descarga en PDF y te responde por WhatsApp.</p>
                <input :value="publicUrl" readonly class="input mt-2 font-mono text-xs" @focus="$event.target.select()" />
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <button class="btn-secondary btn-sm" @click="copyLink">{{ copied ? '¡Copiado!' : 'Copiar link' }}</button>
                    <a :href="publicUrl" target="_blank" class="btn-secondary btn-sm"><Eye class="size-4" /> Ver como cliente</a>
                </div>
                <div class="mt-3 space-y-1 text-xs text-steel-500">
                    <div class="flex items-center gap-1.5"><Send class="size-3.5" /> {{ q.sent_at ? 'Enviado ' + datetime(q.sent_at) : 'Todavía no se envió' }}</div>
                    <div class="flex items-center gap-1.5"><Eye class="size-3.5" /> {{ q.viewed_at ? 'El cliente lo abrió ' + datetime(q.viewed_at) : 'El cliente todavía no lo abrió' }}</div>
                </div>
            </div>

            <button class="btn-ghost btn-sm w-full" @click="showTicket = true"><Receipt class="size-4" /> Ver en formato ticket (80 mm)</button>
        </div>
    </div>

    <Modal :show="showReject" title="Marcar presupuesto como rechazado" @close="showReject = false">
        <p class="mb-3 text-sm text-steel-600">Registrar el motivo te permite ver en el reporte por qué se pierden ventas.</p>
        <div class="grid gap-2 sm:grid-cols-2">
            <label v-for="r in rejectReasons" :key="r" class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm" :class="reject.reason === r ? 'border-steel-800 bg-steel-50' : 'border-steel-200'">
                <input v-model="reject.reason" type="radio" :value="r" /> {{ r }}
            </label>
        </div>
        <Field class="mt-3" label="Detalle (opcional)" :error="reject.errors.reason"><input v-model="reject.detail" class="input" placeholder="Ej.: consiguió 15 % más barato en otra ferretería" /></Field>
        <template #footer>
            <button class="btn-secondary" @click="showReject = false">Cancelar</button>
            <button class="btn-danger" :disabled="reject.processing || (!reject.reason && !reject.detail)" @click="doReject">Marcar rechazado</button>
        </template>
    </Modal>

    <Modal :show="showDetails" title="Validez, condiciones y observaciones" width="max-w-xl" @close="showDetails = false">
        <div class="grid gap-3">
            <Field label="Válido hasta" :error="details.errors.valid_until">
                <div class="flex flex-wrap items-center gap-2">
                    <input v-model="details.valid_until" type="date" class="input w-44" />
                    <button v-for="d in [7, 15, 30]" :key="d" type="button" class="btn-ghost btn-sm" @click="extend(d)">+{{ d }} días desde hoy</button>
                </div>
            </Field>
            <Field label="Observaciones" hint="Ej.: entrega en obra, plazo de entrega"><textarea v-model="details.notes" rows="2" class="input" /></Field>
            <Field label="Condiciones comerciales" :error="details.errors.terms"><textarea v-model="details.terms" rows="4" class="input" /></Field>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showDetails = false">Cancelar</button>
            <button class="btn-primary" :disabled="details.processing" @click="saveDetails">Guardar</button>
        </template>
    </Modal>

    <TicketModal :sale-id="showTicket ? q.id : null" :title="`Presupuesto #${q.number}`" @close="showTicket = false" />
</template>
