<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthShell from '@/Components/AuthShell.vue';
import Field from '@/Components/Field.vue';

const form = useForm({ company_name: '', cuit: '', phone: '', name: '', email: '', password: '', password_confirmation: '' });
const submit = () => form.post(route('register'));
</script>

<template>
    <Head title="Crear cuenta" />
    <AuthShell>
        <h1 class="text-xl font-semibold">Creá la cuenta de tu ferretería</h1>
        <p class="mt-1 text-sm text-steel-500">15 días de prueba, sin tarjeta.</p>
        <form class="mt-6 space-y-3" @submit.prevent="submit">
            <Field label="Nombre de la ferretería" :error="form.errors.company_name"><input v-model="form.company_name" class="input" required autofocus /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="CUIT (opcional)" :error="form.errors.cuit"><input v-model="form.cuit" class="input" /></Field>
                <Field label="Teléfono" :error="form.errors.phone"><input v-model="form.phone" class="input" /></Field>
            </div>
            <Field label="Tu nombre" :error="form.errors.name"><input v-model="form.name" class="input" required /></Field>
            <Field label="Email" :error="form.errors.email"><input v-model="form.email" type="email" class="input" required /></Field>
            <div class="grid grid-cols-2 gap-3">
                <Field label="Contraseña" :error="form.errors.password"><input v-model="form.password" type="password" class="input" required /></Field>
                <Field label="Repetir contraseña"><input v-model="form.password_confirmation" type="password" class="input" required /></Field>
            </div>
            <button class="btn-primary w-full py-2" :disabled="form.processing">Crear cuenta</button>
        </form>
        <p class="mt-6 text-sm text-steel-600">¿Ya tenés cuenta? <Link :href="route('login')" class="link font-medium">Ingresá</Link></p>
    </AuthShell>
</template>
