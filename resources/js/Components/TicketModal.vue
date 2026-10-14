<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { Printer, Download, Mail, Loader2, X } from '@lucide/vue';
import TicketView from '@/Components/TicketView.vue';
import { getJson } from '@/lib/http';
import { money } from '@/lib/format';
import { downloadTicketPdf } from '@/lib/ticketPdf';

/**
 * Pop-up con la vista previa del comprobante: imprimir, descargar PDF y (próximamente) enviar por email.
 */
const props = defineProps({ saleId: [Number, null], change: { type: Number, default: 0 }, title: { type: String, default: 'Comprobante' } });
const emit = defineEmits(['close']);

const data = ref(null);
const loading = ref(false);
const error = ref(null);
const downloading = ref(false);

watch(
    () => props.saleId,
    async (id) => {
        data.value = null;
        error.value = null;
        if (!id) return;
        loading.value = true;
        try {
            data.value = await getJson(route('sales.ticket', id));
        } catch (e) {
            error.value = 'No se pudo cargar el comprobante.';
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

const onKey = (e) => e.key === 'Escape' && props.saleId && emit('close');
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));

const print = () => window.open(route('sales.ticket', props.saleId), '_blank', 'width=420,height=700');
async function download() {
    downloading.value = true;
    try {
        await downloadTicketPdf(data.value);
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="saleId" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-steel-900/50 p-4 sm:pt-10" @mousedown.self="emit('close')">
            <div class="flex max-h-[calc(100vh-5rem)] w-full max-w-md flex-col rounded-lg bg-white shadow-xl" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b border-steel-200 px-4 py-3">
                    <div>
                        <h2 class="text-[15px] font-semibold">{{ title }}</h2>
                        <p v-if="change > 0" class="text-sm font-semibold text-down">Vuelto: {{ money(change) }}</p>
                    </div>
                    <button class="text-steel-400 hover:text-steel-800" @click="emit('close')" aria-label="Cerrar"><X class="size-5" /></button>
                </div>

                <div class="flex flex-1 justify-center overflow-y-auto bg-steel-100 p-4">
                    <Loader2 v-if="loading" class="mt-10 size-6 animate-spin text-steel-400" />
                    <p v-else-if="error" class="mt-10 text-sm text-up">{{ error }}</p>
                    <TicketView v-else-if="data" v-bind="data" class="shadow-sm" />
                </div>

                <div class="space-y-2 border-t border-steel-200 p-4">
                    <div class="grid grid-cols-2 gap-2">
                        <button class="btn-primary" :disabled="!data" @click="print"><Printer class="size-4" /> Imprimir</button>
                        <button class="btn-secondary" :disabled="!data || downloading" @click="download"><Download class="size-4" /> {{ downloading ? 'Generando…' : 'Descargar PDF' }}</button>
                    </div>
                    <button class="btn-secondary w-full" disabled title="Próximamente: envío del comprobante por email al cliente">
                        <Mail class="size-4" /> Enviar por email <span class="badge-gray ml-1">Próximamente</span>
                    </button>
                    <button class="btn-ghost w-full" @click="emit('close')">Cerrar</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
