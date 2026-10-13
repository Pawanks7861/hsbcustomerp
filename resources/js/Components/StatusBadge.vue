<script setup>
defineProps({
    status: { type: String, required: true },
    label: { type: String, default: '' },
});

const classes = {
    draft: 'bg-slate-100 text-slate-600',
    submitted: 'bg-sky-50 text-sky-700',
    approved: 'bg-emerald-50 text-emerald-700',
    rejected: 'bg-red-50 text-red-600',
    active: 'bg-emerald-50 text-emerald-700',
    inactive: 'bg-slate-100 text-slate-500',
};
</script>

<template>
    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium capitalize" :class="classes[status] || classes.draft">
        {{ label || status }}
    </span>
</template>
