<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    note: { type: Object, default: null },
    vendors: { type: Array, required: true },
    purchaseOrders: { type: Array, required: true },
    invoices: { type: Array, required: true },
});

const form = useForm({
    date: props.note?.date || new Date().toISOString().slice(0, 10),
    vendor_id: props.note?.vendor_id || '',
    vendor_reference: props.note?.vendor_reference || '',
    purchase_order_id: props.note?.purchase_order_id || '',
    purchase_invoice_id: props.note?.purchase_invoice_id || '',
    reason: props.note?.reason || '',
    remarks: props.note?.remarks || '',
    subtotal: props.note?.subtotal || '',
    tax_amount: props.note?.tax_amount || '0',
    round_off: props.note?.round_off || '0',
});

const total = computed(() => (Number(form.subtotal || 0) + Number(form.tax_amount || 0) + Number(form.round_off || 0)).toFixed(2));
const filteredOrders = computed(() => props.purchaseOrders.filter((row) => !form.vendor_id || String(row.vendor_id) === String(form.vendor_id)));
const filteredInvoices = computed(() => props.invoices.filter((row) => !form.vendor_id || String(row.vendor_id) === String(form.vendor_id)));

watch(() => form.vendor_id, () => {
    form.purchase_order_id = '';
    form.purchase_invoice_id = '';
});

function submit() {
    const url = props.note
        ? `/projects/${props.project.id}/credit-notes/${props.note.id}`
        : `/projects/${props.project.id}/credit-notes`;
    props.note ? form.put(url) : form.post(url);
}
</script>

<template>
    <Head :title="note ? note.number : 'New Credit Note'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="note ? note.number : 'New Credit Note'" :eyebrow="project.project_name" />
        </template>
        <form class="ui-card max-w-3xl space-y-4 p-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm">Date<input v-model="form.date" type="date" required class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Vendor
                    <select v-model="form.vendor_id" required class="ui-input mt-1 w-full">
                        <option value="">Select vendor</option>
                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option>
                    </select>
                </label>
                <label class="text-sm md:col-span-2">Vendor reference<input v-model="form.vendor_reference" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Purchase Order
                    <select v-model="form.purchase_order_id" class="ui-input mt-1 w-full">
                        <option value="">Optional</option>
                        <option v-for="order in filteredOrders" :key="order.id" :value="order.id">{{ order.po_number }}</option>
                    </select>
                </label>
                <label class="text-sm">Invoice
                    <select v-model="form.purchase_invoice_id" class="ui-input mt-1 w-full">
                        <option value="">Optional</option>
                        <option v-for="invoice in filteredInvoices" :key="invoice.id" :value="invoice.id">{{ invoice.invoice_number }}</option>
                    </select>
                </label>
            </div>
            <label class="block text-sm">Reason<input v-model="form.reason" required class="ui-input mt-1 w-full"></label>
            <textarea v-model="form.remarks" rows="3" placeholder="Remarks" class="ui-input w-full"></textarea>
            <div class="grid gap-4 md:grid-cols-4">
                <label class="text-sm">Subtotal<input v-model="form.subtotal" type="number" min="0" step="0.01" required class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Tax<input v-model="form.tax_amount" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Round off<input v-model="form.round_off" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Total<p class="mt-2 font-semibold">{{ total }}</p></label>
            </div>
            <button class="ui-btn-primary" type="submit" :disabled="form.processing">Save</button>
        </form>
    </AdminLayout>
</template>
