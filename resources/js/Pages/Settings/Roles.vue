<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';

defineProps({ roles: Array, groups: Object });
const editing = ref(null);
const show = ref(false);
const form = useForm({ name: '', max_discount: 0, permissions: [] });
const open = (r = null) => {
    editing.value = r;
    Object.assign(form, { name: r?.name ?? '', max_discount: r?.max_discount ?? 0, permissions: [...(r?.permissions ?? [])] });
    show.value = true;
};
const save = () => {
    const o = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('roles.update', editing.value.id), o) : form.post(route('roles.store'), o);
};
const destroy = (r) => confirm(`¿Eliminar el rol ${r.name}?`) && router.delete(route('roles.destroy', r.id), { preserveScroll: true });
const toggleGroup = (perms) => {
    const keys = Object.keys(perms);
    const all = keys.every((k) => form.permissions.includes(k));
    form.permissions = all ? form.permissions.filter((p) => !keys.includes(p)) : [...new Set([...form.permissions, ...keys])];
};
</script>

<template>
    <PageHeader title="Roles y permisos"><button class="btn-primary" @click="open()"><Plus class="size-4" /> Nuevo rol</button></PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Rol</th><th class="num">Descuento máximo</th><th class="num">Permisos</th><th class="num">Usuarios</th><th></th></tr></thead>
            <tbody>
                <tr v-for="r in roles" :key="r.id">
                    <td class="font-medium">{{ r.name }} <span v-if="r.is_system" class="badge-gray">Sistema</span></td>
                    <td class="num">{{ Number(r.max_discount) }}%</td>
                    <td class="num">{{ r.permissions?.includes('*') ? 'Todos' : r.permissions?.length }}</td>
                    <td class="num">{{ r.users_count }}</td>
                    <td class="text-right whitespace-nowrap"><button class="text-xs link" @click="open(r)">Editar</button><button v-if="!r.is_system" class="ml-3 text-xs text-up hover:underline" @click="destroy(r)">Eliminar</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Modal :show="show" :title="editing ? `Rol ${editing.name}` : 'Nuevo rol'" width="max-w-3xl" @close="show = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Nombre" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
            <Field label="Descuento máximo que puede otorgar %"><input v-model="form.max_discount" type="number" min="0" max="100" class="input" /></Field>
        </div>
        <p v-if="editing?.is_system" class="mt-3 text-sm text-steel-600">El rol Administrador tiene siempre todos los permisos.</p>
        <div v-else class="mt-4 grid gap-4 sm:grid-cols-2">
            <div v-for="(perms, group) in groups" :key="group">
                <button type="button" class="mb-1 text-sm font-semibold hover:underline" @click="toggleGroup(perms)">{{ group }}</button>
                <label v-for="(label, key) in perms" :key="key" class="flex items-start gap-2 py-0.5 text-sm">
                    <input v-model="form.permissions" type="checkbox" :value="key" class="checkbox mt-0.5" /> {{ label }}
                </label>
            </div>
        </div>
        <template #footer><button class="btn-secondary" @click="show = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="save">Guardar</button></template>
    </Modal>
</template>
