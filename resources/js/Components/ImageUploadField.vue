<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Image, X } from '@lucide/vue';

const props = defineProps({
    modelValue: { type: [Object, Array], default: null },
    label: { type: String, required: true },
    button: { type: String, default: 'Upload image' },
    hint: { type: String, default: '' },
    accept: { type: String, default: 'image/*' },
    multiple: { type: Boolean, default: false },
    error: { type: String, default: '' },
    currentUrl: { type: String, default: '' },
    currentImages: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);
const inputId = `image-${Math.random().toString(36).slice(2, 9)}`;
const input = ref(null);
const previews = ref([]);

function files() {
    if (props.multiple) {
        return Array.isArray(props.modelValue) ? props.modelValue : [];
    }

    return props.modelValue instanceof File ? [props.modelValue] : [];
}

function formatBytes(bytes) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function refreshPreviews() {
    previews.value.forEach((item) => URL.revokeObjectURL(item.url));
    previews.value = files().map((file) => ({
        name: file.name,
        size: file.size,
        url: URL.createObjectURL(file),
    }));
}

watch(() => props.modelValue, refreshPreviews, { immediate: true });

onBeforeUnmount(() => {
    previews.value.forEach((item) => URL.revokeObjectURL(item.url));
});

function onChange(event) {
    const selected = Array.from(event.target.files || []);
    event.target.value = '';

    if (props.multiple) {
        emit('update:modelValue', [...files(), ...selected]);

        return;
    }

    emit('update:modelValue', selected[0] || null);
}

function removeAt(index) {
    if (!props.multiple) {
        emit('update:modelValue', null);

        return;
    }

    emit('update:modelValue', files().filter((_, itemIndex) => itemIndex !== index));
}

function ignoreDrop(event) {
    event.preventDefault();
}
</script>

<template>
    <div @dragover="ignoreDrop" @drop="ignoreDrop">
        <p class="ui-label" :id="`${inputId}-label`">{{ label }}</p>

        <div v-if="currentImages.length" class="mb-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div v-for="image in currentImages" :key="image.id" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <img :src="image.url" alt="" class="h-24 w-full object-cover">
            </div>
        </div>

        <img
            v-if="currentUrl && previews.length === 0"
            :src="currentUrl"
            alt=""
            loading="lazy"
            decoding="async"
            class="mb-3 max-h-16 max-w-[160px] rounded-lg border border-slate-200 bg-white object-contain p-1"
        >

        <div v-if="previews.length" class="mb-3 space-y-2">
            <div v-for="(item, index) in previews" :key="item.url" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-2">
                <img :src="item.url" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-slate-900">{{ item.name }}</p>
                    <p class="text-xs text-slate-500">{{ formatBytes(item.size) }}</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-slate-50 hover:text-slate-700" :aria-label="`Remove ${item.name}`" @click="removeAt(index)">
                    <X class="h-4 w-4" />
                </button>
            </div>
        </div>

        <label
            :for="inputId"
            class="inline-flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm transition hover:border-slate-300 hover:bg-slate-50 focus-within:ring-2 focus-within:ring-slate-200"
        >
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-50 text-slate-500">
                <Image class="h-4 w-4" />
            </span>
            <span>
                <span class="block font-medium text-slate-800">{{ button }}</span>
                <span v-if="hint" class="block text-xs font-normal text-slate-500">{{ hint }}</span>
            </span>
        </label>
        <input
            :id="inputId"
            ref="input"
            type="file"
            class="sr-only"
            :accept="accept"
            :multiple="multiple"
            :aria-labelledby="`${inputId}-label`"
            @change="onChange"
        >
        <p v-if="error" class="mt-1 text-xs text-red-600" role="alert">{{ error }}</p>
    </div>
</template>
