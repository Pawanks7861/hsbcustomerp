<script setup>
defineProps({
    label: { type: String, required: true },
    error: { type: String, default: '' },
});
</script>

<template>
    <label class="block">
        <span class="ui-label">{{ label }}</span>
        <slot />
        <span v-if="error" class="mt-1 block text-xs text-red-600">{{ error }}</span>
    </label>
</template>
