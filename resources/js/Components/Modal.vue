<script setup>
import { onMounted, onUnmounted, watch } from 'vue';
import { X } from '@lucide/vue';

const props = defineProps({ show: Boolean, title: String, width: { type: String, default: 'max-w-lg' } });
const emit = defineEmits(['close']);

const onKey = (e) => e.key === 'Escape' && props.show && emit('close');
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
watch(() => props.show, (v) => (document.body.style.overflow = v ? 'hidden' : ''));
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-steel-900/50 p-4 sm:pt-16" @mousedown.self="emit('close')">
            <div :class="width" class="w-full rounded-lg bg-white shadow-xl" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b border-steel-200 px-4 py-3">
                    <h2 class="text-[15px] font-semibold">{{ title }}</h2>
                    <button class="text-steel-400 hover:text-steel-800" @click="emit('close')" aria-label="Cerrar"><X class="size-5" /></button>
                </div>
                <div class="p-4"><slot /></div>
                <div v-if="$slots.footer" class="flex justify-end gap-2 border-t border-steel-200 bg-steel-50 px-4 py-3"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
