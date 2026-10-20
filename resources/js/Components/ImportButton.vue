<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    module: { type: String, required: true },
    permission: { type: String, required: true },
    returnUrl: { type: String, default: null },
    projectId: { type: [Number, String], default: null },
    label: { type: String, default: 'Import' },
});

const page = usePage();

const canImport = computed(() => {
    const user = page.props.auth?.user;
    if (!user) return false;
    if (user.is_admin) return true;
    return (user.permissions || []).includes(props.permission);
});

const href = computed(() => {
    const params = new URLSearchParams();
    if (props.returnUrl) params.set('return', props.returnUrl);
    if (props.projectId) params.set('project_id', String(props.projectId));
    const query = params.toString();
    return `/imports/${props.module}${query ? `?${query}` : ''}`;
});
</script>

<template>
    <Link v-if="canImport" :href="href" class="ui-btn-secondary">{{ label }}</Link>
</template>
