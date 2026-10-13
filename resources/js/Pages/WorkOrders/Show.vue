<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import CompanyHeader from '../../Components/CompanyHeader.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatInr, formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    company: { type: Object, default: null },
    workOrder: { type: Object, required: true },
    history: { type: Array, required: true },
    abilities: { type: Object, required: true },
});

const base = `/projects/${props.project.id}/work-orders/${props.workOrder.id}`;
const approvalRemarks = ref('');
const rejectionReason = ref('');
const cancellationReason = ref('');
const execution = useForm({
    execution_remarks: '',
    lines: props.workOrder.items.map((line) => ({
        id: line.id,
        executed_quantity: line.executed_quantity,
        execution_status: line.execution_status,
    })),
});

function post(path, payload = {}) {
    router.post(`${base}/${path}`, payload);
}

function saveExecution() {
    execution.post(`${base}/execute`);
}

const actionLabels = {
    'work_order.created': 'Created',
    'work_order.updated': 'Updated',
    'work_order.submitted': 'Submitted',
    'work_order.approved': 'Approved',
    'work_order.rejected': 'Rejected',
    'work_order.issued': 'Issued',
    'work_order.cancelled': 'Cancelled',
    'work_order.amended': 'Amendment started',
    'work_order.superseded': 'Superseded',
    'work_order.execution_updated': 'Execution updated',
};
</script>

<template>
    <Head :title="workOrder.work_order_number" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <PageHeader :title="workOrder.work_order_number" :eyebrow="project.project_name" />
                    <div class="mt-2 flex flex-wrap gap-2">
                        <StatusBadge :status="workOrder.status" :label="workOrder.status_label" />
                        <StatusBadge :status="workOrder.work_status" :label="workOrder.work_label" />
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <DocumentPdfActions v-if="abilities.print" :view-url="`${base}/pdf`" :download-url="`${base}/pdf/download`" />
                    <Link v-if="abilities.edit" :href="`${base}/edit`" class="ui-btn-secondary">Edit</Link>
                    <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="post('submit')">Submit</button>
                    <button v-if="abilities.amend" type="button" class="ui-btn-secondary" @click="post('amend')">Amend</button>
                </div>
            </div>
        </template>

        <CompanyHeader :company="company" />

        <section class="ui-card mt-6 p-6">
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-slate-500">Work Order Date</dt><dd class="font-medium">{{ workOrder.work_order_date }}</dd></div>
                <div><dt class="text-slate-500">Completion</dt><dd class="font-medium">{{ workOrder.completion_date || '—' }}</dd></div>
                <div><dt class="text-slate-500">Contractor</dt><dd class="font-medium">{{ workOrder.contractor_code }} — {{ workOrder.contractor_name }}</dd></div>
                <div><dt class="text-slate-500">Contact</dt><dd>{{ workOrder.contractor_contact || '—' }}</dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd>{{ workOrder.contractor_phone || '—' }}</dd></div>
                <div><dt class="text-slate-500">Email</dt><dd>{{ workOrder.contractor_email || '—' }}</dd></div>
                <div><dt class="text-slate-500">GST</dt><dd>{{ workOrder.contractor_gst || '—' }}</dd></div>
                <div><dt class="text-slate-500">Amended From</dt><dd>{{ workOrder.amended_from || '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Address</dt><dd>{{ workOrder.contractor_address || '—' }}</dd></div>
            </dl>
            <p v-if="workOrder.is_quantity_exceeded" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                One or more lines exceed the remaining BOQ quantity. An authorized approval with remarks is required before issue.
            </p>
        </section>

        <section class="mt-6">
            <div class="ui-table-wrap">
                <table class="ui-table min-w-[1100px]">
                    <thead>
                        <tr>
                            <th class="px-3 py-3">#</th>
                            <th class="px-3 py-3">BOQ Item</th>
                            <th class="px-3 py-3">Code</th>
                            <th class="px-3 py-3">Unit</th>
                            <th class="px-3 py-3 text-right">BOQ Qty</th>
                            <th class="px-3 py-3 text-right">Allocated</th>
                            <th class="px-3 py-3 text-right">This WO</th>
                            <th class="px-3 py-3 text-right">Remaining</th>
                            <th class="px-3 py-3 text-right">Rate</th>
                            <th class="px-3 py-3 text-right">Amount</th>
                            <th class="px-3 py-3 text-right">Executed</th>
                            <th class="px-3 py-3">Execution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in workOrder.items" :key="line.id" :class="line.is_exceeded ? 'bg-red-50' : 'odd:bg-white even:bg-stone-50'">
                            <td class="px-3 py-3">{{ index + 1 }}</td>
                            <td class="px-3 py-3">
                                <div class="font-medium">{{ line.description }}</div>
                                <div class="text-xs text-slate-500">{{ line.boq_number }}<span v-if="line.revision_number !== null"> · REV {{ line.revision_number }}</span></div>
                            </td>
                            <td class="px-3 py-3">{{ line.service_code || line.item_number || '—' }}</td>
                            <td class="px-3 py-3">{{ line.unit || '—' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatQty(line.boq_quantity) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatQty(line.previously_allocated) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatQty(line.quantity) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                {{ formatQty(line.remaining_quantity) }}
                                <p v-if="line.is_exceeded" class="text-xs font-medium text-red-700">Excess {{ formatQty(line.excess_quantity) }}</p>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatInr(line.rate) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatInr(line.amount) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums">{{ formatQty(line.executed_quantity) }}</td>
                            <td class="px-3 py-3 capitalize">{{ line.execution_status.replaceAll('_', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <dl class="mt-4 grid justify-end gap-1 text-sm">
                <div class="flex justify-between gap-8"><dt>Subtotal</dt><dd class="tabular-nums">{{ formatInr(workOrder.subtotal) }}</dd></div>
                <div class="flex justify-between gap-8"><dt>GST {{ workOrder.gst_percentage }}%</dt><dd class="tabular-nums">{{ formatInr(workOrder.gst_amount) }}</dd></div>
                <div class="flex justify-between gap-8 font-semibold"><dt>Grand Total</dt><dd class="tabular-nums">{{ formatInr(workOrder.grand_total) }}</dd></div>
            </dl>
        </section>

        <section class="ui-card mt-6 grid gap-4 p-6 text-sm md:grid-cols-2">
            <div><h2 class="font-semibold">Scope of work</h2><p class="mt-2 whitespace-pre-line text-slate-700">{{ workOrder.scope || '—' }}</p></div>
            <div><h2 class="font-semibold">Payment terms</h2><p class="mt-2 whitespace-pre-line text-slate-700">{{ workOrder.payment_terms || '—' }}</p></div>
            <div class="md:col-span-2"><h2 class="font-semibold">Terms and conditions</h2><p class="mt-2 whitespace-pre-line text-slate-700">{{ workOrder.terms_conditions || '—' }}</p></div>
            <div>
                <h2 class="font-semibold">Approval</h2>
                <p class="mt-2 text-slate-700">{{ workOrder.approved_by ? `${workOrder.approved_by} · ${workOrder.approved_at}` : 'Not approved' }}</p>
                <p v-if="workOrder.approval_remarks" class="mt-1 text-slate-700">{{ workOrder.approval_remarks }}</p>
            </div>
            <div>
                <h2 class="font-semibold">Contractor acceptance</h2>
                <p class="mt-8 border-t border-slate-300 pt-2 text-slate-500">Signature and date</p>
            </div>
        </section>

        <section v-if="abilities.approve || abilities.reject || abilities.issue || abilities.cancel" class="ui-card mt-6 grid gap-4 p-6 md:grid-cols-2">
            <form v-if="abilities.approve" class="space-y-2" @submit.prevent="post('approve', { approval_remarks: approvalRemarks })">
                <label class="text-sm">Approval remarks<textarea v-model="approvalRemarks" rows="2" class="ui-textarea"></textarea></label>
                <button class="ui-btn-primary">Approve</button>
            </form>
            <form v-if="abilities.issue" class="space-y-2" @submit.prevent="post('issue', { approval_remarks: approvalRemarks })">
                <p class="text-sm text-slate-600">Issue the approved work order to the contractor. Item quantities, rates, and amounts stay as approved.</p>
                <button class="ui-btn-primary">Issue to contractor</button>
            </form>
            <form v-if="abilities.reject" class="space-y-2" @submit.prevent="post('reject', { rejection_reason: rejectionReason })">
                <label class="text-sm">Rejection reason<textarea v-model="rejectionReason" rows="2" class="ui-textarea"></textarea></label>
                <button class="ui-btn-secondary">Reject</button>
            </form>
            <form v-if="abilities.cancel" class="space-y-2" @submit.prevent="post('cancel', { cancellation_reason: cancellationReason })">
                <label class="text-sm">Cancellation reason<textarea v-model="cancellationReason" rows="2" class="ui-textarea"></textarea></label>
                <button class="ui-btn-secondary">Cancel work order</button>
            </form>
        </section>

        <section v-if="abilities.execute" class="ui-card mt-6 p-6">
            <h2 class="text-lg font-semibold">Execution</h2>
            <form class="mt-4 space-y-3" @submit.prevent="saveExecution">
                <div v-for="(line, index) in execution.lines" :key="line.id" class="grid gap-3 md:grid-cols-3">
                    <p class="text-sm font-medium">{{ workOrder.items[index].description }}</p>
                    <label class="text-sm">Executed quantity
                        <input v-model="line.executed_quantity" type="number" min="0" step="0.001" class="ui-input">
                    </label>
                    <label class="text-sm">Status
                        <select v-model="line.execution_status" class="ui-input">
                            <option value="not_started">Not Started</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </label>
                </div>
                <label class="block text-sm">Remarks if executed quantity exceeds the work order quantity
                    <textarea v-model="execution.execution_remarks" rows="2" class="ui-textarea"></textarea>
                </label>
                <p v-if="execution.errors.execution_remarks" class="text-sm text-red-600">{{ execution.errors.execution_remarks }}</p>
                <button class="ui-btn-primary" :disabled="execution.processing">Update execution</button>
            </form>
        </section>

        <section class="mt-6">
            <h2 class="mb-3 text-lg font-semibold">History</h2>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th class="px-4 py-3">When</th>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">User</th>
                            <th class="px-4 py-3">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="history.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-slate-500">No history yet.</td>
                        </tr>
                        <tr v-for="entry in history" :key="entry.id">
                            <td class="px-4 py-3">{{ entry.created_at }}</td>
                            <td class="px-4 py-3">{{ actionLabels[entry.action] || entry.action }}</td>
                            <td class="px-4 py-3">{{ entry.user || '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ entry.properties?.remarks || entry.properties?.reason || entry.properties?.amendment_number || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
