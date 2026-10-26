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

function emptyLine() {
    return { purchase_order_item_id: '', description: '', unit: '', quantity: '', rate: '', remarks: '' };
}

const form = useForm({
    date: props.note?.date || new Date().toISOString().slice(0, 10),
    vendor_id: props.note?.vendor_id || '',
    purchase_order_id: props.note?.purchase_order_id || '',
    purchase_invoice_id: props.note?.purchase_invoice_id || '',
    reason: props.note?.reason || '',
    remarks: props.note?.remarks || '',
    subtotal: props.note?.subtotal || '',
    tax_amount: props.note?.tax_amount || '0',
    round_off: props.note?.round_off || '0',
    items: (props.note?.items?.length
        ? props.note.items.map((line) => ({
            purchase_order_item_id: line.purchase_order_item_id || '',
            description: line.description || '',
            unit: line.unit || '',
            quantity: line.quantity || '',
            rate: line.rate || '',
            remarks: line.remarks || '',
        }))
        : [emptyLine()]),
});

const selectedOrder = computed(() => props.purchaseOrders.find((row) => String(row.id) === String(form.purchase_order_id)) ?? null);
const itemMode = computed(() => form.items.some((line) => line.description || line.quantity || line.rate));
const itemsSubtotal = computed(() => form.items.reduce((sum, line) => {
    const amount = (Number(line.quantity) || 0) * (Number(line.rate) || 0);
    return Math.round((sum + amount + Number.EPSILON) * 100) / 100;
}, 0));
const subtotalValue = computed(() => (itemMode.value ? itemsSubtotal.value : Number(form.subtotal || 0)));
const total = computed(() => (subtotalValue.value + Number(form.tax_amount || 0) + Number(form.round_off || 0)).toFixed(2));
const filteredOrders = computed(() => purchaseOrdersFor(form.vendor_id));
const filteredInvoices = computed(() => invoicesFor(form.vendor_id));

function purchaseOrdersFor(vendorId) {
    return props.purchaseOrders.filter((row) => !vendorId || String(row.vendor_id) === String(vendorId));
}

function invoicesFor(vendorId) {
    return props.invoices.filter((row) => !vendorId || String(row.vendor_id) === String(vendorId));
}

watch(() => form.vendor_id, () => {
    form.purchase_order_id = '';
    form.purchase_invoice_id = '';
});

function applyPoLine(index) {
    const line = form.items[index];
    const match = selectedOrder.value?.items?.find((item) => String(item.id) === String(line.purchase_order_item_id));
    if (!match) {
        return;
    }
    line.description = match.description;
    line.unit = match.unit || '';
    if (!line.rate) {
        line.rate = match.rate;
    }
}

function addLine() {
    form.items.push(emptyLine());
}

function removeLine(index) {
    form.items.splice(index, 1);
    if (form.items.length === 0) {
        form.items.push(emptyLine());
    }
}

function submit() {
    const url = props.note
        ? `/projects/${props.project.id}/debit-notes/${props.note.id}`
        : `/projects/${props.project.id}/debit-notes`;
    if (itemMode.value) {
        form.subtotal = itemsSubtotal.value.toFixed(2);
    }
    props.note ? form.put(url) : form.post(url);
}
</script>

<template>
    <Head :title="note ? note.number : 'New Debit Note'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="note ? note.number : 'New Debit Note'" :eyebrow="project.project_name" />
        </template>
        <form class="ui-card max-w-5xl space-y-4 p-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm">Date<input v-model="form.date" type="date" required class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Vendor
                    <select v-model="form.vendor_id" required class="ui-input mt-1 w-full">
                        <option value="">Select vendor</option>
                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option>
                    </select>
                </label>
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
            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-2 py-1 text-left font-medium text-slate-500">PO line</th>
                            <th class="px-2 py-1 text-left font-medium text-slate-500">Description</th>
                            <th class="px-2 py-1 text-left font-medium text-slate-500">Unit</th>
                            <th class="px-2 py-1 text-right font-medium text-slate-500">Qty</th>
                            <th class="px-2 py-1 text-right font-medium text-slate-500">Rate</th>
                            <th class="px-2 py-1 text-right font-medium text-slate-500">Amount</th>
                            <th class="px-2 py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.items" :key="index">
                            <td class="border border-slate-200 p-1">
                                <select v-model="line.purchase_order_item_id" class="ui-input w-40" @change="applyPoLine(index)">
                                    <option value="">Amount-only / manual</option>
                                    <option v-for="item in selectedOrder?.items || []" :key="item.id" :value="item.id">{{ item.description }}</option>
                                </select>
                            </td>
                            <td class="border border-slate-200 p-1"><input v-model="line.description" class="ui-input w-full min-w-[14rem]"></td>
                            <td class="border border-slate-200 p-1"><input v-model="line.unit" class="ui-input unit-input"></td>
                            <td class="border border-slate-200 p-1"><input v-model="line.quantity" type="number" min="0" step="0.001" class="ui-input num-input"></td>
                            <td class="border border-slate-200 p-1"><input v-model="line.rate" type="number" min="0" step="0.01" class="ui-input num-input"></td>
                            <td class="num-cell border border-slate-200 p-1">{{ ((Number(line.quantity) || 0) * (Number(line.rate) || 0)).toFixed(2) }}</td>
                            <td class="border border-slate-200 p-1"><button type="button" class="text-xs text-red-700" @click="removeLine(index)">Remove</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="text-sm text-amber-700 hover:underline" @click="addLine">Add line</button>
            <p class="text-xs text-slate-500">Leave item lines empty to record an amount-only adjustment. Quantity lines calculate the subtotal automatically.</p>
            <div class="grid gap-4 md:grid-cols-4">
                <label class="text-sm">Subtotal
                    <input v-if="!itemMode" v-model="form.subtotal" type="number" min="0" step="0.01" required class="ui-input mt-1 w-full">
                    <p v-else class="mt-2 font-semibold">{{ itemsSubtotal.toFixed(2) }}</p>
                </label>
                <label class="text-sm">Tax<input v-model="form.tax_amount" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Round off<input v-model="form.round_off" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Total<p class="mt-2 font-semibold">{{ total }}</p></label>
            </div>
            <button class="ui-btn-primary" type="submit" :disabled="form.processing">Save</button>
        </form>
    </AdminLayout>
</template>
