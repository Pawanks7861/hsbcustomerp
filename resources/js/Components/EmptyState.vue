<script setup>
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div class="px-6 py-14 text-center">
        <p class="text-sm text-slate-500">{{ title }}</p>
        <div class="mt-4">
            <slot />
        </div>
    </div>
</template>
