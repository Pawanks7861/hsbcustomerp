<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';

defineProps({
    project: { type: Object, required: true },
    receipts: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <Head title="Store" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Store" :eyebrow="project.project_name">
                <ImportButton module="store" permission="store.import" :return-url="`/projects/${project.id}/store`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/grns`" class="ui-btn-secondary">Goods Receipt Notes</Link>
                <Link :href="`/projects/${project.id}/store/create`" class="ui-btn-primary">Receive Material</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search receipt no, date, PO, vendor, received by…" />
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Receipt No.</th>
                        <th>Date</th>
                        <th>PO No.</th>
                        <th>Vendor</th>
                        <th>Items</th>
                        <th>Received By</th>
                        <th>Images</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="receipts.data.length === 0"><td colspan="8" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No receipts yet.' }}</td></tr>
                    <tr v-for="receipt in receipts.data" :key="receipt.id">
                        <td>{{ receipt.receipt_number }}</td>
                        <td>{{ receipt.receipt_date }}</td>
                        <td>{{ receipt.po_number || receipt.type }}</td>
                        <td>{{ receipt.vendor || '—' }}</td>
                        <td>{{ receipt.items }}</td>
                        <td>{{ receipt.received_by }}</td>
                        <td>{{ receipt.images }}</td>
                        <td class="text-right"><TableActionIcon action="view" :href="`/projects/${project.id}/store/${receipt.id}`" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="receipts" label="receipts" />
    </AdminLayout>
</template>
