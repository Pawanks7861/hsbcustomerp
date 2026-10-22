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
    purchaseOrders: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const status = ref(props.filters.status ?? '');
const search = ref(props.filters.search ?? '');

function applyFilters() {
    router.get(`/projects/${props.project.id}/purchase-orders`, {
        status: status.value || undefined,
        search: search.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Purchase Orders" />
    <AdminLayout :project="project">
        <template #header>
            <div>
            <PageHeader title="Purchase Orders" :eyebrow="project.project_name">
                <ImportButton module="purchase_orders" permission="purchase_orders.import" :return-url="`/projects/${project.id}/purchase-orders`" :project-id="project.id" />
            </PageHeader>
            </div>
        </template>

        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <label class="text-sm">
                <span class="mb-1 block font-medium">Status</span>
                <select v-model="status" class="ui-input w-40">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </label>
            <label class="text-sm">
                <span class="mb-1 block font-medium">Search</span>
                <input v-model="search" type="search" class="ui-input w-64" placeholder="PO, PR, supplier, or item">
            </label>
            <button type="submit" class="ui-btn-secondary">Filter</button>
        </form>

        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="px-4 py-3">PO No</th>
                        <th class="px-4 py-3">PR No</th>
                        <th class="px-4 py-3">Item description</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">PO Date</th>
                        <th class="px-4 py-3 text-right font-medium text-slate-500">Grand Total</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="purchaseOrders.length === 0">
                        <td colspan="8" class="border border-slate-200 px-4 py-8 text-center text-slate-500">No purchase orders yet.</td>
                    </tr>
                    <tr v-for="record in purchaseOrders" :key="record.id" class="odd:bg-white even:bg-stone-50">
                        <td class="px-4 py-3.5 font-medium">{{ record.po_number }}</td>
                        <td class="px-4 py-3.5">{{ record.pr_number }}</td>
                        <td class="px-4 py-3.5">{{ record.boq_item }}</td>
                        <td class="px-4 py-3.5">{{ record.supplier_name }}</td>
                        <td class="px-4 py-3.5">{{ record.po_date }}</td>
                        <td class="px-4 py-3.5 text-right tabular-nums">{{ formatInr(record.grand_total) }}</td>
                        <td class="px-4 py-3.5"><StatusBadge :status="record.status" /></td>
                        <td class="px-4 py-3.5">
                            <Link :href="`/projects/${project.id}/purchase-orders/${record.id}`" class="font-medium text-amber-700 hover:underline">View</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
