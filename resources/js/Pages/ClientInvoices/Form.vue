<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    invoice: { type: Object, default: null },
    clients: { type: Array, required: true },
    billableItems: { type: Array, required: true },
    taxRates: { type: Array, required: true },
});

function emptyLine() {
    return { boq_item_id: '', description: '', unit: '', quantity: '', rate: '', discount_percentage: '0', remarks: '' };
}

const form = useForm({
    client_id: props.invoice?.client_id || '',
    invoice_date: props.invoice?.invoice_date || new Date().toISOString().slice(0, 10),
    due_date: props.invoice?.due_date || '',
    place_of_supply: props.invoice?.place_of_supply || '',
    reference_number: props.invoice?.reference_number || '',
    description: props.invoice?.description || '',
    payment_terms: props.invoice?.payment_terms || '',
    remarks: props.invoice?.remarks || '',
    gst_percentage: props.invoice?.gst_percentage || '18',
    round_off: props.invoice?.round_off || '0',
    items: props.invoice?.items?.length ? props.invoice.items.map((line) => ({
        boq_item_id: line.boq_item_id || '',
        description: line.description,
        unit: line.unit || '',
        quantity: line.quantity,
        rate: line.rate,
        discount_percentage: line.discount_percentage || '0',
        remarks: line.remarks || '',
    })) : [emptyLine()],
});

const catalog = computed(() => {
    const map = {};
    props.billableItems.forEach((item) => { map[item.id] = item; });
    return map;
});

function applyClient() {
    const client = props.clients.find((row) => String(row.id) === String(form.client_id));
    if (!client) {
        return;
    }
    if (!form.place_of_supply) {
        form.place_of_supply = client.state || '';
    }
    if (!form.payment_terms) {
        form.payment_terms = client.payment_terms || '';
    }
}

function applyBoq(index) {
    const line = form.items[index];
    const item = catalog.value[line.boq_item_id];
    if (!item) {
        return;
    }
    line.description = item.description;
    line.unit = item.unit || '';
    line.rate = item.rate;
    if (!line.quantity) {
        line.quantity = item.remaining_quantity;
    }
}

function remaining(line) {
    const item = catalog.value[line.boq_item_id];
    return item ? item.remaining_quantity : null;
}

function previouslyBilled(line) {
    const item = catalog.value[line.boq_item_id];
    return item ? item.previously_billed : null;
}

const preview = computed(() => {
    const gst = Number(form.gst_percentage || 0);
    let subtotal = 0;
    let discount = 0;
    form.items.forEach((line) => {
        const amount = Number(line.quantity || 0) * Number(line.rate || 0);
        const off = amount * Number(line.discount_percentage || 0) / 100;
        subtotal += amount;
        discount += off;
    });
    const taxable = subtotal - discount;
    const tax = taxable * gst / 100;
    const roundOff = Number(form.round_off || 0);
    return {
        subtotal: subtotal.toFixed(2),
        discount: discount.toFixed(2),
        taxable: taxable.toFixed(2),
        tax: tax.toFixed(2),
        total: (taxable + tax + roundOff).toFixed(2),
    };
});

function addLine() {
    form.items.push(emptyLine());
}

function removeLine(index) {
    form.items.splice(index, 1);
    if (!form.items.length) {
        form.items.push(emptyLine());
    }
}

function submit() {
    const url = props.invoice
        ? `/projects/${props.project.id}/client-invoices/${props.invoice.id}`
        : `/projects/${props.project.id}/client-invoices`;
    props.invoice ? form.put(url) : form.post(url);
}
</script>

<template>
    <Head :title="invoice ? 'Edit Client Invoice' : 'New Client Invoice'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="invoice ? 'Edit Client Invoice' : 'New Client Invoice'" :eyebrow="project.project_name" />
        </template>
        <form class="space-y-4" @submit.prevent="submit">
            <div class="ui-card grid gap-4 p-5 md:grid-cols-2">
                <label class="text-sm">Client
                    <select v-model="form.client_id" required class="ui-input mt-1 w-full" @change="applyClient">
                        <option value="">Select client</option>
                        <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                    </select>
                    <span v-if="form.errors.client_id" class="mt-1 block text-red-700">{{ form.errors.client_id }}</span>
                </label>
                <label class="text-sm">Invoice date<input v-model="form.invoice_date" type="date" required class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Due date<input v-model="form.due_date" type="date" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Place of supply<input v-model="form.place_of_supply" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Reference / contract<input v-model="form.reference_number" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">GST %
                    <select v-model="form.gst_percentage" class="ui-input mt-1 w-full">
                        <option v-for="rate in taxRates" :key="rate" :value="rate">{{ rate }}%</option>
                    </select>
                </label>
                <label class="text-sm">Payment terms<input v-model="form.payment_terms" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Round off<input v-model="form.round_off" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm md:col-span-2">Description<input v-model="form.description" class="ui-input mt-1 w-full"></label>
                <label class="text-sm md:col-span-2">Remarks<textarea v-model="form.remarks" rows="2" class="ui-textarea mt-1 w-full"></textarea></label>
            </div>
            <p class="text-sm text-slate-500">Bill against current BOQ quantities and rates, or enter a manual line. GRNs and vendor invoices are not treated as client-billable work.</p>
            <div class="ui-table-wrap overflow-x-auto">
                <table class="ui-table min-w-[960px]">
                    <thead>
                        <tr>
                            <th>BOQ item</th>
                            <th>Description</th>
                            <th>Unit</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">Disc %</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.items" :key="index">
                            <td>
                                <select v-model="line.boq_item_id" class="ui-input w-48" @change="applyBoq(index)">
                                    <option value="">Manual line</option>
                                    <option v-for="item in billableItems" :key="item.id" :value="item.id">{{ item.item_number }} — {{ item.description }}</option>
                                </select>
                                <p v-if="remaining(line) !== null" class="mt-1 text-xs text-slate-500">Billed {{ previouslyBilled(line) }} · remaining {{ remaining(line) }}</p>
                            </td>
                            <td><input v-model="line.description" required class="ui-input w-full min-w-[14rem]"></td>
                            <td><input v-model="line.unit" class="ui-input unit-input"></td>
                            <td><input v-model="line.quantity" type="number" min="0.001" step="0.001" required class="ui-input num-input"></td>
                            <td><input v-model="line.rate" type="number" min="0" step="0.01" required class="ui-input num-input"></td>
                            <td><input v-model="line.discount_percentage" type="number" min="0" max="100" step="0.01" class="ui-input num-input-sm"></td>
                            <td><button type="button" class="text-sm text-rose-700" @click="removeLine(index)">Remove</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>
            <button type="button" class="ui-btn-secondary" @click="addLine">Add line</button>
            <div class="ui-card max-w-md space-y-1 p-4 text-sm">
                <p class="flex justify-between"><span>Subtotal</span><span>{{ formatInr(preview.subtotal) }}</span></p>
                <p class="flex justify-between"><span>Discount</span><span>{{ formatInr(preview.discount) }}</span></p>
                <p class="flex justify-between"><span>Taxable</span><span>{{ formatInr(preview.taxable) }}</span></p>
                <p class="flex justify-between"><span>GST</span><span>{{ formatInr(preview.tax) }}</span></p>
                <p class="flex justify-between font-semibold"><span>Estimated total</span><span>{{ formatInr(preview.total) }}</span></p>
                <p class="text-xs text-slate-500">Final totals are calculated on the server.</p>
            </div>
            <button class="ui-btn-primary">{{ invoice ? 'Update draft' : 'Save draft' }}</button>
        </form>
    </AdminLayout>
</template>
