<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, default: null },
});

const form = useForm({
    project_name: props.project?.project_name ?? '',
    project_code: props.project?.project_code ?? '',
    description: props.project?.description ?? '',
});

function submit() {
    if (props.project) {
        form.put(`/projects/${props.project.id}`);
        return;
    }

    form.post('/projects');
}
</script>

<template>
    <Head :title="project ? 'Edit Project' : 'Create Project'" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="project ? 'Edit project' : 'Create project'" eyebrow="Projects" />
        </template>

        <form class="ui-card max-w-3xl space-y-5 p-6" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <FormField label="Project name" :error="form.errors.project_name">
                    <input v-model="form.project_name" type="text" class="ui-input" required>
                </FormField>
                <FormField label="Project code" :error="form.errors.project_code">
                    <input v-model="form.project_code" type="text" class="ui-input" required>
                </FormField>
            </div>
            <FormField label="Description" :error="form.errors.description">
                <textarea v-model="form.description" rows="4" class="ui-textarea"></textarea>
            </FormField>
            <div class="flex gap-2">
                <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save</button>
                <Link href="/projects" class="ui-btn-secondary">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
