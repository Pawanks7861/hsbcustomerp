<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    workOrders: { type: Array, required: true },
    filters: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const status = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');

function applyFilters() {
    router.get(`/projects/${props.project.id}/work-orders`, {
        status: status.value || undefined,
        search: search.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Work Orders" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <PageHeader title="Work Orders" :eyebrow="project.project_name">
                    <ImportButton module="work_orders" permission="work_orders.import" :return-url="`/projects/${project.id}/work-orders`" :project-id="project.id" />
                    <Link v-if="abilities.create" :href="`/projects/${project.id}/work-orders/create`" class="ui-btn-primary">New Work Order</Link>
                </PageHeader>
            </div>
        </template>

        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <label class="text-sm">
                <span class="mb-1 block font-medium">Status</span>
                <select v-model="status" class="ui-input w-48">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Pending Approval</option>
                    <option value="approved">Approved</option>
                    <option value="issued">Issued to Contractor</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </label>
            <label class="text-sm">
                <span class="mb-1 block font-medium">Search</span>
                <input v-model="search" type="search" class="ui-input w-64" placeholder="Work order or contractor">
            </label>
            <button type="submit" class="ui-btn-secondary">Filter</button>
        </form>

        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3">Work Order</th>
                        <th class="px-4 py-3">Project</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Contractor</th>
                        <th class="px-4 py-3 text-right">BOQ Items</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3">Approval</th>
                        <th class="px-4 py-3">Work Status</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="workOrders.length === 0">
                        <td colspan="9" class="px-4 py-8 text-center text-slate-500">No work orders yet.</td>
                    </tr>
                    <tr v-for="record in workOrders" :key="record.id" class="odd:bg-white even:bg-stone-50">
                        <td class="px-4 py-3.5 font-medium">{{ record.work_order_number }}</td>
                        <td class="px-4 py-3.5">{{ project.project_name }}</td>
                        <td class="px-4 py-3.5">{{ record.work_order_date }}</td>
                        <td class="px-4 py-3.5">{{ record.contractor_name }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums">{{ record.item_count }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums">{{ formatInr(record.grand_total) }}</td>
                        <td class="px-4 py-3.5"><StatusBadge :status="record.status" :label="record.approval_label" /></td>
                        <td class="px-4 py-3.5"><StatusBadge :status="record.work_status" :label="record.work_label" /></td>
                        <td class="px-4 py-3.5">
                            <Link :href="`/projects/${project.id}/work-orders/${record.id}`" class="font-medium text-amber-700 hover:underline">View</Link>
                            <Link v-if="abilities.edit && record.status === 'draft'" :href="`/projects/${project.id}/work-orders/${record.id}/edit`" class="ml-3 font-medium text-slate-700 hover:underline">Edit</Link>
                            <a v-if="abilities.print" :href="`/projects/${project.id}/work-orders/${record.id}/pdf`" target="_blank" rel="noopener" class="ml-3 font-medium text-slate-700 hover:underline">Print</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
