<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthShell from '@/Components/AuthShell.vue';

const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Ingresar" />
    <AuthShell>
        <h1 class="text-xl font-semibold">Ingresá a tu ferretería</h1>
        <p class="mt-1 text-sm text-steel-500">Usá el email y la contraseña de tu usuario.</p>
        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label class="label" for="email">Email</label>
                <input id="email" v-model="form.email" type="email" class="input py-2" autocomplete="username" autofocus required />
                <p v-if="form.errors.email" class="mt-1 text-xs text-up">{{ form.errors.email }}</p>
            </div>
            <div>
                <label class="label" for="password">Contraseña</label>
                <input id="password" v-model="form.password" type="password" class="input py-2" autocomplete="current-password" required />
            </div>
            <label class="flex items-center gap-2 text-sm text-steel-600"><input v-model="form.remember" type="checkbox" class="checkbox" /> Mantener la sesión iniciada</label>
            <button class="btn-primary w-full py-2" :disabled="form.processing">Ingresar</button>
        </form>
        <p class="mt-6 text-sm text-steel-600">
            ¿Todavía no usás FerreteroSoft? <Link :href="route('register')" class="link font-medium">Creá tu cuenta gratis</Link>
        </p>
        <div class="mt-6 rounded-md border border-dashed border-steel-300 bg-steel-50 p-3 text-xs text-steel-600">
            <div class="font-medium text-steel-800">Cuentas de prueba (contraseña: password)</div>
            <div>demo@ferreteria.com — administrador de la ferretería demo</div>
            <div>vendedor@ferreteria.com — vendedor</div>
            <div>admin@ferreterosoft.com — administrador de la plataforma</div>
        </div>
    </AuthShell>
</template>
