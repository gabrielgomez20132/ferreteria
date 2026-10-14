<script setup>
import { Head, router } from '@inertiajs/vue3';
defineProps({ tenant: Object });
</script>

<template>
    <Head title="Suscripción vencida" />
    <div class="flex min-h-screen items-center justify-center p-6">
        <div class="panel max-w-md p-6">
            <h1 class="text-lg font-semibold">La suscripción de {{ tenant?.name }} está vencida</h1>
            <p class="mt-2 text-sm text-steel-600">
                Tus datos están guardados. Para seguir usando el sistema, contactá a soporte para renovar el plan.
            </p>
            <button class="btn-secondary mt-4" @click="router.post(route('logout'))">Cerrar sesión</button>
        </div>
    </div>
</template>
