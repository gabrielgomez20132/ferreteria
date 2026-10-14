<script setup>
import Toasts from '@/Components/Toasts.vue';
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-[1fr_520px]">
        <section class="relative hidden overflow-hidden bg-steel-800 p-12 text-white lg:flex lg:flex-col">
            <div class="flex items-center gap-3">
                <svg viewBox="0 0 32 32" class="size-9"><rect width="32" height="32" rx="6" fill="#F2B705" /><path d="M9 8h14v4h-5v12h-4V12H9z" fill="#1F2A37" /></svg>
                <span class="text-lg font-semibold">FerreteroSoft</span>
            </div>
            <div class="mt-auto max-w-lg">
                <p class="text-[34px] leading-[1.1] font-semibold tracking-tight">
                    Llegó la lista nueva del proveedor. En cinco minutos tenés todos los precios actualizados.
                </p>
                <p class="mt-4 max-w-md text-steel-300">
                    Subí el Excel, revisá qué subió y qué bajó, y aplicá. Stock, ventas, caja y cuentas corrientes en todas tus sucursales.
                </p>
            </div>
            <!-- franja de seguridad -->
            <div class="absolute right-0 bottom-0 left-0 h-3" style="background: repeating-linear-gradient(-45deg, #F2B705 0 14px, #1F2A37 14px 28px)" />
        </section>
        <section class="flex items-center justify-center bg-white p-6 sm:p-12">
            <div class="w-full max-w-sm"><slot /></div>
        </section>
        <Toasts />
    </div>
</template>
