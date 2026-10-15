<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Download, Printer, MessageCircle, CheckCircle2, CalendarClock, XCircle, Phone, MapPin } from '@lucide/vue';
import { money, qty, date, toNum } from '@/lib/format';
import { downloadQuotePdf, openQuotePdf } from '@/lib/quotePdf';
import { waLink } from '@/lib/whatsapp';

/**
 * Presupuesto que recibe el cliente por WhatsApp: lo ve en el celular, lo descarga y responde.
 */
const props = defineProps({ quote: Object, company: Object, settings: Object, publicUrl: String });
const q = computed(() => props.quote);
const busy = ref(false);
const data = () => ({ quote: q.value, company: props.company, settings: props.settings, publicUrl: props.publicUrl });
async function pdf(print = false) {
    busy.value = true;
    try {
        await (print ? openQuotePdf(data()) : downloadQuotePdf(data()));
    } finally {
        busy.value = false;
    }
}
const phone = computed(() => q.value.branch?.phone || props.company.phone);
const answer = (accept) =>
    waLink(
        phone.value,
        accept
            ? `Hola, ${q.value.customer ? 'soy ' + q.value.customer.name + '. ' : ''}Acepto el presupuesto N° ${q.value.number} por ${money(q.value.total)}. ¿Cómo seguimos?`.replace(/[  ]/g, ' ')
            : `Hola, tengo una consulta sobre el presupuesto N° ${q.value.number}.`,
    );
const daysLeft = computed(() => {
    if (!q.value.valid_until) return null;
    const t = new Date();
    return Math.round((new Date(q.value.valid_until + 'T00:00:00') - new Date(t.getFullYear(), t.getMonth(), t.getDate())) / 86400000);
});
</script>

<template>
    <Head :title="`Presupuesto N° ${quote.number} · ${company.name}`" />
    <div class="min-h-screen bg-steel-100">
        <header class="bg-steel-800 text-white">
            <div class="h-1.5" style="background: repeating-linear-gradient(-45deg, #F2B705 0 12px, #1F2A37 12px 24px)" />
            <div class="mx-auto max-w-4xl px-4 py-4">
                <div class="text-lg font-semibold">{{ company.name }}</div>
                <div class="flex flex-wrap gap-x-4 text-sm text-steel-300">
                    <span v-if="quote.branch?.address || company.address" class="flex items-center gap-1"><MapPin class="size-3.5" /> {{ quote.branch?.address || company.address }}</span>
                    <a v-if="phone" :href="`tel:${phone}`" class="flex items-center gap-1"><Phone class="size-3.5" /> {{ phone }}</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-6">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-sm text-steel-500">Presupuesto N° {{ quote.number }} · {{ date(quote.created_at) }}</p>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ quote.customer?.name ?? 'Presupuesto' }}</h1>
                    <p v-if="quote.user" class="text-sm text-steel-500">Te atendió {{ quote.user.name }}</p>
                </div>
                <div class="flex gap-2">
                    <button class="btn-secondary" :disabled="busy" @click="pdf(true)"><Printer class="size-4" /> Imprimir</button>
                    <button class="btn-primary" :disabled="busy" @click="pdf(false)"><Download class="size-4" /> Descargar PDF</button>
                </div>
            </div>

            <!-- Estado / validez -->
            <div v-if="quote.quote_state === 'pending'" class="mb-4 flex items-center gap-2 rounded-md border border-down/30 bg-down-bg px-4 py-3 text-sm text-down">
                <CalendarClock class="size-4 shrink-0" />
                <span>Precios válidos hasta el <b>{{ date(quote.valid_until) }}</b><span v-if="daysLeft !== null"> ({{ daysLeft === 0 ? 'vence hoy' : daysLeft === 1 ? 'queda 1 día' : `quedan ${daysLeft} días` }})</span>.</span>
            </div>
            <div v-else-if="quote.quote_state === 'expired'" class="mb-4 flex items-center gap-2 rounded-md border border-hazard-500/60 bg-hazard-300/25 px-4 py-3 text-sm">
                <CalendarClock class="size-4 shrink-0" /> Este presupuesto venció el {{ date(quote.valid_until) }}. Escribinos y te lo actualizamos.
            </div>
            <div v-else-if="quote.quote_state === 'converted'" class="mb-4 flex items-center gap-2 rounded-md border border-down/30 bg-down-bg px-4 py-3 text-sm text-down">
                <CheckCircle2 class="size-4 shrink-0" /> ¡Gracias! Este presupuesto ya se concretó.
            </div>
            <div v-else class="mb-4 flex items-center gap-2 rounded-md border border-steel-300 bg-white px-4 py-3 text-sm text-steel-600">
                <XCircle class="size-4 shrink-0" /> Este presupuesto está cerrado. Si lo necesitás, escribinos y lo reactivamos.
            </div>

            <!-- Detalle: tarjetas en celular, tabla en pantallas grandes -->
            <div class="panel divide-y divide-steel-100 md:hidden">
                <div v-for="i in quote.items" :key="i.id" class="p-4">
                    <div class="font-medium">{{ i.description }}</div>
                    <div class="mt-0.5 flex justify-between text-sm text-steel-600">
                        <span>{{ qty(i.quantity) }} × {{ money(i.unit_price) }}<span v-if="toNum(i.discount_percent)"> −{{ toNum(i.discount_percent) }}%</span></span>
                        <span class="font-semibold text-steel-900 tabular-nums">{{ money(i.subtotal) }}</span>
                    </div>
                </div>
            </div>
            <div class="panel hidden overflow-x-auto md:block">
                <table class="table">
                    <thead><tr><th>Código</th><th>Descripción</th><th class="num">Cantidad</th><th class="num">P. unitario</th><th class="num">Bonif.</th><th class="num">Importe</th></tr></thead>
                    <tbody>
                        <tr v-for="i in quote.items" :key="i.id">
                            <td class="code">{{ i.code }}</td>
                            <td>{{ i.description }}</td>
                            <td class="num">{{ qty(i.quantity) }}</td>
                            <td class="num">{{ money(i.unit_price) }}</td>
                            <td class="num">{{ toNum(i.discount_percent) ? toNum(i.discount_percent) + '%' : '' }}</td>
                            <td class="num font-medium">{{ money(i.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="panel mt-4 ml-auto max-w-sm p-4">
                <template v-if="toNum(quote.discount_amount)">
                    <div class="flex justify-between text-sm"><span class="text-steel-500">Subtotal</span><span class="tabular-nums">{{ money(quote.subtotal) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-steel-500">Descuento {{ toNum(quote.discount_percent) }}%</span><span class="tabular-nums">−{{ money(quote.discount_amount) }}</span></div>
                </template>
                <div class="mt-1 flex justify-between text-xl font-semibold"><span>Total</span><span class="tabular-nums">{{ money(quote.total) }}</span></div>
            </div>

            <div v-if="['pending', 'expired'].includes(quote.quote_state) && phone" class="mt-4 grid gap-2 sm:grid-cols-2">
                <a :href="answer(true)" target="_blank" rel="noopener" class="btn justify-center bg-[#25D366] py-3 text-base text-white hover:bg-[#1fb457]"><MessageCircle class="size-5" /> Acepto el presupuesto</a>
                <a :href="answer(false)" target="_blank" rel="noopener" class="btn-secondary justify-center py-3 text-base">Tengo una consulta</a>
            </div>

            <div v-if="quote.notes || quote.terms" class="panel mt-4 space-y-3 p-4 text-sm">
                <div v-if="quote.notes"><div class="font-medium">Observaciones</div><p class="whitespace-pre-line text-steel-600">{{ quote.notes }}</p></div>
                <div v-if="quote.terms"><div class="font-medium">Condiciones</div><p class="whitespace-pre-line text-steel-600">{{ quote.terms }}</p></div>
            </div>
            <p class="mt-6 text-center text-xs text-steel-400">Documento no válido como factura.</p>
        </main>
    </div>
</template>
