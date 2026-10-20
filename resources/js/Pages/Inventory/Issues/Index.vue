<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import PageHeader from '../../../Components/PageHeader.vue';

defineProps({
    project: { type: Object, required: true },
    issues: { type: Array, required: true },
});
</script>

<template>
    <Head title="Material Issues" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Material Issues" :eyebrow="project.project_name">
                <ImportButton module="material_issues" permission="inventory.import" :return-url="`/projects/${project.id}/inventory/issues`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/inventory/issues/create`" class="ui-btn-primary">New issue</Link>
            </PageHeader>
        </template>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th>Issue No.</th><th>Date</th><th>Issued To</th><th>Purpose</th><th>Items</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-if="issues.length === 0"><td colspan="6" class="py-8 text-center text-slate-400">No material issues.</td></tr>
                    <tr v-for="issue in issues" :key="issue.id">
                        <td><Link :href="`/projects/${project.id}/inventory/issues/${issue.id}`" class="text-amber-700 hover:underline">{{ issue.issue_number }}</Link></td>
                        <td>{{ issue.issue_date }}</td>
                        <td>{{ issue.issued_to }}</td>
                        <td>{{ issue.purpose || '—' }}</td>
                        <td>{{ issue.items }}</td>
                        <td class="capitalize">{{ issue.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
