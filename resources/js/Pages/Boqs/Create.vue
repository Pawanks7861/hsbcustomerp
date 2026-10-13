<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
});

const form = useForm({
    title: '',
    gst_percentage: 18,
});

function submit() {
    form.post(`/projects/${props.project.id}/boqs`);
}
</script>

<template>
    <Head title="Create BOQ" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Create BOQ" :eyebrow="project.project_name" />
        </template>

        <form class="ui-card grid max-w-3xl gap-5 p-6 sm:grid-cols-2" @submit.prevent="submit">
            <FormField label="Title" :error="form.errors.title">
                <input v-model="form.title" type="text" class="ui-input" required>
            </FormField>
            <FormField label="GST percentage" :error="form.errors.gst_percentage">
                <input v-model="form.gst_percentage" type="number" min="0" max="100" step="0.01" class="ui-input" required>
            </FormField>
            <div class="flex gap-2 sm:col-span-2">
                <button type="submit" class="ui-btn-primary" :disabled="form.processing">Create BOQ</button>
                <Link :href="`/projects/${project.id}/boqs`" class="ui-btn-secondary">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
