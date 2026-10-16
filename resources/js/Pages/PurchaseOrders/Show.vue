<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import CompanyHeader from '../../Components/CompanyHeader.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    company: { type: Object, default: null },
    purchaseOrder: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const rejecting = ref(false);
const reason = ref('');
const reasonError = ref('');

const amendmentForm = useForm({
    purchase_order_item_id: '',
    proposed_rate: '',
    reason: '',
});

const canViewRates = computed(() => props.abilities?.view_rates !== false);
const moneyOrHidden = (value) => (canViewRates.value ? formatInr(value) : '—');

const pendingAmendments = computed(() => props.purchaseOrder.pending_rate_amendments ?? []);

const amendableItems = computed(() =>
    (props.purchaseOrder.items ?? []).filter(
        (line) => !pendingAmendments.value.some((row) => row.purchase_order_item_id === line.id),
    ),
);

function submitRateAmendment() {
    amendmentForm.post(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/rate-amendments`, {
        preserveScroll: true,
        onSuccess: () => amendmentForm.reset('proposed_rate', 'reason'),
    });
}

function approveAmendment(amendmentId) {
    if (!confirm('Approve this rate amendment?')) {
        return;
    }

    router.post(
        `/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/rate-amendments/${amendmentId}/approve`,
        {},
        { preserveScroll: true },
    );
}

function rejectAmendment(amendmentId) {
    const comment = window.prompt('Optional rejection comment');
    if (comment === null) {
        return;
    }

    router.post(
        `/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/rate-amendments/${amendmentId}/reject`,
        { decision_comment: comment },
        { preserveScroll: true },
    );
}

function submit() {
    if (!confirm('Submit this purchase order?')) {
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/submit`);
}

function approve() {
    if (!confirm('Approve this purchase order?')) {
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/approve`);
}

function reject() {
    reasonError.value = '';

    if (!reason.value.trim()) {
        reasonError.value = 'Rejection reason is required.';
        return;
    }

    router.post(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/reject`, {
        rejection_reason: reason.value,
    });
}

function reopen() {
    router.post(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}/reopen`);
}

function destroy() {
    if (!confirm('Delete this draft purchase order?')) {
        return;
    }

    router.delete(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}`);
}
</script>

<template>
    <Head :title="purchaseOrder.po_number" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">{{ purchaseOrder.po_number }}</h1>
                    <p v-if="purchaseOrder.subject" class="text-sm text-slate-600">{{ purchaseOrder.subject }}</p>
                    <p class="text-sm text-slate-500">Purchase order</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <DocumentPdfActions
                        :view-url="`/projects/${project.id}/purchase-orders/${purchaseOrder.id}/pdf`"
                        :download-url="`/projects/${project.id}/purchase-orders/${purchaseOrder.id}/pdf/download`"
                    />
                    <Link v-if="purchaseOrder.status === 'approved'" :href="`/projects/${project.id}/purchase-orders/${purchaseOrder.id}/grns/create`" class="ui-btn-primary">Create GRN / Receive Material</Link>
                    <StatusBadge :status="purchaseOrder.status" />
                    <span v-if="purchaseOrder.receipt_status" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ purchaseOrder.receipt_status }}</span>
                </div>
            </div>
        </template>

        <article class="ui-card space-y-6 p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <CompanyHeader :company="company" />
                <div class="text-sm">
                    <p class="text-lg font-semibold">{{ purchaseOrder.po_number }}</p>
                    <p>Date: {{ purchaseOrder.po_date }}</p>
                </div>
            </div>

            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Project</dt><dd class="font-medium">{{ project.project_name }} ({{ project.project_code }})</dd></div>
                <div><dt class="text-slate-500">PR number</dt><dd><Link :href="`/projects/${project.id}/purchase-requests/${purchaseOrder.purchase_request_id}`" class="font-medium text-amber-700 hover:underline">{{ purchaseOrder.pr_number }}</Link></dd></div>
                <div><dt class="text-slate-500">Requested BOQ Qty</dt><dd class="tabular-nums">{{ purchaseOrder.requested_boq_quantity === null ? '—' : `${Number(purchaseOrder.requested_boq_quantity).toLocaleString('en-IN', { maximumFractionDigits: 3 })}${purchaseOrder.boq_unit ? ` ${purchaseOrder.boq_unit}` : ''}` }}</dd></div>
                <div><dt class="text-slate-500">BOQ</dt><dd>{{ purchaseOrder.boq_title }}</dd></div>
                <div><dt class="text-slate-500">BOQ item</dt><dd>{{ purchaseOrder.boq_item }}</dd></div>
            </dl>

            <section class="text-sm">
                <h2 class="font-semibold">Supplier</h2>
                <p class="mt-1 font-medium">{{ purchaseOrder.supplier_name }}</p>
                <p v-if="purchaseOrder.supplier_address" class="whitespace-pre-line">{{ purchaseOrder.supplier_address }}</p>
                <p v-if="purchaseOrder.supplier_phone">Phone: {{ purchaseOrder.supplier_phone }}</p>
                <p v-if="purchaseOrder.supplier_email">{{ purchaseOrder.supplier_email }}</p>
                <p v-if="purchaseOrder.gst_number">GST: {{ purchaseOrder.gst_number }}</p>
            </section>

            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Sr. No.</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Service Code</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Item Description</th>
                            <th class="px-3 py-2 font-medium text-slate-500">HSN/SAC</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Unit</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Qty</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Rate</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Disc %</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Taxable</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">CGST</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">SGST</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in purchaseOrder.items" :key="index">
                            <td class="border border-slate-200 px-3 py-2">{{ index + 1 }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.service_code || '—' }}</td>
                            <td class="border border-slate-200 px-3 py-2 whitespace-pre-line">{{ line.description }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.hsn_sac || '—' }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.unit || '—' }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ line.quantity }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ moneyOrHidden(line.rate) }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ line.discount_percentage }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ formatInr(line.taxable_amount) }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ formatInr(line.cgst_amount) }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ formatInr(line.sgst_amount) }}</td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ formatInr(line.line_total || line.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <dl class="grid gap-2 text-sm sm:ml-auto sm:w-80">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.subtotal) }}</dd></div>
                <div class="flex justify-between"><dt>Discount</dt><dd class="tabular-nums">{{ formatInr(Number(purchaseOrder.discount_total) ? -purchaseOrder.discount_total : 0) }}</dd></div>
                <div class="flex justify-between"><dt>Taxable value</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.taxable_value) }}</dd></div>
                <div class="flex justify-between"><dt>CGST @ {{ purchaseOrder.cgst_percentage }}%</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.cgst_amount) }}</dd></div>
                <div class="flex justify-between"><dt>SGST @ {{ purchaseOrder.sgst_percentage }}%</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.sgst_amount) }}</dd></div>
                <div class="flex justify-between"><dt>Freight</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.freight) }}</dd></div>
                <div class="flex justify-between"><dt>Round off</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.round_off) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Grand total</dt><dd class="tabular-nums">{{ formatInr(purchaseOrder.grand_total) }}</dd></div>
            </dl>

            <section class="text-sm">
                <h2 class="font-semibold">Delivery</h2>
                <p class="mt-1">Date: {{ purchaseOrder.delivery_date || '—' }}</p>
                <p class="whitespace-pre-line">{{ purchaseOrder.delivery_address || '—' }}</p>
                <p v-if="purchaseOrder.place_of_supply">Place of supply: {{ purchaseOrder.place_of_supply }}</p>
            </section>
            <p v-if="purchaseOrder.terms_conditions" class="whitespace-pre-line text-sm"><span class="font-medium">Terms &amp; conditions: </span>{{ purchaseOrder.terms_conditions }}</p>
            <p v-if="purchaseOrder.remarks" class="text-sm"><span class="font-medium">Remarks: </span>{{ purchaseOrder.remarks }}</p>

            <section
                v-if="abilities.view_rate_history || pendingAmendments.length || abilities.request_rate_amendment"
                class="space-y-4 rounded-md border border-slate-200 bg-stone-50 p-4 text-sm print:hidden"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Rate amendments</h2>
                    <Link
                        v-if="abilities.view_rate_history"
                        :href="`/projects/${project.id}/purchase-orders/${purchaseOrder.id}/rate-history`"
                        class="text-amber-700 hover:underline"
                    >
                        View rate history
                    </Link>
                </div>

                <div v-if="pendingAmendments.length" class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr>
                                <th class="px-2 py-1 text-left font-medium text-slate-500">Line</th>
                                <th class="px-2 py-1 text-right font-medium text-slate-500">Current</th>
                                <th class="px-2 py-1 text-right font-medium text-slate-500">Proposed</th>
                                <th class="px-2 py-1 text-left font-medium text-slate-500">Reason</th>
                                <th class="px-2 py-1 text-left font-medium text-slate-500">Requested</th>
                                <th v-if="abilities.approve_rate_amendment || abilities.reject_rate_amendment" class="px-2 py-1 text-left font-medium text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in pendingAmendments" :key="row.id">
                                <td class="border border-slate-200 px-2 py-1">{{ row.item_description || '—' }}</td>
                                <td class="border border-slate-200 px-2 py-1 text-right tabular-nums">{{ formatInr(row.previous_rate) }}</td>
                                <td class="border border-slate-200 px-2 py-1 text-right tabular-nums">{{ formatInr(row.proposed_rate) }}</td>
                                <td class="border border-slate-200 px-2 py-1">{{ row.reason }}</td>
                                <td class="border border-slate-200 px-2 py-1 text-xs text-slate-600">
                                    {{ row.requested_by || '—' }}<br />{{ row.requested_at }}
                                </td>
                                <td v-if="abilities.approve_rate_amendment || abilities.reject_rate_amendment" class="border border-slate-200 px-2 py-1">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-if="abilities.approve_rate_amendment"
                                            type="button"
                                            class="rounded bg-emerald-600 px-2 py-1 text-xs text-white hover:bg-emerald-700"
                                            @click="approveAmendment(row.id)"
                                        >
                                            Approve
                                        </button>
                                        <button
                                            v-if="abilities.reject_rate_amendment"
                                            type="button"
                                            class="rounded bg-red-600 px-2 py-1 text-xs text-white hover:bg-red-700"
                                            @click="rejectAmendment(row.id)"
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-slate-600">No pending rate amendments.</p>

                <form v-if="abilities.request_rate_amendment && amendableItems.length" class="grid gap-3 sm:grid-cols-2" @submit.prevent="submitRateAmendment">
                    <label class="block sm:col-span-2">
                        <span class="font-medium">Line</span>
                        <select v-model="amendmentForm.purchase_order_item_id" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2">
                            <option value="" disabled>Select a line</option>
                            <option v-for="line in amendableItems" :key="line.id" :value="line.id">
                                {{ line.description }} ({{ formatInr(line.rate) }})
                            </option>
                        </select>
                        <span v-if="amendmentForm.errors.purchase_order_item_id" class="mt-1 block text-red-700">{{ amendmentForm.errors.purchase_order_item_id }}</span>
                    </label>
                    <label class="block">
                        <span class="font-medium">New rate</span>
                        <input v-model="amendmentForm.proposed_rate" type="number" step="0.01" min="0" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 tabular-nums" />
                        <span v-if="amendmentForm.errors.proposed_rate" class="mt-1 block text-red-700">{{ amendmentForm.errors.proposed_rate }}</span>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="font-medium">Reason</span>
                        <textarea v-model="amendmentForm.reason" rows="2" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                        <span v-if="amendmentForm.errors.reason" class="mt-1 block text-red-700">{{ amendmentForm.errors.reason }}</span>
                    </label>
                    <div class="sm:col-span-2">
                        <button type="submit" class="ui-btn-primary" :disabled="amendmentForm.processing">Request rate amendment</button>
                    </div>
                </form>
            </section>

            <div v-if="purchaseOrder.approved_by || purchaseOrder.rejection_reason" class="rounded-md border border-slate-200 bg-stone-50 p-4 text-sm">
                <p v-if="purchaseOrder.approved_by">Approved by {{ purchaseOrder.approved_by }} on {{ purchaseOrder.approved_at }}</p>
                <p v-if="purchaseOrder.rejection_reason">Rejected by {{ purchaseOrder.rejected_by }} on {{ purchaseOrder.rejected_at }}: {{ purchaseOrder.rejection_reason }}</p>
            </div>

            <div class="flex flex-wrap gap-3 print:hidden">
                <Link v-if="abilities.update" :href="`/projects/${project.id}/purchase-orders/${purchaseOrder.id}/edit`" class="ui-btn-secondary">Edit</Link>
                <button v-if="abilities.delete" type="button" class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700" @click="destroy">Delete</button>
                <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="submit">Submit</button>
                <button v-if="abilities.approve" type="button" class="ui-btn bg-emerald-600 text-white hover:bg-emerald-700" @click="approve">Approve</button>
                <button v-if="abilities.reject" type="button" class="ui-btn bg-red-600 text-white hover:bg-red-700" @click="rejecting = !rejecting">Reject</button>
                <button v-if="abilities.reopen" type="button" class="ui-btn-secondary" @click="reopen">Move to draft</button>
            </div>

            <form v-if="rejecting && abilities.reject" class="space-y-3 print:hidden" @submit.prevent="reject">
                <label class="block text-sm">
                    <span class="font-medium">Rejection reason</span>
                    <textarea v-model="reason" rows="3" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"></textarea>
                    <span v-if="reasonError" class="mt-1 block text-red-700">{{ reasonError }}</span>
                </label>
                <button type="submit" class="ui-btn bg-red-600 text-white hover:bg-red-700">Confirm rejection</button>
            </form>
        </article>
    </AdminLayout>
</template>
