<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import CompanyHeader from '../../Components/CompanyHeader.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import { formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    company: { type: Object, default: null },
    purchaseRequest: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const page = usePage();
const rejecting = ref(false);
const reason = ref('');
const reasonError = ref('');
const approvingExcess = ref(Boolean(page.props.errors?.over_quantity_approval_note));
const approvalNote = ref('');
const noteError = ref('');

const control = computed(() => props.purchaseRequest.quantity_control);
const unit = computed(() => control.value?.unit || '');

function submit() {
    if (!confirm('Submit this purchase request?')) {
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/submit`);
}

function approve() {
    if (props.purchaseRequest.is_boq_quantity_exceeded) {
        approvingExcess.value = true;
        return;
    }

    if (!confirm('Approve this purchase request?')) {
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/approve`);
}

function confirmExcessApproval() {
    noteError.value = '';

    if (!approvalNote.value.trim()) {
        noteError.value = 'Approval note is required.';
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/approve`, {
        over_quantity_approval_note: approvalNote.value,
    });
}

function reject() {
    reasonError.value = '';

    if (!reason.value.trim()) {
        reasonError.value = 'Rejection reason is required.';
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/reject`, {
        rejection_reason: reason.value,
    });
}

function reopen() {
    router.post(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/reopen`);
}

function destroy() {
    if (!confirm('Delete this draft purchase request?')) {
        return;
    }

    router.delete(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}`);
}
</script>

<template>
    <Head :title="purchaseRequest.pr_number" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">{{ purchaseRequest.pr_number }}</h1>
                    <p class="text-sm text-slate-500">Purchase request</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <DocumentPdfActions
                        :view-url="`/projects/${project.id}/purchase-requests/${purchaseRequest.id}/pdf`"
                        :download-url="`/projects/${project.id}/purchase-requests/${purchaseRequest.id}/pdf/download`"
                    />
                    <StatusBadge :status="purchaseRequest.status" />
                    <span v-if="purchaseRequest.is_boq_quantity_exceeded" class="rounded-full bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-800">Qty Exceeded</span>
                </div>
            </div>
        </template>

        <article class="ui-card space-y-6 p-6 sm:p-8">
            <CompanyHeader :company="company" />

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Project</dt><dd class="font-medium">{{ project.project_name }} ({{ project.project_code }})</dd></div>
                <div><dt class="text-slate-500">BOQ</dt><dd class="font-medium">{{ purchaseRequest.boq_title || '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">BOQ item</dt><dd class="font-medium">{{ purchaseRequest.boq_item }}</dd></div>
                <div><dt class="text-slate-500">Request date</dt><dd>{{ purchaseRequest.request_date }}</dd></div>
                <div><dt class="text-slate-500">Required date</dt><dd>{{ purchaseRequest.required_date || '—' }}</dd></div>
                <div><dt class="text-slate-500">Requested by</dt><dd>{{ purchaseRequest.requested_by }}</dd></div>
                <div><dt class="text-slate-500">Status</dt><dd class="capitalize">{{ purchaseRequest.status }}</dd></div>
            </dl>

            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Sr No</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Item Name</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Description</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Service Code</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Unit</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Qty</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in purchaseRequest.items" :key="index" :class="line.quantity_check?.is_exceeded ? 'bg-orange-50' : ''">
                            <td class="border border-slate-200 px-3 py-2">{{ index + 1 }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.name || line.description }}</td>
                            <td class="border border-slate-200 px-3 py-2">
                                <p>{{ line.description }}</p>
                                <p v-if="line.boq_item_id" class="mt-1 text-xs text-slate-500">BOQ tag: {{ line.boq_label }}</p>
                                <p v-if="line.quantity_check?.is_exceeded" class="mt-1 text-xs font-semibold text-orange-800">BOQ QUANTITY EXCEEDED — APPROVAL REQUIRED</p>
                            </td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.service_code || '—' }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.unit || '—' }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ line.quantity }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.remarks || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-slate-500">{{ purchaseRequest.item_count || purchaseRequest.items.length }} item(s)</p>

            <section v-if="purchaseRequest.purchase_orders?.length" class="text-sm">
                <h2 class="font-semibold">Related purchase orders</h2>
                <ul class="mt-2 space-y-1">
                    <li v-for="order in purchaseRequest.purchase_orders" :key="order.id">
                        <Link :href="`/projects/${project.id}/purchase-orders/${order.id}`" class="text-amber-700 hover:underline">{{ order.po_number }}</Link>
                        <span class="capitalize text-slate-500"> {{ order.status }}</span>
                    </li>
                </ul>
            </section>

            <section
                v-if="control"
                class="rounded-2xl border p-5 text-sm"
                :class="control.is_exceeded ? 'border-orange-200 bg-orange-50' : 'border-emerald-100 bg-emerald-50/60'"
            >
                <h2 class="font-semibold" :class="control.is_exceeded ? 'text-orange-900' : 'text-slate-800'">
                    {{ control.is_exceeded ? 'BOQ Quantity Exceeded' : 'Within BOQ Quantity' }}
                </h2>
                <p v-if="!control.has_quantity" class="mt-2 text-orange-800">This BOQ item does not have a quantity defined.</p>
                <dl v-else class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div><dt class="text-slate-500">BOQ Quantity</dt><dd class="font-medium tabular-nums">{{ formatQty(control.boq_quantity, unit) }}</dd></div>
                    <div><dt class="text-slate-500">Previously Requested</dt><dd class="font-medium tabular-nums">{{ formatQty(control.previous_requested_quantity, unit) }}</dd></div>
                    <div><dt class="text-slate-500">This PR</dt><dd class="font-medium tabular-nums">{{ formatQty(control.requested_quantity, unit) }}</dd></div>
                    <div><dt class="text-slate-500">After This PR</dt><dd class="font-medium tabular-nums">{{ formatQty(control.cumulative_quantity, unit) }}</dd></div>
                    <div><dt class="text-slate-500">Excess</dt><dd class="font-semibold tabular-nums">{{ formatQty(control.excess_quantity, unit) }}</dd></div>
                </dl>
                <p v-if="control.is_exceeded" class="mt-3 text-orange-900">
                    Requested cumulative quantity is {{ formatQty(control.cumulative_quantity, unit) }} against BOQ quantity of {{ formatQty(control.boq_quantity, unit) }}.
                    Excess Quantity: {{ formatQty(control.excess_quantity, unit) }}. This PR requires explicit approval.
                </p>
                <p v-if="control.released" class="mt-3 text-slate-600">Rejected requests do not consume BOQ quantity.</p>
                <p v-if="control.over_quantity_approval_note" class="mt-3">
                    Excess approved by {{ control.over_quantity_approved_by }} on {{ control.over_quantity_approved_at }}: {{ control.over_quantity_approval_note }}
                </p>
            </section>

            <p v-if="page.props.errors?.requested_boq_quantity" class="text-sm text-red-700">{{ page.props.errors.requested_boq_quantity }}</p>

            <p v-if="purchaseRequest.description" class="text-sm"><span class="font-medium">Description: </span>{{ purchaseRequest.description }}</p>

            <div v-if="purchaseRequest.approved_by || purchaseRequest.rejection_reason" class="rounded-md border border-slate-200 bg-stone-50 p-4 text-sm">
                <p v-if="purchaseRequest.approved_by">Approved by {{ purchaseRequest.approved_by }} on {{ purchaseRequest.approved_at }}</p>
                <p v-if="purchaseRequest.rejection_reason">Rejected by {{ purchaseRequest.rejected_by }} on {{ purchaseRequest.rejected_at }}: {{ purchaseRequest.rejection_reason }}</p>
            </div>

            <div class="flex flex-wrap gap-3 print:hidden">
                <TableActionIcon v-if="abilities.update" action="edit" :href="`/projects/${project.id}/purchase-requests/${purchaseRequest.id}/edit`" />
                <TableActionIcon v-if="abilities.delete" action="delete" @click="destroy" />
                <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="submit">Submit</button>
                <button v-if="abilities.approve" type="button" class="ui-btn bg-emerald-600 text-white hover:bg-emerald-700" @click="approve">Approve</button>
                <button v-if="abilities.reject" type="button" class="ui-btn bg-red-600 text-white hover:bg-red-700" @click="rejecting = !rejecting">Reject</button>
                <button v-if="abilities.reopen" type="button" class="ui-btn-secondary" @click="reopen">Move to draft</button>
                <Link v-if="abilities.createPurchaseOrder" :href="`/projects/${project.id}/purchase-requests/${purchaseRequest.id}/purchase-orders/create`" class="ui-btn-primary">Create Purchase Order</Link>
            </div>

            <form v-if="rejecting && abilities.reject" class="space-y-3 print:hidden" @submit.prevent="reject">
                <label class="block text-sm">
                    <span class="font-medium">Rejection reason</span>
                    <textarea v-model="reason" rows="3" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                    <span v-if="reasonError" class="mt-1 block text-red-700">{{ reasonError }}</span>
                </label>
                <button type="submit" class="ui-btn bg-red-600 text-white hover:bg-red-700">Confirm rejection</button>
            </form>

            <div v-if="approvingExcess" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/20 px-4 print:hidden">
                <form class="w-full max-w-lg rounded-2xl bg-white p-6" @submit.prevent="confirmExcessApproval">
                    <h2 class="text-base font-semibold">BOQ Quantity Exceeded</h2>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-500">BOQ Qty</dt><dd class="font-medium tabular-nums">{{ formatQty(control.boq_quantity, unit) }}</dd></div>
                        <div><dt class="text-slate-500">Previously Requested</dt><dd class="font-medium tabular-nums">{{ formatQty(control.previous_requested_quantity, unit) }}</dd></div>
                        <div><dt class="text-slate-500">This PR</dt><dd class="font-medium tabular-nums">{{ formatQty(control.requested_quantity, unit) }}</dd></div>
                        <div><dt class="text-slate-500">Cumulative</dt><dd class="font-medium tabular-nums">{{ formatQty(control.cumulative_quantity, unit) }}</dd></div>
                        <div class="col-span-2"><dt class="text-slate-500">Excess</dt><dd class="font-semibold tabular-nums text-orange-800">{{ formatQty(control.excess_quantity, unit) }}</dd></div>
                    </dl>
                    <label class="mt-4 block text-sm">
                        <span class="font-medium">Approval Note</span>
                        <textarea v-model="approvalNote" rows="3" required class="ui-textarea mt-1"></textarea>
                        <span v-if="noteError || page.props.errors?.over_quantity_approval_note" class="mt-1 block text-red-700">
                            {{ noteError || page.props.errors.over_quantity_approval_note }}
                        </span>
                    </label>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="ui-btn-secondary" @click="approvingExcess = false">Cancel</button>
                        <button type="submit" class="ui-btn-primary">Approve Excess & Approve PR</button>
                    </div>
                </form>
            </div>
        </article>
    </AdminLayout>
</template>
