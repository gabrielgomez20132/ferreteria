<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { History } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import CashSummary from '@/Components/CashSummary.vue';
import { money, datetime } from '@/lib/format';

const props = defineProps({ registers: Array, openSessions: Array, current: Object, methods: Object, types: Object });

const openForm = useForm({ cash_register_id: props.registers.find((r) => !r.open)?.id ?? '', opening_amount: 0, notes: '' });
const submitOpen = () => openForm.post(route('cash.open'), { preserveScroll: true });

const showMove = ref(false);
const move = useForm({ type: 'expense', payment_method: 'cash', amount: '', description: '' });
const submitMove = () => move.post(route('cash.movement', props.current.session.id), { preserveScroll: true, onSuccess: () => { showMove.value = false; move.reset(); } });

const showClose = ref(false);
const closeForm = useForm({ counted_cash: '', notes: '' });
const submitClose = () => closeForm.post(route('cash.close', props.current.session.id));
const methodsNoAccount = Object.fromEntries(Object.entries(props.methods).filter(([k]) => k !== 'account'));
</script>

<template>
    <PageHeader title="Caja" :subtitle="current ? `${current.session.cash_register.name} abierta` : 'No tenés una caja abierta en esta sucursal'">
        <Link :href="route('cash.history')" class="btn-secondary"><History class="size-4" /> Historial</Link>
        <template v-if="current">
            <button class="btn-secondary" @click="showMove = true">Ingreso / egreso</button>
            <button class="btn-dark" @click="showClose = true">Cerrar caja</button>
        </template>
    </PageHeader>

    <div v-if="!current" class="grid gap-4 lg:grid-cols-2">
        <form class="panel space-y-3 p-4" @submit.prevent="submitOpen">
            <h2 class="panel-title">Abrir caja</h2>
            <Field label="Caja" :error="openForm.errors.cash_register_id">
                <select v-model="openForm.cash_register_id" class="input">
                    <option v-for="r in registers" :key="r.id" :value="r.id" :disabled="!!r.open">{{ r.name }}{{ r.open ? ' (abierta)' : '' }}</option>
                </select>
            </Field>
            <Field label="Efectivo inicial (cambio)"><input v-model="openForm.opening_amount" type="number" step="0.01" min="0" class="input" /></Field>
            <Field label="Observaciones"><input v-model="openForm.notes" class="input" /></Field>
            <button class="btn-primary" :disabled="openForm.processing || !openForm.cash_register_id">Abrir caja</button>
        </form>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Cajas abiertas en la sucursal</h2></div>
            <div class="divide-y divide-steel-100">
                <Link v-for="s in openSessions" :key="s.id" :href="route('cash.show', s.id)" class="block px-4 py-2.5 text-sm hover:bg-steel-50">
                    <span class="font-medium">{{ s.cash_register.name }}</span> · {{ s.user.name }} · desde {{ datetime(s.opened_at) }}
                </Link>
                <p v-if="!openSessions.length" class="px-4 py-3 text-sm text-steel-500">No hay cajas abiertas.</p>
            </div>
        </div>
    </div>

    <CashSummary v-else :session="current.session" :totals="current.totals" :expected="current.expected_cash" :movements="current.movements" :methods="methods" :types="types" :sales-count="current.sales_count" />

    <Modal :show="showMove" title="Movimiento de caja" @close="showMove = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Tipo"><select v-model="move.type" class="input"><option value="income">Ingreso</option><option value="expense">Egreso / gasto</option><option value="withdrawal">Retiro de efectivo</option></select></Field>
            <Field label="Medio"><select v-model="move.payment_method" class="input"><option v-for="(l, k) in methodsNoAccount" :key="k" :value="k">{{ l }}</option></select></Field>
            <Field label="Importe" :error="move.errors.amount"><input v-model="move.amount" type="number" step="0.01" min="0" class="input" /></Field>
            <Field label="Concepto" :error="move.errors.description"><input v-model="move.description" class="input" /></Field>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="showMove = false">Cancelar</button>
            <button class="btn-primary" :disabled="move.processing" @click="submitMove">Registrar</button>
        </template>
    </Modal>

    <Modal :show="showClose" title="Cierre de caja" @close="showClose = false">
        <p class="mb-3 text-sm text-steel-600">Contá el efectivo de la caja. El sistema espera <strong>{{ money(current?.expected_cash) }}</strong>.</p>
        <Field label="Efectivo contado" :error="closeForm.errors.counted_cash"><input v-model="closeForm.counted_cash" type="number" step="0.01" min="0" class="input text-lg" autofocus /></Field>
        <p v-if="closeForm.counted_cash !== ''" class="mt-2 text-sm" :class="Number(closeForm.counted_cash) - current.expected_cash < 0 ? 'text-up' : 'text-down'">
            Diferencia: {{ money(Number(closeForm.counted_cash) - current.expected_cash) }}
        </p>
        <Field class="mt-3" label="Observaciones"><input v-model="closeForm.notes" class="input" /></Field>
        <template #footer>
            <button class="btn-secondary" @click="showClose = false">Cancelar</button>
            <button class="btn-dark" :disabled="closeForm.processing || closeForm.counted_cash === ''" @click="submitClose">Cerrar caja</button>
        </template>
    </Modal>
</template>
