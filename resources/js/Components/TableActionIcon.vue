<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Eye, Pencil, Trash2 } from '@lucide/vue';

const props = defineProps({
    action: { type: String, required: true },
    href: { type: String, default: null },
    label: { type: String, default: '' },
    target: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const icons = { view: Eye, edit: Pencil, delete: Trash2 };
const captions = { view: 'View', edit: 'Edit', delete: 'Delete' };
const tones = {
    view: 'text-slate-700 hover:bg-slate-100',
    edit: 'text-amber-700 hover:bg-amber-50',
    delete: 'text-red-700 hover:bg-red-50',
};

const icon = computed(() => icons[props.action] || Eye);
const caption = computed(() => props.label || captions[props.action] || 'View');
const tone = computed(() => tones[props.action] || tones.view);
const tag = computed(() => {
    if (!props.href) {
        return 'button';
    }

    return props.target ? 'a' : Link;
});
</script>

<template>
    <component
        :is="disabled ? 'button' : tag"
        :href="disabled ? undefined : (href || undefined)"
        :type="disabled || !href ? 'button' : undefined"
        :target="disabled ? undefined : (target || undefined)"
        :rel="!disabled && target === '_blank' ? 'noopener' : undefined"
        :title="caption"
        :aria-label="caption"
        :disabled="disabled || undefined"
        class="inline-flex h-8 w-8 items-center justify-center rounded-lg"
        :class="disabled ? 'cursor-not-allowed text-slate-300' : tone"
    >
        <component :is="icon" class="h-4 w-4" />
    </component>
</template>
