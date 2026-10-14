<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';

const props = defineProps({ show: Boolean, customer: Object, priceLists: Array });
const emit = defineEmits(['close']);

const blank = { name: '', document_type: 'DNI', document_number: '', tax_condition: 'Consumidor Final', email: '', phone: '', address: '', city: '', price_list_id: '', allow_credit: false, credit_limit: 0, notes: '', is_active: true };
const form = useForm({ ...blank });

watch(() => props.show, (v) => {
    if (!v) return;
    form.clearErrors();
    Object.assign(form, blank, props.customer ? Object.fromEntries(Object.keys(blank).map((k) => [k, props.customer[k] ?? blank[k]])) : {});
});

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => emit('close') };
    form.transform((d) => ({ ...d, price_list_id: d.price_list_id || null }));
    props.customer ? form.put(route('customers.update', props.customer.id), opts) : form.post(route('customers.store'), opts);
};
</script>

<template>
    <Modal :show="show" :title="customer ? 'Editar cliente' : 'Nuevo cliente'" width="max-w-2xl" @close="emit('close')">
        <div class="grid gap-3 sm:grid-cols-2">
            <Field class="sm:col-span-2" label="Nombre o razón social" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
            <div class="grid grid-cols-[90px_1fr] gap-2">
                <Field label="Tipo"><select v-model="form.document_type" class="input"><option>DNI</option><option>CUIT</option><option>CUIL</option><option>PAS</option></select></Field>
                <Field label="Número" :error="form.errors.document_number"><input v-model="form.document_number" class="input" /></Field>
            </div>
            <Field label="Condición frente al IVA"><select v-model="form.tax_condition" class="input"><option>Consumidor Final</option><option>Responsable Inscripto</option><option>Monotributo</option><option>Exento</option></select></Field>
            <Field label="Teléfono"><input v-model="form.phone" class="input" /></Field>
            <Field label="Email" :error="form.errors.email"><input v-model="form.email" type="email" class="input" /></Field>
            <Field label="Dirección"><input v-model="form.address" class="input" /></Field>
            <Field label="Localidad"><input v-model="form.city" class="input" /></Field>
            <Field label="Lista de precios"><select v-model="form.price_list_id" class="input"><option value="">La predeterminada</option><option v-for="l in priceLists" :key="l.id" :value="l.id">{{ l.name }}</option></select></Field>
            <div class="flex items-end gap-3">
                <label class="flex items-center gap-2 pb-2 text-sm"><input v-model="form.allow_credit" type="checkbox" class="checkbox" /> Cuenta corriente</label>
                <Field v-if="form.allow_credit" class="flex-1" label="Límite de crédito" hint="0 = sin límite"><input v-model="form.credit_limit" type="number" min="0" step="0.01" class="input" /></Field>
            </div>
            <Field class="sm:col-span-2" label="Notas"><textarea v-model="form.notes" rows="2" class="input" /></Field>
            <label v-if="customer" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="checkbox" /> Activo</label>
        </div>
        <template #footer>
            <button class="btn-secondary" @click="emit('close')">Cancelar</button>
            <button class="btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
        </template>
    </Modal>
</template>
