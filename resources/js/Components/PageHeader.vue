<script setup>
defineProps({
    title: { type: String, required: true },
    eyebrow: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <p v-if="eyebrow" class="text-xs text-slate-400">{{ eyebrow }}</p>
            <h1 class="truncate text-xl font-semibold tracking-tight text-slate-900">{{ title }}</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <slot />
        </div>
    </div>
</template>
