<script setup>
import { computed, ref } from 'vue';
import { FileText, Paperclip, X } from '@lucide/vue';

const props = defineProps({
    modelValue: { type: Object, default: null },
    label: { type: String, required: true },
    button: { type: String, default: 'Upload File' },
    hint: { type: String, default: '' },
    accept: { type: String, default: '' },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);
const inputId = `file-${Math.random().toString(36).slice(2, 9)}`;
const input = ref(null);

const file = computed(() => (props.modelValue instanceof File ? props.modelValue : null));

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function onChange(event) {
    emit('update:modelValue', event.target.files?.[0] || null);
    event.target.value = '';
}

function clear() {
    emit('update:modelValue', null);

    if (input.value) {
        input.value.value = '';
    }
}

function ignoreDrop(event) {
    event.preventDefault();
}
</script>

<template>
    <div @dragover="ignoreDrop" @drop="ignoreDrop">
        <p class="ui-label" :id="`${inputId}-label`">{{ label }}</p>
        <div v-if="file" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-50 text-slate-500">
                <FileText class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-900">{{ file.name }}</p>
                <p class="text-xs text-slate-500">{{ formatBytes(file.size) }}</p>
            </div>
            <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-50 hover:text-slate-700" aria-label="Remove file" @click="clear">
                <X class="h-4 w-4" />
            </button>
        </div>
        <label
            v-else
            :for="inputId"
            class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-slate-300 hover:bg-slate-50 focus-within:ring-2 focus-within:ring-slate-200"
        >
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-50 text-slate-500">
                <Paperclip class="h-4 w-4" />
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-medium text-slate-800">{{ button }}</span>
                <span v-if="hint" class="block text-xs text-slate-500">{{ hint }}</span>
            </span>
        </label>
        <input
            :id="inputId"
            ref="input"
            type="file"
            class="sr-only"
            :accept="accept"
            :aria-labelledby="`${inputId}-label`"
            @change="onChange"
        >
        <p v-if="error" class="mt-1 text-xs text-red-600" role="alert">{{ error }}</p>
    </div>
</template>
