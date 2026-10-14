<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';

defineProps({ title: String, subtitle: String, back: String });
</script>

<template>
    <Head :title="title" />
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <Link v-if="back" :href="back" class="mb-1 inline-flex items-center gap-0.5 text-xs text-steel-500 hover:text-steel-800">
                <ChevronLeft class="size-3.5" /> Volver
            </Link>
            <h1 class="text-xl leading-tight font-semibold tracking-tight text-steel-900">{{ title }}</h1>
            <p v-if="subtitle" class="mt-0.5 text-sm text-steel-500">{{ subtitle }}</p>
            <slot name="subtitle" />
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>
