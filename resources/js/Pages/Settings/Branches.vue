<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';

defineProps({ branches: Array });
const editing = ref(null);
const show = ref(false);
const form = useForm({ name: '', code: '', type: 'store', address: '', phone: '', is_main: false, is_active: true });
const open = (b = null) => {
    editing.value = b;
    Object.assign(form, { name: b?.name ?? '', code: b?.code ?? '', type: b?.type ?? 'store', address: b?.address ?? '', phone: b?.phone ?? '', is_main: !!b?.is_main, is_active: b?.is_active ?? true });
    show.value = true;
};
const save = () => {
    const o = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('branches.update', editing.value.id), o) : form.post(route('branches.store'), o);
};
const addRegister = (b) => {
    const name = prompt('Nombre de la caja', `Caja ${b.cash_registers.length + 1}`);
    if (name) router.post(route('branches.registers.store', b.id), { name }, { preserveScroll: true });
};
const toggleRegister = (r) => router.put(route('registers.update', r.id), { name: r.name, is_active: !r.is_active }, { preserveScroll: true });
</script>

<template>
    <PageHeader title="Sucursales y depósitos" subtitle="Las sucursales venden y tienen cajas. Los depósitos solo guardan stock: reciben compras y transferencias, y no pueden vender.">
        <button class="btn-primary" @click="open()"><Plus class="size-4" /> Nueva sucursal o depósito</button>
    </PageHeader>
    <div class="grid gap-4 lg:grid-cols-2">
        <div v-for="b in branches" :key="b.id" class="panel" :class="!b.is_active && 'opacity-60'">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">{{ b.name }} <span v-if="b.type === 'warehouse'" class="badge-blue ml-1">Depósito</span> <span v-if="b.is_main" class="badge-down ml-1">Casa central</span><span v-if="!b.is_active" class="badge-gray ml-1">Inactiva</span></h2>
                    <div class="text-xs text-steel-500">{{ b.code }} · {{ b.address || 'sin dirección' }} · {{ b.users_count }} usuarios asignados</div>
                </div>
                <button class="btn-secondary btn-sm" @click="open(b)">Editar</button>
            </div>
            <div v-if="b.type !== 'warehouse'" class="p-4">
                <div class="mb-2 flex items-center justify-between text-sm font-medium">Cajas <button class="btn-ghost btn-sm" @click="addRegister(b)"><Plus class="size-3.5" /> Caja</button></div>
                <div v-for="r in b.cash_registers" :key="r.id" class="flex items-center justify-between border-t border-steel-100 py-1.5 text-sm">
                    <span :class="!r.is_active && 'text-steel-400 line-through'">{{ r.name }}</span>
                    <button class="text-xs link" @click="toggleRegister(r)">{{ r.is_active ? 'Desactivar' : 'Activar' }}</button>
                </div>
            </div>
        </div>
    </div>
    <Modal :show="show" :title="editing ? `Editar ${editing.name}` : 'Nueva sucursal o depósito'" @close="show = false">
        <div class="grid grid-cols-2 gap-3">
            <Field class="col-span-2" label="Tipo">
                <select v-model="form.type" class="input"><option value="store">Sucursal (vende, tiene cajas)</option><option value="warehouse">Depósito (solo stock)</option></select>
            </Field>
            <Field label="Nombre" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
            <Field label="Código / punto de venta"><input v-model="form.code" class="input" /></Field>
            <Field label="Dirección"><input v-model="form.address" class="input" /></Field>
            <Field label="Teléfono"><input v-model="form.phone" class="input" /></Field>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_main" type="checkbox" class="checkbox" /> Casa central</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="checkbox" /> Activa</label>
        </div>
        <template #footer><button class="btn-secondary" @click="show = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="save">Guardar</button></template>
    </Modal>
</template>
