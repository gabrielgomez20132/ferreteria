<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { today } from '@/lib/format';

const props = defineProps({ filters: Object, branches: Array, routeName: String, extra: Object, dates: { type: Boolean, default: true } });
const f = reactive({ ...props.filters });
const apply = () => router.get(route(props.routeName), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null && v !== undefined)), { preserveState: true });
const preset = (days) => {
    f.to = today();
    f.from = days === 'month' ? today().slice(0, 8) + '01' : today(-days);
    apply();
};
defineExpose({ f, apply });
</script>

<template>
    <form class="panel mb-4 flex flex-wrap items-end gap-2 px-4 py-3" @submit.prevent="apply">
        <template v-if="dates">
            <div><label class="label">Desde</label><input v-model="f.from" type="date" class="input" /></div>
            <div><label class="label">Hasta</label><input v-model="f.to" type="date" class="input" /></div>
            <div class="flex gap-1 pb-0.5">
                <button type="button" class="btn-ghost btn-sm" @click="preset(0)">Hoy</button>
                <button type="button" class="btn-ghost btn-sm" @click="preset(7)">7 días</button>
                <button type="button" class="btn-ghost btn-sm" @click="preset('month')">Este mes</button>
                <button type="button" class="btn-ghost btn-sm" @click="preset(90)">90 días</button>
            </div>
        </template>
        <div v-if="branches"><label class="label">Sucursal</label><select v-model="f.branch_id" class="input w-auto"><option value="all">Todas</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
        <slot :f="f" />
        <button class="btn-dark">Ver</button>
    </form>
</template>
