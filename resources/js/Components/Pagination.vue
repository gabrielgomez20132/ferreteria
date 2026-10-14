<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ data: Object });
const label = (l) => l.replace('&laquo; Previous', '‹').replace('Next &raquo;', '›').replace('pagination.previous', '‹').replace('pagination.next', '›');
</script>

<template>
    <div v-if="data && data.last_page > 1" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
        <span class="text-steel-500">{{ data.from }}–{{ data.to }} de {{ data.total }}</span>
        <div class="flex flex-wrap gap-1">
            <template v-for="(l, i) in data.links" :key="i">
                <Link
                    v-if="l.url" :href="l.url" preserve-scroll
                    class="min-w-8 rounded border px-2 py-1 text-center"
                    :class="l.active ? 'border-steel-800 bg-steel-800 text-white' : 'border-steel-200 bg-white hover:bg-steel-50'"
                    v-html="label(l.label)"
                />
                <span v-else class="min-w-8 px-2 py-1 text-center text-steel-300" v-html="label(l.label)" />
            </template>
        </div>
    </div>
    <div v-else-if="data && data.total" class="px-4 py-3 text-sm text-steel-500">{{ data.total }} registro{{ data.total === 1 ? '' : 's' }}</div>
</template>
