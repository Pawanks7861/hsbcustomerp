<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatQty } from '../../lib/money';
import { visitListing } from '../../lib/listing';

defineProps({
    project: { type: Object, required: true },
    purchaseRequests: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <Head title="Purchase Requests" />
    <AdminLayout :project="project">
        <template #header>
            <div>
            <PageHeader title="Purchase Requests" :eyebrow="project.project_name">
                <ImportButton module="purchase_requests" permission="purchase_requests.import" :return-url="`/projects/${project.id}/purchase-requests`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/purchase-requests/create`" class="ui-btn-primary">New request</Link>
            </PageHeader>
            </div>
        </template>

        <TableToolbar :search="filters.search" placeholder="Search PR no, item, date, requester, status…">
            <label class="text-sm">
                <span class="sr-only">Status</span>
                <select class="ui-input w-40" :value="filters.status || ''" @change="visitListing({ status: $event.target.value })">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </label>
        </TableToolbar>

        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3">PR No</th>
                        <th class="px-4 py-3">Item</th>
                        <th class="px-4 py-3">Request Date</th>
                        <th class="px-4 py-3">Required Date</th>
                        <th class="px-4 py-3">Requested By</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Qty Control</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="purchaseRequests.data.length === 0">
                        <td colspan="8" class="border border-slate-200 px-4 py-8 text-center text-slate-500">{{ filters.search || filters.status ? 'No matching records found.' : 'No purchase requests yet.' }}</td>
                    </tr>
                    <tr v-for="record in purchaseRequests.data" :key="record.id" class="odd:bg-white even:bg-stone-50">
                        <td class="px-4 py-3.5 font-medium">{{ record.pr_number }}</td>
                        <td class="px-4 py-3.5">{{ record.boq_item }}</td>
                        <td class="px-4 py-3.5">{{ record.request_date }}</td>
                        <td class="px-4 py-3.5">{{ record.required_date || '—' }}</td>
                        <td class="px-4 py-3.5">{{ record.requested_by }}</td>
                        <td class="px-4 py-3.5"><StatusBadge :status="record.status" /></td>
                        <td class="px-4 py-3.5">
                            <span v-if="record.quantity_control?.state === 'within'" class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Within BOQ</span>
                            <span v-else-if="record.quantity_control?.state === 'exceeded'" class="rounded-full bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-800">Exceeded {{ formatQty(record.quantity_control.excess_quantity, record.quantity_control.unit) }}</span>
                            <span v-else-if="record.quantity_control?.state === 'released'" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">Released</span>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">No BOQ qty</span>
                        </td>
                        <td class="px-4 py-3.5">
                            <TableActionIcon action="view" :href="`/projects/${project.id}/purchase-requests/${record.id}`" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="purchaseRequests" label="purchase requests" />
    </AdminLayout>
</template>
