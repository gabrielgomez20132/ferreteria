<script setup>
import { money, qty, datetime, toNum } from '@/lib/format';

// Ticket de 80 mm (lo usan la página imprimible y el pop-up del punto de venta)
defineProps({ sale: Object, company: Object, settings: Object, methods: Object });
</script>

<template>
    <div class="w-[76mm] bg-white p-[3mm] font-mono text-[11px] leading-snug text-black">
        <div class="text-center">
            <div class="text-[14px] font-bold">{{ company.name }}</div>
            <div v-if="company.legal_name">{{ company.legal_name }}</div>
            <div v-if="company.cuit">CUIT {{ company.cuit }}</div>
            <div v-if="company.address">{{ company.address }}</div>
            <div v-if="sale.branch">{{ sale.branch.name }}</div>
            <div v-if="settings.ticket_header" class="whitespace-pre-line">{{ settings.ticket_header }}</div>
        </div>
        <div class="my-2 border-t border-dashed border-black" />
        <div>{{ sale.type === 'quote' ? 'PRESUPUESTO' : 'TICKET' }} N° {{ String(sale.number).padStart(8, '0') }}</div>
        <div>{{ datetime(sale.created_at) }} · {{ sale.user?.name }}</div>
        <div>Cliente: {{ sale.customer?.name ?? 'Consumidor final' }}</div>
        <div v-if="sale.customer?.document_number">{{ sale.customer.document_type }} {{ sale.customer.document_number }}</div>
        <div class="text-[10px]">Documento no válido como factura</div>
        <div class="my-2 border-t border-dashed border-black" />
        <div v-for="i in sale.items" :key="i.id" class="mb-1">
            <div>{{ i.description }}</div>
            <div class="flex justify-between">
                <span>{{ qty(i.quantity) }} x {{ money(i.unit_price) }}<span v-if="toNum(i.discount_percent)"> -{{ toNum(i.discount_percent) }}%</span></span>
                <span>{{ money(i.subtotal) }}</span>
            </div>
        </div>
        <div class="my-2 border-t border-dashed border-black" />
        <div v-if="toNum(sale.discount_amount)" class="flex justify-between"><span>Descuento</span><span>-{{ money(sale.discount_amount) }}</span></div>
        <div class="flex justify-between text-[14px] font-bold"><span>TOTAL</span><span>{{ money(sale.total) }}</span></div>
        <div v-for="p in sale.payments" :key="p.id" class="flex justify-between"><span>{{ methods[p.method] }}</span><span>{{ money(p.amount) }}</span></div>
        <div v-if="toNum(sale.change_amount)" class="flex justify-between"><span>Vuelto</span><span>{{ money(sale.change_amount) }}</span></div>
        <div class="my-2 border-t border-dashed border-black" />
        <div class="text-center whitespace-pre-line">{{ settings.ticket_footer }}</div>
    </div>
</template>
