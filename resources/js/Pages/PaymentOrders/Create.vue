<script setup>
import { computed, reactive, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Field from '@/Components/Field.vue';
import { money, date, round2, toNum, today } from '@/lib/format';

const props = defineProps({ suppliers: Array, supplier: Object, pending: Array, methods: Object });

const form = useForm({
    supplier_id: props.supplier?.id ?? '',
    date: today(),
    notes: '',
    from_cash: false,
    lines: [{ method: 'transfer', amount: '', reference: '', bank: '', check_date: '' }],
    applications: {},
});
const pendingOf = (p) => round2(toNum(p.total) - toNum(p.paid_amount));
const apply = reactive(Object.fromEntries(props.pending.map((p) => [p.id, ''])));

const total = computed(() => round2(form.lines.reduce((a, l) => a + toNum(l.amount), 0)));
const applied = computed(() => round2(Object.values(apply).reduce((a, v) => a + toNum(v), 0)));
const totalPending = computed(() => round2(props.pending.reduce((a, p) => a + pendingOf(p), 0)));

// Imputación automática: reparte el total de la orden desde la factura más vieja
function autoApply() {
    let left = total.value;
    for (const p of props.pending) {
        const v = Math.min(left, pendingOf(p));
        apply[p.id] = v > 0 ? round2(v) : '';
        left = round2(left - Math.max(v, 0));
    }
}
watch(total, autoApply);
const payAll = () => {
    form.lines = [{ method: 'transfer', amount: totalPending.value, reference: '', bank: '', check_date: '' }];
};

const changeSupplier = (id) => router.get(route('payment-orders.create'), { supplier_id: id || undefined });
const submit = () =>
    form.transform((d) => ({
        ...d,
        lines: d.lines.filter((l) => toNum(l.amount) > 0).map((l) => ({ ...l, check_date: l.check_date || null })),
        applications: Object.fromEntries(Object.entries(apply).filter(([, v]) => toNum(v) > 0)),
    })).post(route('payment-orders.store'));
</script>

<template>
    <PageHeader title="Nueva orden de pago" :back="route('payment-orders.index')">
        <button class="btn-primary" :disabled="form.processing || !form.supplier_id || total <= 0 || applied > total + 0.01" @click="submit">Registrar pago de {{ money(total) }}</button>
    </PageHeader>

    <div class="panel mb-4 grid gap-3 p-4 sm:grid-cols-3">
        <Field label="Proveedor" :error="form.errors.supplier_id">
            <select class="input" v-sel="form.supplier_id" @change="changeSupplier($event.target.value)">
                <option value="">Elegí…</option>
                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} · saldo {{ money(s.balance) }}</option>
            </select>
        </Field>
        <Field label="Fecha"><input v-model="form.date" type="date" class="input" /></Field>
        <Field label="Observaciones"><input v-model="form.notes" class="input" /></Field>
    </div>

    <div v-if="supplier" class="grid gap-4 xl:grid-cols-2">
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Medios de pago</h2>
                <button class="btn-ghost btn-sm" @click="form.lines.push({ method: 'check', amount: '', reference: '', bank: '', check_date: '' })"><Plus class="size-3.5" /> Otro medio</button>
            </div>
            <div class="space-y-3 p-4">
                <div v-for="(l, i) in form.lines" :key="i" class="rounded-md border border-steel-200 p-3">
                    <div class="grid grid-cols-[1fr_140px_auto] items-end gap-2">
                        <Field label="Medio"><select v-model="l.method" class="input"><option v-for="(lbl, k) in methods" :key="k" :value="k">{{ lbl }}</option></select></Field>
                        <Field label="Importe"><input v-model="l.amount" type="number" step="0.01" min="0" class="input text-right" /></Field>
                        <button v-if="form.lines.length > 1" class="pb-2 text-steel-400 hover:text-up" @click="form.lines.splice(i, 1)" aria-label="Quitar"><X class="size-4" /></button>
                    </div>
                    <div v-if="l.method !== 'cash'" class="mt-2 grid grid-cols-3 gap-2">
                        <Field :label="l.method === 'check' ? 'N° de cheque' : 'N° de operación'"><input v-model="l.reference" class="input" /></Field>
                        <Field v-if="['check', 'transfer'].includes(l.method)" label="Banco"><input v-model="l.bank" class="input" /></Field>
                        <Field v-if="l.method === 'check'" label="Fecha de cobro"><input v-model="l.check_date" type="date" class="input" /></Field>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.from_cash" type="checkbox" class="checkbox" /> El dinero sale de mi caja abierta</label>
                <div class="flex justify-between border-t border-steel-200 pt-2 text-base font-semibold"><span>Total a pagar</span><span class="tabular-nums">{{ money(total) }}</span></div>
                <p v-if="Object.keys(form.errors).length" class="text-xs text-up">{{ Object.values(form.errors)[0] }}</p>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div><h2 class="panel-title">Facturas a cancelar</h2><p class="text-xs text-steel-500">Pendiente total {{ money(totalPending) }}</p></div>
                <div class="flex gap-2"><button class="btn-ghost btn-sm" @click="payAll">Pagar todo</button><button class="btn-secondary btn-sm" @click="autoApply">Imputar automático</button></div>
            </div>
            <table class="table">
                <thead><tr><th>Comprobante</th><th>Vence</th><th class="num">Saldo</th><th class="num w-36">Imputar</th></tr></thead>
                <tbody>
                    <tr v-for="p in pending" :key="p.id">
                        <td>{{ p.voucher_type }} {{ p.voucher_number || 's/n' }}<div class="code">{{ date(p.date) }} · total {{ money(p.total) }}</div></td>
                        <td :class="p.due_date && new Date(p.due_date) < new Date() ? 'text-up' : ''">{{ date(p.due_date) }}</td>
                        <td class="num">{{ money(pendingOf(p)) }}</td>
                        <td class="num"><input v-model="apply[p.id]" type="number" step="0.01" min="0" :max="pendingOf(p)" class="input ml-auto w-32 text-right" /></td>
                    </tr>
                    <tr v-if="!pending.length"><td colspan="4" class="py-6 text-center text-steel-500">El proveedor no tiene facturas pendientes. El pago queda como saldo a favor.</td></tr>
                </tbody>
                <tfoot><tr><td colspan="3" class="px-3 py-2 text-right text-sm">Imputado</td><td class="num px-3 font-semibold" :class="applied > total + 0.01 && 'text-up'">{{ money(applied) }}</td></tr></tfoot>
            </table>
            <p v-if="total - applied > 0.01" class="px-4 pb-3 text-xs text-steel-500">{{ money(total - applied) }} quedan como pago a cuenta (saldo a favor).</p>
        </div>
    </div>
    <div v-else class="panel p-8 text-center text-sm text-steel-500">Elegí un proveedor para ver sus facturas pendientes.</div>
</template>
