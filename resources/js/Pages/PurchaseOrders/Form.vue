<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    purchaseRequest: { type: Object, required: true },
    boq: { type: Object, default: null },
    item: { type: Object, default: null },
    purchaseOrder: { type: Object, required: true },
    vendors: { type: Array, default: () => [] },
    gstRates: { type: Array, default: () => ['0.00', '5.00', '12.00', '18.00', '28.00'] },
    companyState: { type: String, default: '' },
});

const lines = computed(() => props.purchaseOrder.items ?? []);

const form = useForm({
    po_date: props.purchaseOrder.po_date || new Date().toISOString().slice(0, 10),
    vendor_id: props.purchaseOrder.vendor_id || '',
    delivery_date: props.purchaseOrder.delivery_date ?? '',
    delivery_address: props.purchaseOrder.delivery_address ?? '',
    place_of_supply: props.purchaseOrder.place_of_supply ?? props.companyState ?? '',
    freight: props.purchaseOrder.freight ?? '0',
    round_off: props.purchaseOrder.round_off ?? '0',
    subject: props.purchaseOrder.subject ?? '',
    terms_conditions: props.purchaseOrder.terms_conditions ?? '',
    remarks: props.purchaseOrder.remarks ?? '',
    rate_change_reason: '',
    items: (props.purchaseOrder.items ?? []).map((line) => ({
        rate: line.rate ?? '0',
        hsn_sac: line.hsn_sac ?? '',
        discount_percentage: line.discount_percentage ?? '0',
        gst_percentage: normalizeRate(line.gst_percentage ?? '18.00'),
    })),
});

const canEditRates = computed(() => props.purchaseOrder.abilities?.edit_rates !== false);

function normalizeRegion(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\b(state|ut|union territory)\b/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function normalizeRate(value) {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return props.gstRates[0] || '0.00';
    }

    const scaled = number.toFixed(2);
    const match = props.gstRates.find((rate) => Number(rate).toFixed(2) === scaled);

    return match ?? scaled;
}

function roundMoney(value) {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return 0;
    }

    const negative = number < 0;
    const rounded = Math.round((Math.abs(number) + Number.EPSILON) * 100) / 100;

    return negative ? -rounded : rounded;
}

function percentOf(base, rate) {
    return roundMoney(base * (Number(rate) || 0) / 100);
}

const taxSplit = computed(() => {
    const supply = normalizeRegion(form.place_of_supply);
    const company = normalizeRegion(props.companyState);

    if (!supply || !company) {
        return 'cgst_sgst';
    }

    return supply === company ? 'cgst_sgst' : 'igst';
});

const preview = computed(() => {
    let subtotal = 0;
    let discount = 0;
    let taxable = 0;
    let cgst = 0;
    let sgst = 0;
    let igst = 0;

    lines.value.forEach((line, index) => {
        const qty = Number(line.quantity) || 0;
        const rate = Number(form.items[index]?.rate ?? line.rate) || 0;
        const gross = roundMoney(qty * rate);
        const discountAmount = percentOf(gross, form.items[index]?.discount_percentage);
        const taxableAmount = roundMoney(gross - discountAmount);
        const gstRate = Number(form.items[index]?.gst_percentage ?? line.gst_percentage) || 0;
        const half = roundMoney(gstRate / 2);
        const otherHalf = roundMoney(gstRate - half);

        subtotal = roundMoney(subtotal + gross);
        discount = roundMoney(discount + discountAmount);
        taxable = roundMoney(taxable + taxableAmount);

        if (taxSplit.value === 'igst') {
            igst = roundMoney(igst + percentOf(taxableAmount, gstRate));
        } else {
            cgst = roundMoney(cgst + percentOf(taxableAmount, half));
            sgst = roundMoney(sgst + percentOf(taxableAmount, otherHalf));
        }
    });

    const freight = roundMoney(form.freight);
    const roundOff = roundMoney(form.round_off);
    const gstTotal = roundMoney(cgst + sgst + igst);
    const grand = roundMoney(taxable + gstTotal + freight + roundOff);

    return { subtotal, discount, taxable, cgst, sgst, igst, gstTotal, freight, roundOff, grand };
});

const selectedVendor = computed(() => props.vendors.find((vendor) => String(vendor.id) === String(form.vendor_id)) ?? null);

function linePreview(index) {
    const line = lines.value[index];
    const qty = Number(line?.quantity) || 0;
    const rate = Number(form.items[index]?.rate ?? line?.rate) || 0;
    const gross = roundMoney(qty * rate);
    const discountAmount = percentOf(gross, form.items[index]?.discount_percentage);
    const taxableAmount = roundMoney(gross - discountAmount);
    const gstRate = Number(form.items[index]?.gst_percentage) || 0;
    const half = roundMoney(gstRate / 2);
    const otherHalf = roundMoney(gstRate - half);
    let gstAmount = 0;

    if (taxSplit.value === 'igst') {
        gstAmount = percentOf(taxableAmount, gstRate);
    } else {
        gstAmount = roundMoney(percentOf(taxableAmount, half) + percentOf(taxableAmount, otherHalf));
    }

    return {
        taxable: taxableAmount,
        gst: gstAmount,
        total: roundMoney(taxableAmount + gstAmount),
    };
}

function submit() {
    if (props.purchaseOrder.id) {
        form.put(`/projects/${props.project.id}/purchase-orders/${props.purchaseOrder.id}`);
        return;
    }

    form.post(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/purchase-orders`);
}
</script>

<template>
    <Head :title="purchaseOrder.id ? 'Edit Purchase Order' : 'Create Purchase Order'" />
    <AdminLayout :project="project">
        <template #header>
            <div>
                <h1 class="text-xl font-semibold">{{ purchaseOrder.id ? 'Edit purchase order' : 'Create purchase order' }}</h1>
                <p class="text-sm text-slate-500">From {{ purchaseRequest.pr_number }}</p>
            </div>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="ui-card grid gap-5 p-6 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">PO date</span>
                    <input v-model="form.po_date" type="date" required class="ui-input">
                    <span v-if="form.errors.po_date" class="mt-1 block text-red-700">{{ form.errors.po_date }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Place of supply</span>
                    <input v-model="form.place_of_supply" type="text" maxlength="255" class="ui-input">
                    <span class="mt-1 block text-xs text-slate-500">
                        Compared with company state ({{ companyState || 'not set' }}) to apply CGST/SGST or IGST.
                    </span>
                    <span v-if="form.errors.place_of_supply" class="mt-1 block text-red-700">{{ form.errors.place_of_supply }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Freight</span>
                    <input v-model="form.freight" type="number" min="0" step="0.01" class="ui-input">
                    <span v-if="form.errors.freight" class="mt-1 block text-red-700">{{ form.errors.freight }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Round off</span>
                    <input v-model="form.round_off" type="number" step="0.01" class="ui-input">
                    <span class="mt-1 block text-xs text-slate-500">Added to the grand total. Use a negative amount to reduce it.</span>
                    <span v-if="form.errors.round_off" class="mt-1 block text-red-700">{{ form.errors.round_off }}</span>
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Vendor</span>
                    <select v-model="form.vendor_id" required class="ui-input">
                        <option value="">Select a vendor</option>
                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.vendor_code }} — {{ vendor.name }}</option>
                    </select>
                    <span v-if="form.errors.vendor_id" class="mt-1 block text-red-700">{{ form.errors.vendor_id }}</span>
                </label>
                <div v-if="selectedVendor" class="rounded-xl bg-slate-50 p-4 text-sm text-slate-600 sm:col-span-2">
                    <p class="font-medium text-slate-900">{{ selectedVendor.name }}</p>
                    <p v-if="selectedVendor.address" class="whitespace-pre-line">{{ selectedVendor.address }}</p>
                    <p v-if="selectedVendor.phone">{{ selectedVendor.phone }}</p>
                    <p v-if="selectedVendor.email">{{ selectedVendor.email }}</p>
                    <p v-if="selectedVendor.gst_number">GST {{ selectedVendor.gst_number }}</p>
                    <p class="mt-2 text-xs text-slate-500">These details are saved on the purchase order and stay unchanged if the vendor master is edited later.</p>
                </div>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Subject</span>
                    <input v-model="form.subject" type="text" class="ui-input" maxlength="255">
                    <span v-if="form.errors.subject" class="mt-1 block text-red-700">{{ form.errors.subject }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Delivery date</span>
                    <input v-model="form.delivery_date" type="date" class="ui-input">
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Delivery address</span>
                    <textarea v-model="form.delivery_address" rows="2" class="ui-textarea"></textarea>
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Terms &amp; Conditions</span>
                    <textarea v-model="form.terms_conditions" rows="5" class="ui-textarea"></textarea>
                    <span class="mt-1 block text-xs text-slate-500">Enter one condition per line if preferred.</span>
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Remarks</span>
                    <textarea v-model="form.remarks" rows="2" class="ui-textarea"></textarea>
                </label>
            </div>

            <div class="ui-table-wrap overflow-x-auto">
                <table class="min-w-[1100px] border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Sr. No.</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Service Code</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Item</th>
                            <th class="px-3 py-2 font-medium text-slate-500">HSN/SAC</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Unit</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Qty</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Previous GRN qty</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Rate</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Disc %</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">GST %</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Taxable</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">GST Amt</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in lines" :key="index">
                            <td class="border border-slate-200 px-3 py-2">{{ index + 1 }}</td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.service_code || '—' }}</td>
                            <td class="border border-slate-200 px-3 py-2 whitespace-pre-line">
                                <div>{{ line.name || line.description }}</div>
                                <div v-if="line.name && line.description && line.name !== line.description" class="text-xs text-slate-500">{{ line.description }}</div>
                            </td>
                            <td class="border border-slate-200 px-3 py-2">
                                <input v-if="form.items[index]" v-model="form.items[index].hsn_sac" type="text" maxlength="20" class="ui-input w-24">
                            </td>
                            <td class="border border-slate-200 px-3 py-2">{{ line.unit || '—' }}</td>
                            <td class="num-cell border border-slate-200 px-3 py-2">{{ line.quantity }}</td>
                            <td class="num-cell border border-slate-200 px-3 py-2">{{ line.previous_grn_quantity ?? '0.000' }}</td>
                            <td class="num-cell border border-slate-200 px-3 py-2">
                                <input
                                    v-if="form.items[index] && canEditRates"
                                    v-model="form.items[index].rate"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="ui-input num-input min-w-[6.5rem]"
                                >
                                <span v-else class="tabular-nums">{{ formatInr(line.rate) }}</span>
                            </td>
                            <td class="border border-slate-200 px-3 py-2 text-right">
                                <input v-if="form.items[index]" v-model="form.items[index].discount_percentage" type="number" min="0" max="100" step="0.01" class="ui-input num-input-sm min-w-[4.5rem]">
                            </td>
                            <td class="border border-slate-200 px-3 py-2 text-right">
                                <select v-if="form.items[index]" v-model="form.items[index].gst_percentage" class="ui-input min-w-[5.5rem]">
                                    <option v-for="rate in gstRates" :key="rate" :value="rate">{{ Number(rate) }}%</option>
                                </select>
                                <span v-if="form.errors[`items.${index}.gst_percentage`]" class="mt-1 block text-xs text-red-700">{{ form.errors[`items.${index}.gst_percentage`] }}</span>
                            </td>
                            <td class="num-cell border border-slate-200 px-3 py-2">{{ formatInr(linePreview(index).taxable) }}</td>
                            <td class="num-cell border border-slate-200 px-3 py-2">{{ formatInr(linePreview(index).gst) }}</td>
                            <td class="num-cell border border-slate-200 px-3 py-2">{{ formatInr(linePreview(index).total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <label v-if="canEditRates" class="block text-sm">
                <span class="font-medium">Rate change reason</span>
                <textarea v-model="form.rate_change_reason" rows="2" class="ui-textarea" placeholder="Required when any line rate changes"></textarea>
                <span v-if="form.errors.rate_change_reason" class="mt-1 block text-red-700">{{ form.errors.rate_change_reason }}</span>
            </label>
            <p class="text-sm text-slate-500">Quantity and description stay locked from the purchase request. Rate, HSN, discount, and GST % can be edited on drafts. Changing a rate requires a reason and is written to the audit log.</p>
            <dl class="grid gap-2 text-sm sm:ml-auto sm:w-80">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="tabular-nums">{{ formatInr(preview.subtotal) }}</dd></div>
                <div class="flex justify-between"><dt>Discount</dt><dd class="tabular-nums">{{ formatInr(preview.discount ? -preview.discount : 0) }}</dd></div>
                <div class="flex justify-between"><dt>Taxable value</dt><dd class="tabular-nums">{{ formatInr(preview.taxable) }}</dd></div>
                <div v-if="taxSplit === 'igst'" class="flex justify-between"><dt>IGST</dt><dd class="tabular-nums">{{ formatInr(preview.igst) }}</dd></div>
                <template v-else>
                    <div class="flex justify-between"><dt>CGST</dt><dd class="tabular-nums">{{ formatInr(preview.cgst) }}</dd></div>
                    <div class="flex justify-between"><dt>SGST</dt><dd class="tabular-nums">{{ formatInr(preview.sgst) }}</dd></div>
                </template>
                <div class="flex justify-between"><dt>Total GST</dt><dd class="tabular-nums">{{ formatInr(preview.gstTotal) }}</dd></div>
                <div class="flex justify-between"><dt>Freight</dt><dd class="tabular-nums">{{ formatInr(preview.freight) }}</dd></div>
                <div class="flex justify-between"><dt>Round off</dt><dd class="tabular-nums">{{ formatInr(preview.roundOff) }}</dd></div>
                <div class="flex justify-between font-semibold"><dt>Grand total</dt><dd class="tabular-nums">{{ formatInr(preview.grand) }}</dd></div>
            </dl>
            <div class="flex flex-wrap items-center gap-4">
                <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save draft</button>
                <Link :href="purchaseOrder.id ? `/projects/${project.id}/purchase-orders/${purchaseOrder.id}` : `/projects/${project.id}/purchase-requests/${purchaseRequest.id}`" class="text-sm text-slate-600 hover:underline">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
