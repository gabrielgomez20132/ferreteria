<script setup>
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { money, datetime } from '@/lib/format';

defineProps({ sessions: Object });
</script>

<template>
    <PageHeader title="Historial de cajas" :back="route('cash.index')" />
    <div class="panel overflow-x-auto">
        <table class="table">
            <thead><tr><th>Apertura</th><th>Cierre</th><th>Caja</th><th>Sucursal</th><th>Responsable</th><th class="num">Inicial</th><th class="num">Esperado</th><th class="num">Contado</th><th class="num">Diferencia</th><th>Estado</th></tr></thead>
            <tbody>
                <tr v-for="s in sessions.data" :key="s.id">
                    <td class="whitespace-nowrap"><Link :href="route('cash.show', s.id)" class="link">{{ datetime(s.opened_at) }}</Link></td>
                    <td class="whitespace-nowrap">{{ datetime(s.closed_at) }}</td>
                    <td>{{ s.cash_register?.name }}</td>
                    <td>{{ s.branch?.name }}</td>
                    <td>{{ s.user?.name }}</td>
                    <td class="num">{{ money(s.opening_amount) }}</td>
                    <td class="num">{{ money(s.expected_cash) }}</td>
                    <td class="num">{{ money(s.counted_cash) }}</td>
                    <td class="num font-medium" :class="Number(s.difference) < 0 ? 'text-up' : Number(s.difference) > 0 ? 'text-down' : ''">{{ s.difference !== null ? money(s.difference) : '' }}</td>
                    <td><StatusBadge :status="s.status" /></td>
                </tr>
            </tbody>
        </table>
        <Pagination :data="sessions" />
    </div>
</template>
