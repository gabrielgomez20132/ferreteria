<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Printer, Ban, Undo2 } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import TicketModal from '@/Components/TicketModal.vue';
import { money, qty, datetime, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ sale: Object, methods: Object });
const can = useCan();
const s = computed(() => props.sale);

const showCancel = ref(false);
const cancelForm = useForm({ reason: '' });
const doCancel = () => cancelForm.post(route('sales.cancel', s.value.id), { onSuccess: () => (showCancel.value = false) });

const showReturn = ref(false);
const returnForm = useForm({ items: {}, refund_method: 'cash', reason: '', restock: true });
const available = (i) => toNum(i.quantity) - toNum(i.returned_quantity);
const returnTotal = computed(() =>
    s.value.items.reduce((a, i) => a + (toNum(i.subtotal) / toNum(i.quantity)) * (1 - toNum(s.value.discount_percent) / 100) * toNum(returnForm.items[i.id] || 0), 0),
);
const doReturn = () => returnForm.post(route('sales.return', s.value.id), { onSuccess: () => (showReturn.value = false) });
const showTicket = ref(false);
</script>

<template>
    <PageHeader :title="`${s.type === 'quote' ? 'Presupuesto' : 'Venta'} #${s.number}`" :back="route('sales.index', s.type === 'quote' ? { type: 'quote' } : {})">
        <template #subtitle>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm text-steel-500">
                {{ datetime(s.created_at) }} · {{ s.branch?.name }} · {{ s.user?.name }} <StatusBadge :status="s.status" />
                <Link v-if="s.quote" :href="route('quotes.show', s.quote.id)" class="link">desde el presupuesto #{{ s.quote.number }}</Link>
            </p>
        </template>
        <button class="btn-secondary" @click="showTicket = true"><Printer class="size-4" /> Comprobante</button>
        <Link v-if="s.type === 'quote'" :href="route('pos', { quote: s.id })" class="btn-primary">Convertir en venta</Link>
        <button v-if="s.status === 'completed' && can('sales.return')" class="btn-secondary" @click="showReturn = true"><Undo2 class="size-4" /> Devolución</button>
        <button v-if="s.status === 'completed' && can('sales.cancel') && !s.returns.length" class="btn-danger" @click="showCancel = true"><Ban class="size-4" /> Anular</button>
    </PageHeader>

    <div v-if="s.status === 'cancelled'" class="mb-4 rounded-md border border-up/30 bg-up-bg px-4 py-2.5 text-sm text-up">
        Anulada el {{ datetime(s.cancelled_at) }} por {{ s.canceller?.name }}. Motivo: {{ s.cancel_reason }}
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
        <div class="panel">
            <table class="table">
                <thead><tr><th>Producto</th><th class="num">Cantidad</th><th class="num">Precio</th><th class="num">Desc.</th><th class="num">Subtotal</th></tr></thead>
                <tbody>
                    <tr v-for="i in s.items" :key="i.id">
                        <td>{{ i.description }}<div class="code">{{ i.code }}<span v-if="toNum(i.returned_quantity)" class="text-up"> · devuelto {{ qty(i.returned_quantity) }}</span></div></td>
                        <td class="num">{{ qty(i.quantity) }}</td>
                        <td class="num">{{ money(i.unit_price) }}</td>
                        <td class="num">{{ toNum(i.discount_percent) ? i.discount_percent + '%' : '' }}</td>
                        <td class="num font-medium">{{ money(i.subtotal) }}</td>
                    </tr>
                </tbody>
                <tfoot class="text-sm">
                    <tr v-if="toNum(s.discount_amount)"><td colspan="4" class="px-3 py-1.5 text-right text-steel-500">Descuento {{ s.discount_percent }}%</td><td class="num px-3">− {{ money(s.discount_amount) }}</td></tr>
                    <tr><td colspan="4" class="px-3 py-2 text-right font-semibold">Total</td><td class="num px-3 text-lg font-semibold">{{ money(s.total) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <div class="space-y-4">
            <div class="panel p-4 text-sm">
                <div class="text-steel-500">Cliente</div>
                <div class="font-medium"><Link v-if="s.customer" :href="route('customers.show', s.customer.id)" class="link">{{ s.customer.name }}</Link><span v-else>Consumidor final</span></div>
                <div v-if="s.price_list" class="mt-2 text-steel-500">Lista: {{ s.price_list.name }}</div>
                <div v-if="s.notes" class="mt-2 text-steel-600">{{ s.notes }}</div>
            </div>
            <div v-if="s.payments.length" class="panel p-4 text-sm">
                <div class="mb-1 font-medium">Pagos</div>
                <div v-for="p in s.payments" :key="p.id" class="flex justify-between py-0.5"><span>{{ methods[p.method] }}</span><span class="tabular-nums">{{ money(p.amount) }}</span></div>
                <div v-if="toNum(s.change_amount)" class="flex justify-between py-0.5 text-steel-500"><span>Vuelto</span><span class="tabular-nums">{{ money(s.change_amount) }}</span></div>
            </div>
            <div v-for="r in s.returns" :key="r.id" class="panel p-4 text-sm">
                <div class="font-medium">Devolución · {{ money(r.total) }}</div>
                <div class="text-xs text-steel-500">{{ datetime(r.created_at) }} · {{ r.user?.name }} · {{ methods[r.refund_method] }}</div>
                <div v-if="r.reason" class="mt-1 text-steel-600">{{ r.reason }}</div>
            </div>
        </div>
    </div>

    <Modal :show="showCancel" title="Anular venta" @close="showCancel = false">
        <p class="mb-3 text-sm text-steel-600">Se repone el stock, se registra la salida del dinero en tu caja y se revierte la cuenta corriente si corresponde.</p>
        <Field label="Motivo" :error="cancelForm.errors.reason"><input v-model="cancelForm.reason" class="input" autofocus /></Field>
        <template #footer>
            <button class="btn-secondary" @click="showCancel = false">Volver</button>
            <button class="btn-danger" :disabled="cancelForm.processing || !cancelForm.reason" @click="doCancel">Anular venta</button>
        </template>
    </Modal>

    <Modal :show="showReturn" title="Devolución de productos" width="max-w-2xl" @close="showReturn = false">
        <table class="table">
            <thead><tr><th>Producto</th><th class="num">Disponible</th><th class="num w-32">Devolver</th></tr></thead>
            <tbody>
                <tr v-for="i in s.items" :key="i.id">
                    <td>{{ i.description }}</td>
                    <td class="num">{{ qty(available(i)) }}</td>
                    <td class="num"><input v-model="returnForm.items[i.id]" type="number" min="0" :max="available(i)" step="any" class="input w-24 text-right" :disabled="available(i) <= 0" /></td>
                </tr>
            </tbody>
        </table>
        <div class="mt-3 grid grid-cols-2 gap-3">
            <Field label="Reintegro">
                <select v-model="returnForm.refund_method" class="input">
                    <option v-for="(l, k) in methods" :key="k" :value="k" :disabled="k === 'account' && !s.customer">{{ k === 'account' ? 'Crédito en cuenta corriente' : l }}</option>
                </select>
            </Field>
            <Field label="Motivo"><input v-model="returnForm.reason" class="input" /></Field>
        </div>
        <label class="mt-3 flex items-center gap-2 text-sm"><input v-model="returnForm.restock" type="checkbox" class="checkbox" /> Vuelve al stock (desmarcar si está fallado)</label>
        <template #footer>
            <span class="mr-auto self-center text-sm">A reintegrar: <strong class="tabular-nums">{{ money(returnTotal) }}</strong></span>
            <button class="btn-secondary" @click="showReturn = false">Cancelar</button>
            <button class="btn-primary" :disabled="returnForm.processing || returnTotal <= 0" @click="doReturn">Registrar devolución</button>
        </template>
    </Modal>

    <TicketModal :sale-id="showTicket ? s.id : null" :title="`${s.type === 'quote' ? 'Presupuesto' : 'Venta'} #${s.number}`" @close="showTicket = false" />
</template>
