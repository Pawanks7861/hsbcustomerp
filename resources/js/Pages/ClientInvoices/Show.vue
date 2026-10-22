<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr, formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    invoice: { type: Object, required: true },
    paymentModes: { type: Array, required: true },
    abilities: { type: Object, default: () => ({}) },
});

const rejectForm = useForm({ rejection_reason: '' });
const paymentForm = useForm({
    payment_date: new Date().toISOString().slice(0, 10),
    payment_mode: 'bank_transfer',
    amount: '',
    reference_number: '',
    remarks: '',
});

function post(action) {
    rejectForm.post(`/projects/${props.project.id}/client-invoices/${props.invoice.id}/${action}`, {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset(),
    });
}

function pay() {
    paymentForm.post(`/projects/${props.project.id}/client-invoices/${props.invoice.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => paymentForm.reset('amount', 'reference_number', 'remarks'),
    });
}

function destroy() {
    if (!window.confirm('Delete this draft client invoice?')) {
        return;
    }
    router.delete(`/projects/${props.project.id}/client-invoices/${props.invoice.id}`);
}
</script>

<template>
    <Head :title="invoice.invoice_number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="invoice.invoice_number" :eyebrow="project.project_name">
                <DocumentPdfActions
                    v-if="abilities.print"
                    :view-url="`/projects/${project.id}/client-invoices/${invoice.id}/pdf`"
                    :download-url="`/projects/${project.id}/client-invoices/${invoice.id}/pdf/download`"
                />
                <Link v-if="abilities.edit" :href="`/projects/${project.id}/client-invoices/${invoice.id}/edit`" class="ui-btn-secondary">Edit</Link>
                <button v-if="abilities.delete" type="button" class="ui-btn-secondary" @click="destroy">Delete</button>
            </PageHeader>
        </template>
        <div class="ui-card mb-4 grid gap-3 p-5 text-sm md:grid-cols-3">
            <div><p class="text-slate-500">Client</p><p>{{ invoice.client_name }}</p></div>
            <div><p class="text-slate-500">Invoice date</p><p>{{ invoice.invoice_date }}</p></div>
            <div><p class="text-slate-500">Due date</p><p>{{ invoice.due_date || '—' }}</p></div>
            <div><p class="text-slate-500">GSTIN</p><p>{{ invoice.client_gst || '—' }}</p></div>
            <div><p class="text-slate-500">Place of supply</p><p>{{ invoice.place_of_supply || '—' }}</p></div>
            <div><p class="text-slate-500">Reference</p><p>{{ invoice.reference_number || '—' }}</p></div>
            <div><p class="text-slate-500">Invoice total</p><p>{{ formatInr(invoice.grand_total) }}</p></div>
            <div><p class="text-slate-500">Received</p><p>{{ formatInr(invoice.paid) }}</p></div>
            <div><p class="text-slate-500">Outstanding</p><p class="font-semibold">{{ formatInr(invoice.outstanding) }}</p></div>
            <div><p class="text-slate-500">Approval</p><p class="capitalize">{{ invoice.approval_status }}</p></div>
            <div><p class="text-slate-500">Payment</p><p class="capitalize">{{ invoice.payment_status.replaceAll('_', ' ') }}{{ invoice.overdue ? ' · overdue' : '' }}</p></div>
            <p v-if="invoice.approved_by" class="md:col-span-3">Approved by {{ invoice.approved_by }} on {{ invoice.approved_at }}</p>
            <p v-if="invoice.rejection_reason" class="md:col-span-3 text-rose-600">Rejected by {{ invoice.rejected_by }}: {{ invoice.rejection_reason }}</p>
        </div>
        <div class="mb-4 flex flex-wrap gap-2">
            <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="post('submit')">Submit for approval</button>
            <button v-if="abilities.approve" type="button" class="ui-btn-primary" @click="post('approve')">Approve</button>
        </div>
        <form v-if="abilities.reject" class="ui-card mb-4 max-w-lg space-y-3 p-4" @submit.prevent="post('reject')">
            <textarea v-model="rejectForm.rejection_reason" required placeholder="Rejection reason" class="ui-input w-full"></textarea>
            <button class="ui-btn-secondary">Reject</button>
        </form>
        <div class="ui-table-wrap mb-4 overflow-x-auto">
            <table class="ui-table min-w-[880px]">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Rate</th>
                        <th class="text-right">Taxable</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Line total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in invoice.items" :key="line.id">
                        <td>
                            {{ line.description }}
                            <span v-if="line.item_number" class="block text-xs text-slate-500">{{ line.item_number }}</span>
                        </td>
                        <td>{{ line.unit || '—' }}</td>
                        <td class="text-right tabular-nums">{{ formatQty(line.quantity) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.rate) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.taxable_amount) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(Number(line.cgst_amount) + Number(line.sgst_amount)) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.line_total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ui-card mb-4 max-w-md space-y-1 p-4 text-sm">
            <p class="flex justify-between"><span>Subtotal</span><span>{{ formatInr(invoice.subtotal) }}</span></p>
            <p class="flex justify-between"><span>Discount</span><span>{{ formatInr(invoice.discount_total) }}</span></p>
            <p class="flex justify-between"><span>Taxable</span><span>{{ formatInr(invoice.taxable_value) }}</span></p>
            <p class="flex justify-between"><span>GST {{ Number(invoice.gst_percentage) }}%</span><span>{{ formatInr(invoice.gst_amount) }}</span></p>
            <p class="flex justify-between"><span>Round off</span><span>{{ formatInr(invoice.round_off) }}</span></p>
            <p class="flex justify-between font-semibold"><span>Total</span><span>{{ formatInr(invoice.grand_total) }}</span></p>
        </div>
        <div v-if="invoice.payments.length" class="ui-table-wrap mb-4">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Receipt date</th>
                        <th>Mode</th>
                        <th>Reference</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="payment in invoice.payments" :key="payment.id">
                        <td>{{ payment.payment_date }}</td>
                        <td class="capitalize">{{ payment.payment_mode.replaceAll('_', ' ') }}</td>
                        <td>{{ payment.reference_number || '—' }}</td>
                        <td class="text-right">{{ formatInr(payment.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <form v-if="abilities.pay" class="ui-card max-w-lg space-y-3 p-5" @submit.prevent="pay">
            <h2 class="font-semibold">Record receipt</h2>
            <p class="text-sm text-slate-500">Outstanding {{ formatInr(invoice.outstanding) }}.</p>
            <input v-model="paymentForm.payment_date" type="date" required class="ui-input">
            <select v-model="paymentForm.payment_mode" class="ui-input">
                <option v-for="mode in paymentModes" :key="mode" :value="mode">{{ mode.replaceAll('_', ' ') }}</option>
            </select>
            <input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" required class="ui-input" placeholder="Amount received">
            <input v-model="paymentForm.reference_number" class="ui-input" placeholder="UTR / cheque / reference">
            <textarea v-model="paymentForm.remarks" rows="2" class="ui-textarea" placeholder="Remarks"></textarea>
            <p v-if="paymentForm.errors.amount" class="text-sm text-red-700">{{ paymentForm.errors.amount }}</p>
            <p v-if="paymentForm.errors.reference_number" class="text-sm text-red-700">{{ paymentForm.errors.reference_number }}</p>
            <button class="ui-btn-primary">Save receipt</button>
        </form>
    </AdminLayout>
</template>
