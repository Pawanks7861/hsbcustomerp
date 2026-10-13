<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    vendor: { type: Object, required: true },
    projects: { type: Array, required: true },
    purchaseOrders: { type: Array, required: true },
    invoices: { type: Array, required: true },
    invoice: { type: Object, default: null },
    preset: { type: Object, required: true },
});

const form = useForm({
    project_id: props.preset.project_id || '',
    purchase_order_id: props.preset.purchase_order_id || '',
    purchase_invoice_id: props.preset.purchase_invoice_id || '',
    payment_date: new Date().toISOString().slice(0, 10),
    payment_type: props.preset.payment_type || 'advance',
    payment_mode: 'bank_transfer',
    amount: '',
    reference_number: '',
    remarks: '',
});

const selectedInvoice = computed(() => props.invoice || props.invoices.find((row) => String(row.id) === String(form.purchase_invoice_id)) || null);

function submit() {
    form.post(`/vendors/${props.vendor.id}/payments`);
}
</script>

<template>
    <Head title="Record Payment" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Record Payment" :eyebrow="vendor.name" />
        </template>
        <form class="ui-card grid max-w-3xl gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
            <label class="text-sm">Payment Type
                <select v-model="form.payment_type" class="ui-input">
                    <option value="advance">Advance</option>
                    <option value="invoice_payment">Invoice Payment</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <label class="text-sm">Payment Mode
                <select v-model="form.payment_mode" class="ui-input">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="upi">UPI</option>
                    <option value="card">Card</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <label class="text-sm">Project
                <select v-model="form.project_id" class="ui-input"><option value="">Optional</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option></select>
            </label>
            <label class="text-sm">Purchase Order
                <select v-model="form.purchase_order_id" class="ui-input"><option value="">Optional</option><option v-for="order in purchaseOrders" :key="order.id" :value="order.id">{{ order.po_number }}</option></select>
            </label>
            <label v-if="form.payment_type === 'invoice_payment'" class="text-sm md:col-span-2">Purchase Invoice
                <select v-model="form.purchase_invoice_id" required class="ui-input"><option value="">Select invoice</option><option v-for="row in invoices" :key="row.id" :value="row.id">{{ row.invoice_number }}</option></select>
            </label>
            <div v-if="selectedInvoice && form.payment_type === 'invoice_payment'" class="rounded-xl bg-slate-50 p-4 text-sm md:col-span-2">
                <p>Invoice total {{ formatInr(selectedInvoice.total_amount) }}</p>
                <p v-if="invoice">Already paid {{ formatInr(invoice.paid) }} · Advance applied {{ formatInr(invoice.advance_applied) }} · Outstanding {{ formatInr(invoice.outstanding) }}</p>
            </div>
            <label class="text-sm">Date<input v-model="form.payment_date" type="date" required class="ui-input"></label>
            <label class="text-sm">Amount<input v-model="form.amount" type="number" min="0" step="0.01" required class="ui-input"></label>
            <label class="text-sm">Reference<input v-model="form.reference_number" class="ui-input"></label>
            <label class="text-sm md:col-span-2">Remarks<textarea v-model="form.remarks" rows="2" class="ui-textarea"></textarea></label>
            <p v-if="form.errors.amount" class="text-sm text-red-700 md:col-span-2">{{ form.errors.amount }}</p>
            <button class="ui-btn-primary md:col-span-2" :disabled="form.processing">Save Payment</button>
        </form>
    </AdminLayout>
</template>
