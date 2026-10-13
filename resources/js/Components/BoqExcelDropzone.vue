<script setup>
import { computed, ref } from 'vue';
import { FileSpreadsheet, LoaderCircle, UploadCloud, X } from '@lucide/vue';

const props = defineProps({
    modelValue: { type: Object, default: null },
    error: { type: String, default: '' },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const input = ref(null);
const dragging = ref(false);
const localError = ref('');
const message = computed(() => localError.value || props.error);

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function openPicker(event) {
    if (props.processing || event?.target === input.value) {
        return;
    }

    input.value?.click();
}

function assign(file) {
    localError.value = '';

    if (!file) {
        emit('update:modelValue', null);

        return;
    }

    const name = file.name.toLowerCase();

    if (!name.endsWith('.xlsx') && !name.endsWith('.xls')) {
        localError.value = 'Unsupported file type. Please upload an XLSX or XLS file.';
        emit('update:modelValue', null);

        return;
    }

    if (file.size > 10 * 1024 * 1024) {
        localError.value = 'File is larger than 10 MB.';
        emit('update:modelValue', null);

        return;
    }

    emit('update:modelValue', file);
}

function onBrowse(event) {
    const files = event.target.files;

    if (files && files.length > 1) {
        localError.value = 'Please upload one Excel file.';
        emit('update:modelValue', null);
        event.target.value = '';

        return;
    }

    assign(files?.[0] || null);
    event.target.value = '';
}

function onDrop(event) {
    dragging.value = false;

    if (props.processing) {
        return;
    }

    const files = event.dataTransfer?.files;

    if (!files || files.length === 0) {
        return;
    }

    if (files.length > 1) {
        localError.value = 'Please upload one Excel file.';
        emit('update:modelValue', null);

        return;
    }

    assign(files[0]);
}

function onDragLeave(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        dragging.value = false;
    }
}

function clear() {
    localError.value = '';
    emit('update:modelValue', null);
}
</script>

<template>
    <div>
        <div
            v-if="!modelValue"
            class="cursor-pointer rounded-2xl border border-dashed bg-white px-6 py-8 text-center transition"
            :class="message
                ? 'border-red-300 bg-red-50/40'
                : dragging
                    ? 'border-slate-500 bg-slate-50'
                    : 'border-slate-300 hover:border-slate-400'"
            @click="openPicker"
            @dragenter.prevent="dragging = true"
            @dragover.prevent="dragging = true"
            @dragleave="onDragLeave"
            @drop.prevent="onDrop"
        >
            <UploadCloud class="mx-auto h-8 w-8 text-slate-400" />
            <p class="mt-3 text-sm font-medium text-slate-800">Drag & drop your Excel file here</p>
            <p class="my-3 text-xs uppercase tracking-wide text-slate-400">or</p>
            <button type="button" class="ui-btn-secondary" :disabled="processing" @click.stop="openPicker">Browse File</button>
            <p class="mt-3 text-xs text-slate-500">Supported: .xlsx, .xls · Maximum file size: 10 MB</p>
            <input
                ref="input"
                type="file"
                accept=".xlsx,.xls,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                class="sr-only"
                aria-label="Browse Excel file"
                :disabled="processing"
                @change="onBrowse"
            >
        </div>

        <div v-else class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-500">
                <FileSpreadsheet class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-900">{{ modelValue.name }}</p>
                <p class="text-xs text-slate-500">{{ formatBytes(modelValue.size) }} · Ready to preview</p>
            </div>
            <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-50 hover:text-slate-700" aria-label="Remove file" :disabled="processing" @click="clear">
                <X class="h-4 w-4" />
            </button>
        </div>

        <p v-if="processing" class="mt-3 flex items-center gap-2 text-sm text-slate-500">
            <LoaderCircle class="h-4 w-4 animate-spin" />
            Reading workbook...
        </p>
        <p v-if="message" class="mt-3 text-sm text-red-700" role="alert">{{ message }}</p>
    </div>
</template>
