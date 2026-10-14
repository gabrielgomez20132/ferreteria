<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, HandCoins, Trash2 } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import CustomerModal from '@/Components/CustomerModal.vue';
import { money, date, datetime } from '@/lib/format';
import { useCan } from '@/lib/can';

const props = defineProps({ customer: Object, movements: Object, sales: Array, overdue: Number, methods: Object, priceLists: Array });
const can = useCan();
const showEdit = ref(false);
const showPay = ref(false);
const showAdjust = ref(false);
const pay = useForm({ amount: '', method: 'cash', notes: '' });
const adjust = useForm({ amount: '', description: '' });

const doPay = () => pay.post(route('customers.payment', props.customer.id), { preserveScroll: true, onSuccess: () => { showPay.value = false; pay.reset(); } });
const doAdjust = () => adjust.post(route('customers.adjust', props.customer.id), { preserveScroll: true, onSuccess: () => { showAdjust.value = false; adjust.reset(); } });
const destroy = () => confirm('¿Eliminar este cliente?') && router.delete(route('customers.destroy', props.customer.id));
const TYPES = { sale: 'Venta', payment: 'Pago', return: 'Devolución', adjustment: 'Ajuste', cancel: 'Anulación' };
</script>

<template>
    <PageHeader :title="customer.name" :back="route('customers.index')">
        <template #subtitle>
            <p class="mt-0.5 text-sm text-steel-500">
                {{ customer.document_type }} {{ customer.document_number || '—' }} · {{ customer.tax_condition }}<span v-if="customer.phone"> · {{ customer.phone }}</span><span v-if="customer.price_list"> · Lista {{ customer.price_list.name }}</span>
            </p>
        </template>
        <template v-if="can('customers.manage')">
            <button class="btn-secondary" @click="showEdit = true"><Pencil class="size-4" /> Editar</button>
            <button class="btn-secondary" @click="showAdjust = true">Ajuste</button>
            <button class="btn-primary" @click="showPay = true"><HandCoins class="size-4" /> Registrar pago</button>
            <button class="btn-ghost text-up" @click="destroy" aria-label="Eliminar"><Trash2 class="size-4" /></button>
        </template>
    </PageHeader>

    <div class="panel mb-4 grid grid-cols-2 divide-x divide-steel-200 md:grid-cols-4">
        <div class="p-4"><div class="text-sm text-steel-500">Saldo</div><div class="text-2xl font-semibold tabular-nums" :class="Number(customer.balance) > 0 && 'text-up'">{{ money(customer.balance) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Vencido</div><div class="text-2xl font-semibold tabular-nums" :class="overdue > 0 && 'text-up'">{{ money(overdue) }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Límite de crédito</div><div class="text-2xl font-semibold tabular-nums">{{ customer.allow_credit ? (Number(customer.credit_limit) ? money(customer.credit_limit) : 'Sin límite') : 'Sin cuenta' }}</div></div>
        <div class="p-4"><div class="text-sm text-steel-500">Disponible</div><div class="text-2xl font-semibold tabular-nums">{{ customer.allow_credit && Number(customer.credit_limit) ? money(Number(customer.credit_limit) - Number(customer.balance)) : '—' }}</div></div>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1fr_340px]">
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Cuenta corriente</h2></div>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Vence</th><th class="num">Debe</th><th class="num">Haber</th><th class="num">Saldo</th></tr></thead>
                    <tbody>
                        <tr v-for="m in movements.data" :key="m.id">
                            <td class="whitespace-nowrap">{{ datetime(m.created_at) }}</td>
                            <td><span class="badge-gray mr-1">{{ TYPES[m.type] ?? m.type }}</span>{{ m.description }}</td>
                            <td class="whitespace-nowrap" :class="Number(m.pending_amount) > 0 && m.due_date && new Date(m.due_date) < new Date() ? 'text-up' : ''">
                                {{ Number(m.debit) ? date(m.due_date) : '' }}<div v-if="Number(m.pending_amount) > 0" class="text-xs">pend. {{ money(m.pending_amount) }}</div>
                            </td>
                            <td class="num">{{ Number(m.debit) ? money(m.debit) : '' }}</td>
                            <td class="num">{{ Number(m.credit) ? money(m.credit) : '' }}</td>
                            <td class="num font-medium">{{ money(m.balance_after) }}</td>
                        </tr>
                        <tr v-if="!movements.data.length"><td colspan="6" class="py-6 text-center text-steel-500">Sin movimientos.</td></tr>
                    </tbody>
                </table>
            </div>
            <Pagination :data="movements" />
        </div>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Últimas compras</h2></div>
            <div class="divide-y divide-steel-100">
                <Link v-for="s in sales" :key="s.id" :href="route('sales.show', s.id)" class="flex items-center justify-between px-4 py-2 text-sm hover:bg-steel-50">
                    <span>#{{ s.number }} · {{ date(s.created_at) }}</span>
                    <span class="flex items-center gap-2"><StatusBadge v-if="s.status !== 'completed'" :status="s.status" /><span class="tabular-nums">{{ money(s.total) }}</span></span>
                </Link>
                <div v-if="!sales.length" class="px-4 py-3 text-sm text-steel-500">Sin compras registradas.</div>
            </div>
        </div>
    </div>

    <CustomerModal :show="showEdit" :customer="customer" :price-lists="priceLists" @close="showEdit = false" />

    <Modal :show="showPay" title="Registrar pago" @close="showPay = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Importe" :error="pay.errors.amount"><input v-model="pay.amount" type="number" step="0.01" min="0" class="input" autofocus /></Field>
            <Field label="Medio"><select v-model="pay.method" class="input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field class="col-span-2" label="Observaciones"><input v-model="pay.notes" class="input" /></Field>
        </div>
        <button class="mt-2 text-xs link" @click="pay.amount = customer.balance">Cancelar el saldo total ({{ money(customer.balance) }})</button>
        <template #footer>
            <button class="btn-secondary" @click="showPay = false">Cancelar</button>
            <button class="btn-primary" :disabled="pay.processing || !pay.amount" @click="doPay">Registrar pago</button>
        </template>
    </Modal>

    <Modal :show="showAdjust" title="Ajuste de cuenta corriente" @close="showAdjust = false">
        <div class="grid gap-3">
            <Field label="Importe" hint="Positivo aumenta la deuda; negativo la reduce" :error="adjust.errors.amount"><input v-model="adjust.amount" type="number" step="0.01" class="input" /></Field>
            <Field label="Motivo" :error="adjust.errors.description"><input v-model="adjust.description" class="input" /></Field>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showAdjust = false">Cancelar</button>
            <button class="btn-primary" :disabled="adjust.processing" @click="doAdjust">Guardar ajuste</button>
        </template>
    </Modal>
</template>
