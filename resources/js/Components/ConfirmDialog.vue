<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Confirm' },
    message: { type: String, default: '' },
    confirmLabel: { type: String, default: 'Confirm' },
    danger: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/20 px-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl" role="dialog">
            <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ message }}</p>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn-secondary" @click="emit('close')">Cancel</button>
                <button type="button" :class="danger ? 'ui-btn bg-red-600 text-white hover:bg-red-700' : 'ui-btn-primary'" @click="emit('confirm')">
                    {{ confirmLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
