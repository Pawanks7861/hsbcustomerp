<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    filters: { type: Object, required: true },
    vendors: { type: Array, required: true },
    orders: { type: Array, required: true },
    receipts: { type: Array, required: true },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const vendorId = ref(props.filters.vendor_id || '');
const purchaseOrderId = ref(props.filters.purchase_order_id || '');
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

function apply() {
    router.get(`/projects/${props.project.id}/grns`, {
        search: search.value || undefined,
        status: status.value || undefined,
        vendor_id: vendorId.value || undefined,
        purchase_order_id: purchaseOrderId.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Goods Receipt Notes" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Goods Receipt Notes" :eyebrow="project.project_name" />
        </template>
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm"><span class="mb-1 block font-medium">Search</span><input v-model="search" class="ui-input w-48" placeholder="GRN, PO, challan"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Status</span>
                <select v-model="status" class="ui-input w-36">
                    <option value="">All</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Pending</option>
                    <option value="posted">Posted</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Vendor</span>
                <select v-model="vendorId" class="ui-input w-44"><option value="">All</option><option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option></select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">PO</span>
                <select v-model="purchaseOrderId" class="ui-input w-40"><option value="">All</option><option v-for="order in orders" :key="order.id" :value="order.id">{{ order.po_number }}</option></select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">From</span><input v-model="from" type="date" class="ui-input"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">To</span><input v-model="to" type="date" class="ui-input"></label>
            <button class="ui-btn-secondary" type="submit">Filter</button>
        </form>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[980px]">
                <thead>
                    <tr>
                        <th>GRN No.</th><th>Date</th><th>PO</th><th>Vendor</th>
                        <th class="text-right">Ordered</th><th class="text-right">Received</th>
                        <th class="text-right">Accepted</th><th class="text-right">Rejected</th>
                        <th>Created By</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="receipts.length === 0"><td colspan="10" class="py-8 text-center text-slate-400">No goods receipt notes.</td></tr>
                    <tr v-for="row in receipts" :key="row.id">
                        <td><Link :href="`/projects/${project.id}/grns/${row.id}`" class="text-amber-700 hover:underline">{{ row.receipt_number }}</Link></td>
                        <td>{{ row.receipt_date }}</td>
                        <td>{{ row.po_number || '—' }}</td>
                        <td>{{ row.vendor || '—' }}</td>
                        <td class="text-right tabular-nums">{{ row.ordered }}</td>
                        <td class="text-right tabular-nums">{{ row.received }}</td>
                        <td class="text-right tabular-nums">{{ row.accepted }}</td>
                        <td class="text-right tabular-nums">{{ row.rejected }}</td>
                        <td>{{ row.created_by || '—' }}</td>
                        <td class="capitalize">{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
