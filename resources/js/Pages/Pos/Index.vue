<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Trash2, UserPlus, Printer, X, Plus, FileText, Wallet } from '@lucide/vue';
import ProductSearch from '@/Components/ProductSearch.vue';
import Modal from '@/Components/Modal.vue';
import TicketModal from '@/Components/TicketModal.vue';
import Field from '@/Components/Field.vue';
import { getJson, postJson } from '@/lib/http';
import { money, qty as fmtQty, round2, toNum } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ priceLists: Array, methods: Object, session: Object, requireSession: Boolean, consumidorFinal: Object, quote: Object, lastSale: Object });
const page = usePage();
const can = useCan();
const maxDiscount = computed(() => toNum(page.props.auth.user.max_discount));
const canOverride = computed(() => can('sales.price_override'));

const search = ref(null);
const items = ref([]);
const customer = ref(null);
const priceListId = ref(props.priceLists.find((l) => l.is_default)?.id ?? null);
const globalDiscount = ref(0);
const notes = ref('');
const payments = ref([{ method: 'cash', amount: null }]);
const processing = ref(false);
const lastSale = ref(props.lastSale);
const voucherType = ref('ticket'); // 'factura' (ARCA) se habilitará más adelante
const ticketFor = ref(null); // venta cuyo comprobante se muestra en el pop-up

// ---------- Carrito ----------
function addProduct(p) {
    const existing = items.value.find((i) => i.product_id === p.id);
    if (existing) {
        existing.quantity = round2(toNum(existing.quantity) + 1);
        flash(existing);
        return;
    }
    items.value.unshift({
        product_id: p.id, code: p.code, name: p.name, unit: p.unit, allow_fraction: p.allow_fraction,
        list_price: p.price, unit_price: p.price, quantity: 1, discount_percent: 0,
        stock: p.stock, track_stock: p.track_stock, _flash: true,
    });
    setTimeout(() => (items.value[0] && (items.value[0]._flash = false)), 600);
}
const flash = (i) => {
    i._flash = true;
    setTimeout(() => (i._flash = false), 600);
};
const lineTotal = (i) => round2(toNum(i.quantity) * toNum(i.unit_price) * (1 - toNum(i.discount_percent) / 100));
const subtotal = computed(() => round2(items.value.reduce((a, i) => a + lineTotal(i), 0)));
const discountAmount = computed(() => round2((subtotal.value * toNum(globalDiscount.value)) / 100));
const total = computed(() => round2(subtotal.value - discountAmount.value));
const remove = (idx) => items.value.splice(idx, 1);

// ---------- Pagos ----------
const paid = computed(() => round2(payments.value.reduce((a, p) => a + toNum(p.amount), 0)));
const cashPaid = computed(() => payments.value.filter((p) => p.method === 'cash').reduce((a, p) => a + toNum(p.amount), 0));
const change = computed(() => Math.max(0, round2(paid.value - total.value)));
const missing = computed(() => Math.max(0, round2(total.value - paid.value)));
watch(total, (t) => {
    // con un solo medio de pago, el importe sigue al total
    if (payments.value.length === 1) payments.value[0].amount = t || null;
});
const addPayment = () => payments.value.push({ method: 'transfer', amount: missing.value || null });

// ---------- Cliente ----------
const customerQuery = ref('');
const customerResults = ref([]);
let ct = null;
watch(customerQuery, (q) => {
    clearTimeout(ct);
    if (q.length < 2) return (customerResults.value = []);
    ct = setTimeout(async () => (customerResults.value = await getJson(route('customers.search'), { q })), 250);
});
function setCustomer(c) {
    customer.value = c;
    customerQuery.value = '';
    customerResults.value = [];
    if (c?.price_list_id) priceListId.value = c.price_list_id;
}
const showNewCustomer = ref(false);
const newCustomer = ref({ name: '', document_type: 'DNI', document_number: '', tax_condition: 'Consumidor Final', phone: '' });
const newCustomerErrors = ref({});
async function createCustomer() {
    try {
        const c = await postJson(route('customers.store'), newCustomer.value);
        setCustomer(c);
        showNewCustomer.value = false;
        newCustomer.value = { name: '', document_type: 'DNI', document_number: '', tax_condition: 'Consumidor Final', phone: '' };
    } catch (e) {
        newCustomerErrors.value = e.errors;
    }
}

// Al cambiar de lista se vuelven a cotizar los ítems
watch(priceListId, async (id) => {
    for (const i of items.value) {
        const r = await getJson(route('products.search'), { q: i.code, price_list_id: id });
        const p = r.items.find((x) => x.id === i.product_id);
        if (p) {
            i.list_price = p.price;
            i.unit_price = p.price;
        }
    }
});

// ---------- Presupuesto cargado ----------
onMounted(async () => {
    if (props.quote) {
        if (props.quote.customer) setCustomer(props.quote.customer);
        for (const it of props.quote.items) {
            const r = await getJson(route('products.search'), { q: it.code, price_list_id: priceListId.value });
            const p = r.items.find((x) => x.id === it.product_id);
            if (p) {
                addProduct(p);
                items.value[0].quantity = toNum(it.quantity);
                items.value[0].discount_percent = toNum(it.discount_percent);
            }
        }
    }
    search.value?.focus();
});

// ---------- Confirmar ----------
const errors = computed(() => {
    const e = [];
    if (!items.value.length) e.push('Agregá productos');
    if (props.requireSession && !props.session) e.push('Abrí la caja');
    for (const i of items.value) {
        if (toNum(i.quantity) <= 0) e.push(`Cantidad inválida en ${i.name}`);
        if (!i.allow_fraction && !Number.isInteger(toNum(i.quantity))) e.push(`${i.name} no se vende fraccionado`);
        if (toNum(i.discount_percent) > maxDiscount.value) e.push(`Descuento máximo ${maxDiscount.value}%`);
    }
    if (toNum(globalDiscount.value) > maxDiscount.value) e.push(`Descuento máximo ${maxDiscount.value}%`);
    if (missing.value > 0) e.push(`Faltan ${money(missing.value)}`);
    if (change.value > cashPaid.value + 0.001) e.push('El vuelto solo puede salir del efectivo');
    if (payments.value.some((p) => p.method === 'account') && !customer.value) e.push('Elegí un cliente para cuenta corriente');
    return [...new Set(e)];
});

function submit(type = 'sale') {
    if (type === 'sale' && errors.value.length) return;
    if (type === 'quote' && !items.value.length) return;
    processing.value = true;
    router.post(route('pos.store'), {
        type,
        voucher_type: type === 'sale' ? voucherType.value : null,
        customer_id: customer.value?.id ?? null,
        price_list_id: priceListId.value,
        discount_percent: toNum(globalDiscount.value),
        notes: notes.value,
        items: items.value.map((i) => ({
            product_id: i.product_id, quantity: toNum(i.quantity), discount_percent: toNum(i.discount_percent),
            unit_price: canOverride.value && toNum(i.unit_price) !== toNum(i.list_price) ? toNum(i.unit_price) : null,
        })),
        payments: type === 'sale' ? payments.value.filter((p) => toNum(p.amount) > 0) : [],
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (p) => {
            if (!p.props.flash?.error) {
                lastSale.value = p.props.lastSale;
                if (lastSale.value) ticketFor.value = lastSale.value; // abre el pop-up del comprobante
                reset();
            }
        },
        onFinish: () => (processing.value = false),
    });
}
function reset() {
    items.value = [];
    customer.value = null;
    globalDiscount.value = 0;
    notes.value = '';
    payments.value = [{ method: 'cash', amount: null }];
    priceListId.value = props.priceLists.find((l) => l.is_default)?.id ?? null;
    setTimeout(() => search.value?.focus(), 50);
}

const onKey = (e) => {
    if (e.key === 'F9') {
        e.preventDefault();
        submit('sale');
    } else if (e.key === 'F3') {
        e.preventDefault();
        search.value?.focus();
    }
};
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

const openTicket = () => (ticketFor.value = lastSale.value);
</script>

<template>
    <Head title="Punto de venta" />
    <div class="-m-4 grid min-h-[calc(100vh-3rem)] lg:-m-6 lg:grid-cols-[1fr_380px]">
        <!-- Carrito -->
        <section class="flex min-w-0 flex-col p-4 lg:p-5">
            <div v-if="!session" class="mb-3 flex items-center gap-3 rounded-md border border-hazard-500/60 bg-hazard-300/25 px-3 py-2 text-sm">
                <Wallet class="size-4" /> No tenés una caja abierta.
                <Link :href="route('cash.index')" class="link font-medium">Abrir caja</Link>
            </div>
            <div v-if="lastSale" class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-down/30 bg-down-bg px-3 py-2 text-sm">
                <span class="font-medium text-down">{{ lastSale.type === 'quote' ? 'Presupuesto' : 'Venta' }} #{{ lastSale.number }} registrada · {{ money(lastSale.total) }}</span>
                <span v-if="lastSale.change > 0" class="font-semibold">Vuelto: {{ money(lastSale.change) }}</span>
                <button class="btn-secondary btn-sm ml-auto" @click="openTicket"><Printer class="size-3.5" /> Ver comprobante</button>
                <button class="text-steel-500" @click="lastSale = null" aria-label="Ocultar"><X class="size-4" /></button>
            </div>

            <div class="flex gap-2">
                <div class="flex-1"><ProductSearch ref="search" :price-list-id="priceListId" placeholder="Escaneá o buscá un producto (F3)" @select="addProduct" /></div>
            </div>

            <div class="panel mt-3 flex-1 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr><th>Producto</th><th class="w-28 text-right">Cantidad</th><th class="w-32 text-right">Precio</th><th class="w-20 text-right">Desc. %</th><th class="w-32 text-right">Subtotal</th><th class="w-8"></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(i, idx) in items" :key="i.product_id" :class="i._flash && 'bg-hazard-300/30'" class="transition-colors">
                                <td>
                                    <div class="font-medium">{{ i.name }}</div>
                                    <div class="code">{{ i.code }} · <span :class="i.track_stock && toNum(i.stock) < toNum(i.quantity) ? 'text-up' : ''">stock {{ fmtQty(i.stock) }} {{ i.unit }}</span></div>
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        <input v-model="i.quantity" type="number" :step="i.allow_fraction ? '0.001' : '1'" min="0" class="input w-20 text-right" :aria-label="'Cantidad de ' + i.name" />
                                        <span class="w-6 text-xs text-steel-500">{{ i.unit }}</span>
                                    </div>
                                </td>
                                <td class="num">
                                    <input v-if="canOverride" v-model="i.unit_price" type="number" step="0.01" min="0" class="input w-28 text-right" :aria-label="'Precio de ' + i.name" />
                                    <span v-else>{{ money(i.unit_price) }}</span>
                                </td>
                                <td class="num">
                                    <input v-if="maxDiscount > 0" v-model="i.discount_percent" type="number" step="0.5" min="0" :max="maxDiscount" class="input w-16 text-right" :class="toNum(i.discount_percent) > maxDiscount && 'border-up'" :aria-label="'Descuento de ' + i.name" />
                                </td>
                                <td class="num font-semibold">{{ money(lineTotal(i)) }}</td>
                                <td><button class="text-steel-400 hover:text-up" @click="remove(idx)" :aria-label="'Quitar ' + i.name"><Trash2 class="size-4" /></button></td>
                            </tr>
                            <tr v-if="!items.length">
                                <td colspan="6" class="py-16 text-center text-steel-500">
                                    Escaneá el código de barras o escribí el código / descripción para agregar productos.
                                    <div class="mt-2 text-xs">F3 buscar · F9 cobrar · F2 desde cualquier pantalla abre el punto de venta</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Cobro -->
        <aside class="flex flex-col border-l border-steel-200 bg-white">
            <div class="space-y-3 border-b border-steel-200 p-4">
                <div class="relative">
                    <label class="label">Cliente</label>
                    <div v-if="customer" class="flex items-start justify-between rounded-md border border-steel-300 bg-steel-50 px-2.5 py-1.5 text-sm">
                        <div>
                            <div class="font-medium">{{ customer.name }}</div>
                            <div class="text-xs text-steel-500">
                                {{ customer.document_number || customer.tax_condition }}
                                <span v-if="customer.allow_credit"> · saldo {{ money(customer.balance) }}</span>
                            </div>
                        </div>
                        <button class="text-steel-400 hover:text-steel-800" @click="customer = null" aria-label="Quitar cliente"><X class="size-4" /></button>
                    </div>
                    <div v-else class="flex gap-1.5">
                        <input v-model="customerQuery" class="input" placeholder="Consumidor final · buscar cliente" />
                        <button class="btn-secondary px-2" title="Nuevo cliente" @click="showNewCustomer = true"><UserPlus class="size-4" /></button>
                    </div>
                    <div v-if="customerResults.length" class="absolute z-20 mt-1 w-full rounded-md border border-steel-200 bg-white shadow-lg">
                        <button v-for="c in customerResults" :key="c.id" class="block w-full border-b border-steel-100 px-3 py-2 text-left text-sm hover:bg-steel-50" @click="setCustomer(c)">
                            {{ c.name }} <span class="text-xs text-steel-500">{{ c.document_number }}</span>
                        </button>
                    </div>
                </div>
                <div>
                    <div class="label">Comprobante</div>
                    <div class="grid grid-cols-2 gap-1 rounded-md bg-steel-100 p-1 text-sm">
                        <button type="button" class="rounded py-1.5" :class="voucherType === 'ticket' ? 'bg-white font-medium shadow-sm' : 'text-steel-600'" @click="voucherType = 'ticket'">Ticket</button>
                        <button type="button" class="cursor-not-allowed rounded py-1.5 text-steel-400" disabled title="Próximamente: factura electrónica A/B/C con CAE de ARCA">
                            Factura ARCA <span class="text-[10px]">(próx.)</span>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <Field label="Lista de precios">
                        <select v-model="priceListId" class="input"><option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.name }}</option></select>
                    </Field>
                    <Field label="Descuento general %" v-if="maxDiscount > 0">
                        <input v-model="globalDiscount" type="number" min="0" :max="maxDiscount" step="0.5" class="input" />
                    </Field>
                </div>
            </div>

            <div class="space-y-1 border-b border-steel-200 p-4 text-sm">
                <div class="flex justify-between"><span class="text-steel-500">Subtotal</span><span class="tabular-nums">{{ money(subtotal) }}</span></div>
                <div v-if="discountAmount" class="flex justify-between"><span class="text-steel-500">Descuento</span><span class="tabular-nums">− {{ money(discountAmount) }}</span></div>
                <div class="flex items-baseline justify-between pt-1">
                    <span class="font-medium">Total</span>
                    <span class="text-3xl font-semibold tracking-tight tabular-nums">{{ money(total) }}</span>
                </div>
                <div class="text-right text-xs text-steel-500">{{ items.length }} ítems</div>
            </div>

            <div class="flex-1 space-y-2 p-4">
                <div class="flex items-center justify-between"><span class="text-sm font-medium">Forma de pago</span><button class="btn-ghost btn-sm" @click="addPayment"><Plus class="size-3.5" /> Otro medio</button></div>
                <div v-for="(p, idx) in payments" :key="idx" class="flex gap-1.5">
                    <select v-model="p.method" class="input">
                        <option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option>
                    </select>
                    <input v-model="p.amount" type="number" step="0.01" min="0" class="input w-32 text-right" :aria-label="'Importe ' + methods[p.method]" />
                    <button v-if="payments.length > 1" class="text-steel-400 hover:text-up" @click="payments.splice(idx, 1)" aria-label="Quitar pago"><X class="size-4" /></button>
                </div>
                <div v-if="change > 0" class="flex justify-between rounded-md bg-down-bg px-3 py-2 text-sm font-semibold text-down"><span>Vuelto</span><span class="tabular-nums">{{ money(change) }}</span></div>
                <div v-if="missing > 0 && items.length" class="flex justify-between rounded-md bg-up-bg px-3 py-2 text-sm text-up"><span>Falta</span><span class="tabular-nums">{{ money(missing) }}</span></div>
                <Field label="Observaciones"><input v-model="notes" class="input" /></Field>
            </div>

            <div class="space-y-2 border-t border-steel-200 p-4">
                <p v-if="items.length && errors.length" class="text-xs text-up">{{ errors[0] }}</p>
                <button class="btn-primary w-full py-3 text-base" :disabled="processing || errors.length > 0" @click="submit('sale')">
                    Cobrar {{ money(total) }} <kbd class="ml-1 rounded border border-steel-900/20 px-1 text-[10px]">F9</kbd>
                </button>
                <div class="flex gap-2">
                    <button class="btn-secondary flex-1" :disabled="processing || !items.length" @click="submit('quote')"><FileText class="size-4" /> Guardar presupuesto</button>
                    <button class="btn-ghost" :disabled="!items.length" @click="reset">Vaciar</button>
                </div>
            </div>
        </aside>
    </div>

    <Modal :show="showNewCustomer" title="Nuevo cliente" @close="showNewCustomer = false">
        <div class="grid grid-cols-2 gap-3">
            <Field class="col-span-2" label="Nombre o razón social" :error="newCustomerErrors.name?.[0]"><input v-model="newCustomer.name" class="input" autofocus /></Field>
            <Field label="Tipo doc."><select v-model="newCustomer.document_type" class="input"><option>DNI</option><option>CUIT</option><option>CUIL</option></select></Field>
            <Field label="Número" :error="newCustomerErrors.document_number?.[0]"><input v-model="newCustomer.document_number" class="input" /></Field>
            <Field label="Condición IVA"><select v-model="newCustomer.tax_condition" class="input"><option>Consumidor Final</option><option>Responsable Inscripto</option><option>Monotributo</option><option>Exento</option></select></Field>
            <Field label="Teléfono"><input v-model="newCustomer.phone" class="input" /></Field>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showNewCustomer = false">Cancelar</button>
            <button class="btn-primary" @click="createCustomer">Crear y usar</button>
        </template>
    </Modal>

    <TicketModal
        :sale-id="ticketFor?.id ?? null" :change="ticketFor?.change ?? 0"
        :title="ticketFor ? `${ticketFor.type === 'quote' ? 'Presupuesto' : 'Venta'} #${ticketFor.number} registrada` : ''"
        @close="ticketFor = null; search?.focus()"
    />
</template>
