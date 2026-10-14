<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import FileUploadField from '../../Components/FileUploadField.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr, formatQty, lineAmount } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    invoice: { type: Object, default: null },
    purchaseOrders: { type: Array, required: true },
    selectedOrder: { type: Object, default: null },
    vendors: { type: Array, default: () => [] },
});

const taxRates = ['0', '5', '12', '18', '28'];
const poTaxRate = props.selectedOrder ? String(Number(props.selectedOrder.gst_percentage || 0)) : '';
const poRateIsCustom = poTaxRate !== '' && !taxRates.includes(poTaxRate);

const form = useForm({
    purchase_order_id: props.selectedOrder?.id || '',
    tax_percentage: poRateIsCustom ? '' : (poTaxRate || '18'),
    vendor_id: '',
    invoice_number: props.invoice?.invoice_number || '',
    invoice_date: props.invoice?.invoice_date || new Date().toISOString().slice(0, 10),
    due_date: props.invoice?.due_date || '',
    remarks: props.invoice?.remarks || '',
    invoice_file: null,
    items: (props.selectedOrder?.lines || []).map((line) => ({
        purchase_order_item_id: line.id,
        quantity: '',
    })),
});

const rows = computed(() => (props.selectedOrder?.lines || []).map((line, index) => {
    const quantity = form.items[index]?.quantity ?? '';
    const current = quantity === '' ? 0 : Number(quantity);
    const remaining = Math.max(0, Number(line.remaining) - (Number.isFinite(current) ? current : 0));
    const gross = lineAmount(quantity, line.rate);

    return {
        ...line,
        quantity,
        balance: remaining,
        gross,
    };
}));

const splitRates = computed(() => {
    if (form.tax_percentage === '') {
        return null;
    }

    const gst = Number(form.tax_percentage);
    const cgst = Math.round((gst / 2 + Number.EPSILON) * 100) / 100;

    return { cgst, sgst: Math.round((gst - cgst + Number.EPSILON) * 100) / 100 };
});

const totals = computed(() => {
    let taxable = 0;
    let tax = 0;

    rows.value.forEach((row) => {
        if (row.gross === null) {
            return;
        }

        const discountRate = Number(row.discount_percentage || 0);
        const discount = Math.round(((row.gross * discountRate) / 100 + Number.EPSILON) * 100) / 100;
        const net = Math.max(0, Math.round((row.gross - discount + Number.EPSILON) * 100) / 100);
        const cgstRate = splitRates.value ? splitRates.value.cgst : Number(row.cgst_percentage || 0);
        const sgstRate = splitRates.value ? splitRates.value.sgst : Number(row.sgst_percentage || 0);
        const cgst = Math.round(((net * cgstRate) / 100 + Number.EPSILON) * 100) / 100;
        const sgst = Math.round(((net * sgstRate) / 100 + Number.EPSILON) * 100) / 100;
        taxable += net;
        tax += cgst + sgst;
    });

    return {
        taxable: Math.round((taxable + Number.EPSILON) * 100) / 100,
        tax: Math.round((tax + Number.EPSILON) * 100) / 100,
        total: Math.round((taxable + tax + Number.EPSILON) * 100) / 100,
    };
});

function chooseOrder(event) {
    const id = event.target.value;
    form.purchase_order_id = id;
    router.get(`/projects/${props.project.id}/purchase-invoices/create`, id ? { purchase_order_id: id } : {}, {
        preserveState: false,
    });
}

function submit() {
    form.post(`/projects/${props.project.id}/purchase-invoices`, { forceFormData: true });
}
</script>

<template>
    <Head title="Purchase Invoice" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Purchase Invoice" :eyebrow="project.project_name" />
        </template>
        <form class="space-y-4" @submit.prevent="submit">
            <section class="ui-card grid max-w-3xl gap-4 p-6 md:grid-cols-2">
                <label class="text-sm md:col-span-2">Purchase Order
                    <select :value="form.purchase_order_id" required class="ui-input" @change="chooseOrder">
                        <option value="">Select approved PO</option>
                        <option v-for="order in purchaseOrders" :key="order.id" :value="order.id">{{ order.po_number }} · {{ order.vendor }}</option>
                    </select>
                </label>
                <div v-if="selectedOrder" class="rounded-xl bg-slate-50 p-4 text-sm md:col-span-2">
                    <p v-if="selectedOrder.vendor_id">Vendor {{ selectedOrder.vendor }}</p>
                    <p>PO total {{ formatInr(selectedOrder.grand_total) }}</p>
                </div>
                <label v-if="selectedOrder && !selectedOrder.vendor_id" class="text-sm md:col-span-2">Vendor
                    <select v-model="form.vendor_id" required class="ui-input">
                        <option value="">Select vendor</option>
                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.vendor_code }} · {{ vendor.name }}</option>
                    </select>
                    <span class="mt-1 block text-slate-500">This purchase order was saved without a vendor. The vendor you select is stored on the order and this invoice.</span>
                    <Link v-if="vendors.length === 0" href="/vendors/create" class="mt-2 inline-block text-sm underline">Add a vendor first</Link>
                </label>
                <p v-if="form.errors.vendor_id" class="text-sm text-red-700 md:col-span-2">{{ form.errors.vendor_id }}</p>
                <label class="text-sm">Invoice Number<input v-model="form.invoice_number" required class="ui-input"></label>
                <label class="text-sm">Invoice Date<input v-model="form.invoice_date" type="date" required class="ui-input"></label>
                <label class="text-sm">Due Date<input v-model="form.due_date" type="date" class="ui-input"></label>
                <label class="text-sm">Tax (GST)
                    <select v-model="form.tax_percentage" class="ui-input">
                        <option v-if="poRateIsCustom" value="">As per PO ({{ poTaxRate }}%)</option>
                        <option v-for="rate in taxRates" :key="rate" :value="rate">{{ rate }}%</option>
                    </select>
                    <span v-if="form.errors.tax_percentage" class="mt-1 block text-red-700">{{ form.errors.tax_percentage }}</span>
                </label>
                <div class="md:col-span-2">
                    <FileUploadField
                        v-model="form.invoice_file"
                        label="Purchase Invoice Attachment"
                        button="Upload Invoice"
                        hint="PDF, JPG, PNG or WEBP"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                        :error="form.errors.invoice_file || ''"
                    />
                </div>
                <label class="text-sm md:col-span-2">Remarks<textarea v-model="form.remarks" rows="2" class="ui-textarea"></textarea></label>
                <p v-if="form.errors.purchase_order_id" class="text-sm text-red-700">{{ form.errors.purchase_order_id }}</p>
            </section>

            <section v-if="selectedOrder" class="ui-card overflow-x-auto p-4">
                <table class="min-w-[980px] w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="px-2 py-2">Sr.</th>
                            <th class="px-2 py-2">PO Item</th>
                            <th class="px-2 py-2">Unit</th>
                            <th class="px-2 py-2 text-right">PO Qty</th>
                            <th class="px-2 py-2 text-right">Previously Invoiced</th>
                            <th class="px-2 py-2 text-right">Current Invoice Qty</th>
                            <th class="px-2 py-2 text-right">Balance Qty</th>
                            <th class="px-2 py-2 text-right">Rate</th>
                            <th class="px-2 py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="row.id">
                            <td class="px-2 py-2">{{ index + 1 }}</td>
                            <td class="px-2 py-2">
                                <p>{{ row.description }}</p>
                                <p v-if="row.service_code" class="text-xs text-slate-500">{{ row.service_code }}</p>
                            </td>
                            <td class="px-2 py-2">{{ row.unit || '—' }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ formatQty(row.po_quantity) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ formatQty(row.previously_invoiced) }}</td>
                            <td class="px-2 py-2 text-right">
                                <input v-model="form.items[index].quantity" type="number" min="0" :max="row.remaining" step="0.001" class="ui-input w-28 text-right">
                                <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-xs text-red-700">{{ form.errors[`items.${index}.quantity`] }}</p>
                            </td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ formatQty(row.balance) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ formatInr(row.rate) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ row.gross === null ? '—' : formatInr(row.gross) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="form.errors.items" class="mt-3 text-sm text-red-700">{{ form.errors.items }}</p>
                <div class="mt-4 ml-auto w-full max-w-xs space-y-1 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Taxable amount</span><span class="tabular-nums">{{ formatInr(totals.taxable) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Tax{{ form.tax_percentage === '' ? '' : ` (GST ${form.tax_percentage}%)` }}</span><span class="tabular-nums">{{ formatInr(totals.tax) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>Invoice total</span><span class="tabular-nums">{{ formatInr(totals.total) }}</span></div>
                </div>
            </section>
            <button class="ui-btn-primary" :disabled="form.processing || !selectedOrder">Save Invoice</button>
        </form>
    </AdminLayout>
</template>
