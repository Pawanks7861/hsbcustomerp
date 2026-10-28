<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';

defineProps({
    project: { type: Object, required: true },
    notes: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <Head title="Credit Notes" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Credit Notes" :eyebrow="project.project_name">
                <ImportButton module="credit_notes" permission="credit_notes.import" :return-url="`/projects/${project.id}/credit-notes`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/credit-notes/create`" class="ui-btn-primary">+ Credit Note</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search number, date, vendor, amount, status…" />
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr><th>No.</th><th>Date</th><th>Vendor</th><th class="text-right">Total</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-if="notes.data.length === 0"><td colspan="6" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No credit notes yet.' }}</td></tr>
                    <tr v-for="note in notes.data" :key="note.id">
                        <td>{{ note.number }}</td>
                        <td>{{ note.date }}</td>
                        <td>{{ note.vendor }}</td>
                        <td class="text-right">{{ formatInr(note.total_amount) }}</td>
                        <td><StatusBadge :status="note.status" /></td>
                        <td class="text-right"><TableActionIcon action="view" :href="`/projects/${project.id}/credit-notes/${note.id}`" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="notes" label="credit notes" />
    </AdminLayout>
</template>
