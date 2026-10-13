<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, FileText } from '@lucide/vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr, formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    invoice: { type: Object, required: true },
    availableAdvance: { type: String, required: true },
    canSettle: { type: Boolean, required: true },
});

const advance = useForm({ amount: '', remarks: '' });

function applyAdvance() {
    advance.post(`/projects/${props.project.id}/purchase-invoices/${props.invoice.id}/advance`);
}
</script>

<template>
    <Head :title="invoice.invoice_number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="invoice.invoice_number" :eyebrow="project.project_name">
                <DocumentPdfActions
                    :view-url="`/projects/${project.id}/purchase-invoices/${invoice.id}/pdf`"
                    :download-url="`/projects/${project.id}/purchase-invoices/${invoice.id}/pdf/download`"
                />
                <Link v-if="canSettle" :href="`/vendors/${invoice.vendor_id}/payments/create?purchase_invoice_id=${invoice.id}&payment_type=invoice_payment`" class="ui-btn-primary">Record Payment</Link>
            </PageHeader>
        </template>
        <div class="ui-card mb-4 grid gap-3 p-5 text-sm md:grid-cols-3">
            <div><p class="text-slate-500">Vendor</p><p>{{ invoice.vendor }}</p></div>
            <div><p class="text-slate-500">PO Number</p><p>{{ invoice.po_number }}</p></div>
            <div><p class="text-slate-500">Project</p><p>{{ project.project_name }}</p></div>
            <div><p class="text-slate-500">Invoice Date</p><p>{{ invoice.invoice_date }}</p></div>
            <div><p class="text-slate-500">Invoice Total</p><p>{{ formatInr(invoice.total_amount) }}</p></div>
            <div><p class="text-slate-500">Paid</p><p>{{ formatInr(invoice.paid) }}</p></div>
            <div><p class="text-slate-500">Advance Applied</p><p>{{ formatInr(invoice.advance_applied) }}</p></div>
            <div><p class="text-slate-500">Outstanding</p><p class="font-semibold">{{ formatInr(invoice.outstanding) }}</p></div>
            <div><p class="text-slate-500">Status</p><p class="capitalize">{{ invoice.status.replaceAll('_', ' ') }}</p></div>
        </div>
        <div v-if="invoice.items?.length" class="ui-table-wrap mb-4 overflow-x-auto">
            <table class="ui-table min-w-[880px]">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th class="text-right">PO Qty</th>
                        <th class="text-right">Previously Invoiced</th>
                        <th class="text-right">Current Invoice Qty</th>
                        <th class="text-right">Rate</th>
                        <th class="text-right">Taxable Amount</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Line Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in invoice.items" :key="line.id">
                        <td>
                            {{ line.description }}
                            <span v-if="line.service_code" class="block text-xs text-slate-500">{{ line.service_code }}</span>
                        </td>
                        <td>{{ line.unit || '—' }}</td>
                        <td class="text-right tabular-nums">{{ formatQty(line.po_quantity) }}</td>
                        <td class="text-right tabular-nums">{{ formatQty(line.previously_invoiced) }}</td>
                        <td class="text-right tabular-nums">{{ formatQty(line.quantity) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.rate) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.taxable_amount) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(Number(line.cgst_amount) + Number(line.sgst_amount)) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.line_total) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="invoice.has_attachment" class="ui-card mb-4 flex flex-wrap items-center gap-3 p-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-500">
                <FileText class="h-5 w-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-slate-900">Invoice attachment</p>
                <p class="text-xs text-slate-500">{{ invoice.invoice_number }}</p>
            </div>
            <a :href="`/projects/${project.id}/purchase-invoices/${invoice.id}/attachment`" target="_blank" class="ui-btn-secondary">View</a>
            <a :href="`/projects/${project.id}/purchase-invoices/${invoice.id}/attachment`" download class="ui-btn-secondary">
                <Download class="h-4 w-4" />
                Download
            </a>
        </div>
        <form v-if="canSettle && Number(availableAdvance) > 0" class="ui-card max-w-lg space-y-3 p-5" @submit.prevent="applyAdvance">
            <h2 class="font-semibold">Apply Vendor Advance</h2>
            <p class="text-sm text-slate-500">Available advance {{ formatInr(availableAdvance) }}. Outstanding {{ formatInr(invoice.outstanding) }}.</p>
            <input v-model="advance.amount" type="number" min="0" step="0.01" required class="ui-input" placeholder="Amount to adjust">
            <textarea v-model="advance.remarks" rows="2" class="ui-textarea" placeholder="Remarks"></textarea>
            <p v-if="advance.errors.amount" class="text-sm text-red-700">{{ advance.errors.amount }}</p>
            <button class="ui-btn-primary">Apply Vendor Advance</button>
        </form>
    </AdminLayout>
</template>
