<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import { money } from '@/lib/format';

defineProps({ plans: Array });
const editing = ref(null);
const show = ref(false);
const form = useForm({ name: '', slug: '', price: 0, max_branches: 1, max_users: 3, max_products: '', is_active: true });
const open = (p = null) => {
    editing.value = p;
    Object.assign(form, { name: p?.name ?? '', slug: p?.slug ?? '', price: p?.price ?? 0, max_branches: p?.max_branches ?? 1, max_users: p?.max_users ?? 3, max_products: p?.max_products ?? '', is_active: p?.is_active ?? true });
    show.value = true;
};
const save = () => {
    form.transform((d) => ({ ...d, max_products: d.max_products || null }));
    const o = { onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('admin.plans.update', editing.value.id), o) : form.post(route('admin.plans.store'), o);
};
</script>

<template>
    <PageHeader title="Planes"><button class="btn-primary" @click="open()"><Plus class="size-4" /> Nuevo plan</button></PageHeader>
    <div class="grid gap-4 md:grid-cols-3">
        <div v-for="p in plans" :key="p.id" class="panel p-5" :class="!p.is_active && 'opacity-60'">
            <div class="flex items-start justify-between"><h2 class="text-lg font-semibold">{{ p.name }}</h2><button class="text-xs link" @click="open(p)">Editar</button></div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ money(p.price) }}<span class="text-sm font-normal text-steel-500"> /mes</span></div>
            <ul class="mt-3 space-y-1 text-sm text-steel-600">
                <li>Hasta {{ p.max_branches }} sucursales</li>
                <li>Hasta {{ p.max_users }} usuarios</li>
                <li>{{ p.max_products ? `Hasta ${p.max_products} productos` : 'Productos ilimitados' }}</li>
            </ul>
            <div class="mt-3 text-xs text-steel-500">{{ p.tenants_count }} empresas</div>
        </div>
    </div>
    <Modal :show="show" :title="editing ? 'Editar plan' : 'Nuevo plan'" @close="show = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Nombre" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
            <Field label="Identificador" :error="form.errors.slug"><input v-model="form.slug" class="input" /></Field>
            <Field label="Precio mensual"><input v-model="form.price" type="number" step="0.01" class="input" /></Field>
            <Field label="Máx. sucursales"><input v-model="form.max_branches" type="number" min="1" class="input" /></Field>
            <Field label="Máx. usuarios"><input v-model="form.max_users" type="number" min="1" class="input" /></Field>
            <Field label="Máx. productos (vacío = ilimitado)"><input v-model="form.max_products" type="number" min="1" class="input" /></Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="checkbox" /> Disponible</label>
        </div>
        <template #footer><button class="btn-secondary" @click="show = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="save">Guardar</button></template>
    </Modal>
</template>
