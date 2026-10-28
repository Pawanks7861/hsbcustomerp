<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import { formatInr, formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    item: { type: Object, required: true },
    purchaseRequests: { type: Array, required: true },
    purchaseOrders: { type: Array, required: true },
    quantitySummary: { type: Object, required: true },
    receiptSummary: { type: Object, default: () => ({ ordered_quantity: '0.000', accepted_quantity: '0.000', invoiced_quantity: '0.000' }) },
    goodsReceipts: { type: Array, default: () => [] },
    workOrderSummary: { type: Object, default: () => ({ boq_quantity: '0.000', allocated_quantity: '0.000', remaining_quantity: '0.000' }) },
    workOrders: { type: Array, default: () => [] },
    canCreateWorkOrder: { type: Boolean, default: false },
});
</script>

<template>
    <Head :title="`Procurement ${item.item_number}`" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">BOQ item procurement</h1>
                    <p class="text-sm text-slate-500">{{ boq.title }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="item.procurable"
                        :href="`/projects/${project.id}/boqs/${boq.id}/items/${item.id}/purchase-requests/create`"
                        class="ui-btn-primary"
                    >
                        + Create Purchase Request
                    </Link>
                    <Link
                        v-if="canCreateWorkOrder"
                        :href="`/projects/${project.id}/work-orders/create?boq_item_id=${item.id}`"
                        class="ui-btn-secondary"
                    >
                        + Create Work Order
                    </Link>
                </div>
            </div>
        </template>

        <section class="ui-card p-6">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">BOQ item</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-slate-500">Item No</dt><dd class="font-medium">{{ item.item_number }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Description</dt><dd class="font-medium">{{ item.description }}</dd></div>
                <div><dt class="text-slate-500">Unit</dt><dd>{{ item.unit || '—' }}</dd></div>
                <div><dt class="text-slate-500">Qty</dt><dd class="tabular-nums">{{ item.quantity ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Rate</dt><dd class="tabular-nums">{{ item.rate === null ? '—' : formatInr(item.rate) }}</dd></div>
                <div><dt class="text-slate-500">BOQ Amount</dt><dd class="tabular-nums">{{ item.amount === null ? '—' : formatInr(item.amount) }}</dd></div>
                <div><dt class="text-slate-500">Budget</dt><dd class="tabular-nums">{{ formatInr(item.budget_total) }}</dd></div>
            </dl>
            <dl class="mt-6 grid gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-slate-500">BOQ Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(quantitySummary.boq_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">Requested Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(quantitySummary.used_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">Approved Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(quantitySummary.approved_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">PO Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(receiptSummary.ordered_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">GRN Accepted</dt><dd class="font-medium tabular-nums">{{ formatQty(receiptSummary.accepted_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">Invoiced Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(receiptSummary.invoiced_quantity, quantitySummary.unit) }}</dd></div>
                <div>
                    <dt class="text-slate-500">Remaining Quantity</dt>
                    <dd class="font-medium tabular-nums">{{ formatQty(quantitySummary.remaining_quantity, quantitySummary.unit) }}</dd>
                    <p v-if="Number(quantitySummary.remaining_quantity) < 0" class="mt-1 text-xs font-medium text-orange-800">
                        Over Requested by {{ formatQty(Math.abs(Number(quantitySummary.remaining_quantity)), quantitySummary.unit) }}
                    </p>
                </div>
            </dl>
        </section>

        <section class="mt-6">
            <h2 class="mb-3 text-lg font-semibold">Work Orders</h2>
            <p class="mb-3 text-sm text-slate-500">Work order quantities are allocated to contractors. They are separate from purchase requests, purchase orders, and posted GRNs.</p>
            <dl class="ui-card mb-4 grid gap-4 p-6 text-sm sm:grid-cols-3">
                <div><dt class="text-slate-500">Original BOQ Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(workOrderSummary.boq_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">Total Active Work Order Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(workOrderSummary.allocated_quantity, quantitySummary.unit) }}</dd></div>
                <div><dt class="text-slate-500">Remaining Quantity Available for Work Orders</dt><dd class="font-medium tabular-nums">{{ formatQty(workOrderSummary.remaining_quantity, quantitySummary.unit) }}</dd></div>
            </dl>
            <div class="ui-table-wrap">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">Work Order</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Contractor</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Allocated Qty</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Rate</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Amount</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="workOrders.length === 0">
                            <td colspan="6" class="border border-slate-200 px-4 py-6 text-center text-slate-500">No work orders for this item.</td>
                        </tr>
                        <tr v-for="record in workOrders" :key="record.id" class="odd:bg-white even:bg-stone-50">
                            <td class="border border-slate-200 px-4 py-3 font-medium">
                                <Link :href="`/projects/${project.id}/work-orders/${record.work_order_id}`" class="text-amber-700 hover:underline">{{ record.work_order_number }}</Link>
                            </td>
                            <td class="border border-slate-200 px-4 py-3">{{ record.contractor_name }}</td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatQty(record.quantity) }}</td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatInr(record.rate) }}</td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatInr(record.amount) }}</td>
                            <td class="border border-slate-200 px-4 py-3"><StatusBadge :status="record.status" :label="record.status_label" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6">
            <h2 class="mb-3 text-lg font-semibold">Purchase Requests</h2>
            <div class="ui-table-wrap">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">PR No</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Date</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Requested By</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Estimated Amount</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="purchaseRequests.length === 0">
                            <td colspan="6" class="border border-slate-200 px-4 py-6 text-center text-slate-500">No purchase requests for this item.</td>
                        </tr>
                        <tr v-for="record in purchaseRequests" :key="record.id" class="odd:bg-white even:bg-stone-50">
                            <td class="border border-slate-200 px-4 py-3 font-medium">{{ record.pr_number }}</td>
                            <td class="border border-slate-200 px-4 py-3">{{ record.request_date }}</td>
                            <td class="border border-slate-200 px-4 py-3"><StatusBadge :status="record.status" /></td>
                            <td class="border border-slate-200 px-4 py-3">{{ record.requested_by }}</td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatInr(record.estimated_total) }}</td>
                            <td class="border border-slate-200 px-4 py-3">
                                <TableActionIcon action="view" :href="`/projects/${project.id}/purchase-requests/${record.id}`" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6">
            <h2 class="mb-3 text-lg font-semibold">Purchase Orders</h2>
            <div class="ui-table-wrap">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">PO No</th>
                            <th class="px-4 py-3 font-medium text-slate-500">PR No</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Supplier</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Date</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Status</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Grand Total</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="purchaseOrders.length === 0">
                            <td colspan="7" class="border border-slate-200 px-4 py-6 text-center text-slate-500">No purchase orders for this item.</td>
                        </tr>
                        <tr v-for="record in purchaseOrders" :key="record.id" class="odd:bg-white even:bg-stone-50">
                            <td class="border border-slate-200 px-4 py-3 font-medium">{{ record.po_number }}</td>
                            <td class="border border-slate-200 px-4 py-3">{{ record.pr_number }}</td>
                            <td class="border border-slate-200 px-4 py-3">{{ record.supplier_name }}</td>
                            <td class="border border-slate-200 px-4 py-3">{{ record.po_date }}</td>
                            <td class="border border-slate-200 px-4 py-3"><StatusBadge :status="record.status" /></td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatInr(record.grand_total) }}</td>
                            <td class="border border-slate-200 px-4 py-3">
                                <TableActionIcon action="view" :href="`/projects/${project.id}/purchase-orders/${record.id}`" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6">
            <h2 class="mb-3 text-lg font-semibold">Posted GRNs</h2>
            <div class="ui-table-wrap">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 font-medium text-slate-500">GRN</th>
                            <th class="px-4 py-3 font-medium text-slate-500">Date</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Accepted</th>
                            <th class="px-4 py-3 font-medium text-slate-500 text-right">Rejected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="goodsReceipts.length === 0">
                            <td colspan="4" class="border border-slate-200 px-4 py-6 text-center text-slate-500">No posted GRNs for this item.</td>
                        </tr>
                        <tr v-for="receipt in goodsReceipts" :key="`${receipt.id}-${receipt.accepted}`" class="odd:bg-white even:bg-stone-50">
                            <td class="border border-slate-200 px-4 py-3 font-medium">
                                <Link :href="`/projects/${project.id}/grns/${receipt.id}`" class="text-amber-700 hover:underline">{{ receipt.receipt_number }}</Link>
                            </td>
                            <td class="border border-slate-200 px-4 py-3">{{ receipt.receipt_date }}</td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatQty(receipt.accepted) }}</td>
                            <td class="border border-slate-200 px-4 py-3 text-right tabular-nums">{{ formatQty(receipt.rejected) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
