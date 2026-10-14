<script setup>
import { money, datetime } from '@/lib/format';

defineProps({ session: Object, totals: Object, expected: Number, movements: Array, methods: Object, types: Object, salesCount: Number });
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[320px_1fr]">
        <div class="space-y-4">
            <div class="panel p-4 text-sm">
                <div class="flex justify-between"><span class="text-steel-500">Apertura</span><span class="tabular-nums">{{ money(session.opening_amount) }}</span></div>
                <div v-for="(t, m) in totals" :key="m" class="mt-1 flex justify-between">
                    <span>{{ methods[m] ?? m }}</span>
                    <span class="tabular-nums" :class="t.net < 0 && 'text-up'">{{ money(t.net) }}</span>
                </div>
                <div class="mt-3 flex justify-between border-t border-steel-200 pt-2 text-base font-semibold"><span>Efectivo esperado</span><span class="tabular-nums">{{ money(expected) }}</span></div>
                <template v-if="session.status === 'closed'">
                    <div class="mt-1 flex justify-between"><span>Efectivo contado</span><span class="tabular-nums">{{ money(session.counted_cash) }}</span></div>
                    <div class="mt-1 flex justify-between font-semibold" :class="Number(session.difference) < 0 ? 'text-up' : Number(session.difference) > 0 ? 'text-down' : ''"><span>Diferencia</span><span class="tabular-nums">{{ money(session.difference) }}</span></div>
                </template>
                <div class="mt-3 text-xs text-steel-500">{{ salesCount }} ventas · abierta {{ datetime(session.opened_at) }} por {{ session.user?.name }}<span v-if="session.closed_at"> · cerrada {{ datetime(session.closed_at) }} por {{ session.closer?.name }}</span></div>
            </div>
        </div>
        <div class="panel overflow-x-auto">
            <table class="table">
                <thead><tr><th>Hora</th><th>Concepto</th><th>Medio</th><th class="num">Ingreso</th><th class="num">Egreso</th></tr></thead>
                <tbody>
                    <tr v-for="m in movements" :key="m.id">
                        <td class="whitespace-nowrap">{{ datetime(m.created_at) }}</td>
                        <td><span class="badge-gray mr-1">{{ types[m.type] ?? m.type }}</span>{{ m.description }}</td>
                        <td>{{ methods[m.payment_method] ?? m.payment_method }}</td>
                        <td class="num text-down">{{ m.direction === 'in' ? money(m.amount) : '' }}</td>
                        <td class="num text-up">{{ m.direction === 'out' ? money(m.amount) : '' }}</td>
                    </tr>
                    <tr v-if="!movements.length"><td colspan="5" class="py-6 text-center text-steel-500">Sin movimientos todavía.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
