<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    tone: { type: String, default: 'mint' },
});

const tones = {
    mint: 'bg-[#E7F6EF]',
    beige: 'bg-[#F8F1E7]',
    blue: 'bg-[#E8F1FB]',
    pink: 'bg-[#FDECEC]',
};
</script>

<template>
    <article class="flex min-h-[148px] min-w-0 flex-col justify-between rounded-2xl p-5" :class="tones[tone] || tones.mint">
        <div class="flex items-start justify-between gap-3">
            <p class="min-w-0 text-sm leading-5 text-slate-500">{{ label }}</p>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/70 text-slate-500">
                <slot name="icon" />
            </span>
        </div>
        <p class="mt-6 break-words text-2xl font-semibold leading-tight tracking-tight text-slate-900">{{ value }}</p>
    </article>
</template>
