<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import { datetime } from '@/lib/format';

const props = defineProps({ users: Array, roles: Array, branches: Array, plan: Object });
const editing = ref(null);
const show = ref(false);
const form = useForm({ name: '', email: '', password: '', role_id: '', is_active: true, branch_ids: [] });
const open = (u = null) => {
    editing.value = u;
    form.clearErrors();
    Object.assign(form, { name: u?.name ?? '', email: u?.email ?? '', password: '', role_id: u?.role_id ?? props.roles[0]?.id, is_active: u?.is_active ?? true, branch_ids: u?.branches?.map((b) => b.id) ?? [] });
    show.value = true;
};
const save = () => {
    const o = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('users.update', editing.value.id), o) : form.post(route('users.store'), o);
};
</script>

<template>
    <PageHeader title="Usuarios" :subtitle="plan ? `Plan ${plan.name}: hasta ${plan.max_users} usuarios activos` : ''"><button class="btn-primary" @click="open()"><Plus class="size-4" /> Nuevo usuario</button></PageHeader>
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Sucursales</th><th>Último ingreso</th><th></th></tr></thead>
            <tbody>
                <tr v-for="u in users" :key="u.id" :class="!u.is_active && 'opacity-50'">
                    <td class="font-medium">{{ u.name }} <span v-if="!u.is_active" class="badge-gray">Inactivo</span></td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.role?.name }}</td>
                    <td>{{ u.branches.length ? u.branches.map((b) => b.name).join(', ') : 'Todas' }}</td>
                    <td>{{ datetime(u.last_login_at) }}</td>
                    <td class="text-right"><button class="text-xs link" @click="open(u)">Editar</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Modal :show="show" :title="editing ? 'Editar usuario' : 'Nuevo usuario'" @close="show = false">
        <div class="grid gap-3">
            <Field label="Nombre" :error="form.errors.name"><input v-model="form.name" class="input" /></Field>
            <Field label="Email" :error="form.errors.email"><input v-model="form.email" type="email" class="input" /></Field>
            <Field :label="editing ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña'" :error="form.errors.password"><input v-model="form.password" type="password" class="input" autocomplete="new-password" /></Field>
            <Field label="Rol" :error="form.errors.role_id"><select v-model="form.role_id" class="input"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option></select></Field>
            <div>
                <div class="label">Sucursales donde puede trabajar (ninguna = todas)</div>
                <label v-for="b in branches" :key="b.id" class="mr-4 inline-flex items-center gap-2 text-sm"><input v-model="form.branch_ids" type="checkbox" :value="b.id" class="checkbox" /> {{ b.name }}</label>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="checkbox" /> Activo</label>
        </div>
        <template #footer><button class="btn-secondary" @click="show = false">Cancelar</button><button class="btn-primary" :disabled="form.processing" @click="save">Guardar</button></template>
    </Modal>
</template>
