<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Modal from '@/Components/Modal.vue';
import Field from '@/Components/Field.vue';
import { date, datetime } from '@/lib/format';

const props = defineProps({ tenants: Object, plans: Array, filters: Object });
const search = ref(props.filters.search ?? '');
const go = (p) => router.get(route('admin.tenants.index'), { ...props.filters, ...p }, { preserveState: true });

const showNew = ref(false);
const create = useForm({ name: '', cuit: '', plan_id: props.plans[0]?.id, status: 'trial', admin_name: '', admin_email: '', admin_password: '' });
const submitNew = () => create.post(route('admin.tenants.store'), { onSuccess: () => { showNew.value = false; create.reset(); } });

const editing = ref(null);
const edit = useForm({ plan_id: '', status: '', trial_ends_at: '', subscription_ends_at: '' });
const open = (t) => {
    editing.value = t;
    Object.assign(edit, { plan_id: t.plan_id, status: t.status, trial_ends_at: t.trial_ends_at?.slice(0, 10) ?? '', subscription_ends_at: t.subscription_ends_at?.slice(0, 10) ?? '' });
};
const save = () => edit.transform((d) => ({ ...d, trial_ends_at: d.trial_ends_at || null, subscription_ends_at: d.subscription_ends_at || null })).put(route('admin.tenants.update', editing.value.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
</script>

<template>
    <PageHeader title="Empresas suscriptas"><button class="btn-primary" @click="showNew = true"><Plus class="size-4" /> Nueva empresa</button></PageHeader>
    <div class="panel">
        <div class="flex gap-2 border-b border-steel-200 px-4 py-3">
            <form @submit.prevent="go({ search })"><input v-model="search" class="input w-72" placeholder="Nombre, email o CUIT" /></form>
            <select class="input w-auto" v-sel="filters.status ?? ''" @change="go({ status: $event.target.value || undefined })"><option value="">Todos los estados</option><option value="trial">Prueba</option><option value="active">Activa</option><option value="suspended">Suspendida</option><option value="cancelled">Cancelada</option></select>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Empresa</th><th>Plan</th><th>Estado</th><th class="num">Sucursales</th><th class="num">Usuarios</th><th>Última venta</th><th>Vence</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="t in tenants.data" :key="t.id">
                        <td class="font-medium">{{ t.name }}<div class="text-xs font-normal text-steel-500">{{ t.email }} · {{ t.cuit }}</div></td>
                        <td>{{ t.plan?.name }}</td>
                        <td><StatusBadge :status="t.status" /></td>
                        <td class="num">{{ t.branches_count }}</td>
                        <td class="num">{{ t.users_count }}</td>
                        <td>{{ datetime(t.last_sale_at) }}</td>
                        <td>{{ date(t.status === 'trial' ? t.trial_ends_at : t.subscription_ends_at) }}</td>
                        <td class="text-right"><button class="text-xs link" @click="open(t)">Gestionar</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :data="tenants" />
    </div>

    <Modal :show="showNew" title="Nueva empresa" @close="showNew = false">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Nombre" :error="create.errors.name"><input v-model="create.name" class="input" /></Field>
            <Field label="CUIT"><input v-model="create.cuit" class="input" /></Field>
            <Field label="Plan"><select v-model="create.plan_id" class="input"><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
            <Field label="Estado"><select v-model="create.status" class="input"><option value="trial">Prueba</option><option value="active">Activa</option></select></Field>
            <Field label="Nombre del administrador" :error="create.errors.admin_name"><input v-model="create.admin_name" class="input" /></Field>
            <Field label="Email del administrador" :error="create.errors.admin_email"><input v-model="create.admin_email" type="email" class="input" /></Field>
            <Field label="Contraseña inicial" :error="create.errors.admin_password"><input v-model="create.admin_password" class="input" /></Field>
        </div>
        <template #footer><button class="btn-secondary" @click="showNew = false">Cancelar</button><button class="btn-primary" :disabled="create.processing" @click="submitNew">Crear</button></template>
    </Modal>

    <Modal :show="!!editing" :title="editing?.name" @close="editing = null">
        <div class="grid grid-cols-2 gap-3">
            <Field label="Plan"><select v-model="edit.plan_id" class="input"><option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option></select></Field>
            <Field label="Estado"><select v-model="edit.status" class="input"><option value="trial">Prueba</option><option value="active">Activa</option><option value="suspended">Suspendida</option><option value="cancelled">Cancelada</option></select></Field>
            <Field label="Fin de prueba"><input v-model="edit.trial_ends_at" type="date" class="input" /></Field>
            <Field label="Suscripción paga hasta"><input v-model="edit.subscription_ends_at" type="date" class="input" /></Field>
        </div>
        <template #footer><button class="btn-secondary" @click="editing = null">Cancelar</button><button class="btn-primary" :disabled="edit.processing" @click="save">Guardar</button></template>
    </Modal>
</template>
