<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({ show: Boolean, supplier: Object });
const emit = defineEmits(['close']);
const blank = {
    name: '', legal_name: '', cuit: '', tax_condition: 'Responsable Inscripto', contact_name: '', phone: '', email: '', address: '',
    payment_term_days: 0, discount_1: 0, discount_2: 0, discount_3: 0, prices_include_vat: false, default_markup: '', notes: '', is_active: true,
    recalculate_costs: true,
};
const form = useForm({ ...blank });
watch(() => props.show, (v) => {
    if (!v) return;
    form.clearErrors();
    Object.assign(form, blank, props.supplier ? Object.fromEntries(Object.keys(blank).map((k) => [k, props.supplier[k] ?? blank[k]])) : {});
});
const discountsChanged = computed(() => props.supplier && ['discount_1', 'discount_2', 'discount_3', 'prices_include_vat'].some((k) => String(props.supplier[k]) !== String(form[k])));
const submit = () => {
    form.transform((d) => ({ ...d, default_markup: d.default_markup === '' ? null : d.default_markup }));
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    props.supplier ? form.put(route('suppliers.update', props.supplier.id), opts) : form.post(route('suppliers.store'), opts);
};
</script>

<template>
    <Modal :show="show" :title="supplier ? 'Editar proveedor' : 'Nuevo proveedor'" width="max-w-2xl" @close="emit('close')">
        <div class="grid gap-3 sm:grid-cols-2">
            <Field label="Nombre comercial" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
            <Field label="Razón social"><input v-model="form.legal_name" class="input" /></Field>
            <Field label="CUIT"><input v-model="form.cuit" class="input" /></Field>
            <Field label="Condición IVA"><select v-model="form.tax_condition" class="input"><option>Responsable Inscripto</option><option>Monotributo</option><option>Exento</option></select></Field>
            <Field label="Contacto"><input v-model="form.contact_name" class="input" /></Field>
            <Field label="Teléfono"><input v-model="form.phone" class="input" /></Field>
            <Field label="Email" :error="form.errors.email"><input v-model="form.email" type="email" class="input" /></Field>
            <Field label="Plazo de pago (días)"><input v-model="form.payment_term_days" type="number" min="0" class="input" /></Field>
        </div>
        <div class="mt-4 rounded-md border border-steel-200 bg-steel-50 p-3">
            <div class="mb-2 text-sm font-medium">Condiciones de la lista de precios</div>
            <div class="grid grid-cols-3 gap-2">
                <Field label="Bonificación 1 %"><input v-model="form.discount_1" type="number" step="0.01" class="input" /></Field>
                <Field label="Bonificación 2 %"><input v-model="form.discount_2" type="number" step="0.01" class="input" /></Field>
                <Field label="Bonificación 3 %"><input v-model="form.discount_3" type="number" step="0.01" class="input" /></Field>
            </div>
            <div class="mt-2 flex flex-wrap items-end gap-4">
                <label class="flex items-center gap-2 text-sm"><input v-model="form.prices_include_vat" type="checkbox" class="checkbox" /> Sus listas traen precios con IVA</label>
                <Field label="Margen sugerido para sus productos %"><input v-model="form.default_markup" type="number" step="0.01" class="input w-28" /></Field>
            </div>
            <label v-if="discountsChanged" class="mt-2 flex items-center gap-2 text-sm text-[#7a5b00]"><input v-model="form.recalculate_costs" type="checkbox" class="checkbox" /> Recalcular el costo de todos sus artículos con las nuevas condiciones</label>
        </div>
        <Field class="mt-3" label="Notas"><textarea v-model="form.notes" rows="2" class="input" /></Field>
        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancelar</button>
            <button class="btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
        </template>
    </Modal>
</template>
