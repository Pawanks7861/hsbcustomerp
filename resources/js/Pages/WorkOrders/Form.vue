<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    workOrder: { type: Object, default: null },
    contractors: { type: Array, required: true },
    catalog: { type: Array, required: true },
    preselectedId: { type: Number, default: null },
});

const catalogById = computed(() => {
    const map = {};
    props.catalog.forEach((item) => {
        map[item.id] = item;
    });
    return map;
});

function blankLine(itemId = '') {
    const item = catalogById.value[itemId];

    return {
        boq_item_id: itemId || '',
        quantity: '',
        rate: item?.rate ?? '',
    };
}

const initialLines = props.workOrder?.items?.length
    ? props.workOrder.items.map((line) => ({
        boq_item_id: line.boq_item_id,
        quantity: line.quantity,
        rate: line.rate,
    }))
    : [blankLine(props.preselectedId || '')];

const form = useForm({
    vendor_id: props.workOrder?.vendor_id ?? '',
    work_order_date: props.workOrder?.work_order_date ?? new Date().toISOString().slice(0, 10),
    completion_date: props.workOrder?.completion_date ?? '',
    gst_percentage: props.workOrder?.gst_percentage ?? '0',
    scope: props.workOrder?.scope ?? '',
    payment_terms: props.workOrder?.payment_terms ?? '',
    terms_conditions: props.workOrder?.terms_conditions ?? '',
    remarks: props.workOrder?.remarks ?? '',
    items: initialLines,
});

function money(quantity, rate) {
    const value = Number(quantity) * Number(rate);
    if (!Number.isFinite(value)) {
        return 0;
    }

    return Math.round((value + Number.EPSILON) * 100) / 100;
}

function lineMeta(line) {
    return catalogById.value[line.boq_item_id] || null;
}

function remaining(line) {
    const item = lineMeta(line);
    if (!item) {
        return null;
    }

    return Number(item.quantity) - Number(item.previously_allocated) - Number(line.quantity || 0);
}

const subtotal = computed(() => form.items.reduce((sum, line) => sum + money(line.quantity, line.rate), 0));
const gstAmount = computed(() => Math.round(((subtotal.value * Number(form.gst_percentage || 0)) / 100 + Number.EPSILON) * 100) / 100);
const grandTotal = computed(() => Math.round((subtotal.value + gstAmount.value + Number.EPSILON) * 100) / 100);

function formatMoney(value) {
    return value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function addLine() {
    form.items.push(blankLine());
}

function removeLine(index) {
    form.items.splice(index, 1);
    if (form.items.length === 0) {
        form.items.push(blankLine());
    }
}

function onItem(line) {
    const item = lineMeta(line);
    if (item && (line.rate === '' || line.rate === null)) {
        line.rate = item.rate;
    }
}

function submit() {
    form.transform((data) => ({
        ...data,
        items: data.items.filter((line) => line.boq_item_id),
    }));

    if (props.workOrder?.id) {
        form.put(`/projects/${props.project.id}/work-orders/${props.workOrder.id}`);
        return;
    }

    form.post(`/projects/${props.project.id}/work-orders`);
}
</script>

<template>
    <Head :title="workOrder ? `Edit ${workOrder.work_order_number}` : 'New Work Order'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="workOrder ? `Edit ${workOrder.work_order_number}` : 'New Work Order'" :eyebrow="project.project_name" />
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="ui-card grid gap-4 p-6 md:grid-cols-2">
                <label class="text-sm">
                    Contractor
                    <select v-model="form.vendor_id" required class="ui-input">
                        <option value="">Select an active contractor</option>
                        <option v-for="contractor in contractors" :key="contractor.id" :value="contractor.id">
                            {{ contractor.vendor_code }} — {{ contractor.name }}
                        </option>
                    </select>
                    <span v-if="form.errors.vendor_id" class="mt-1 block text-red-600">{{ form.errors.vendor_id }}</span>
                </label>
                <label class="text-sm">
                    Work Order Date
                    <input v-model="form.work_order_date" type="date" required class="ui-input">
                </label>
                <label class="text-sm">
                    Completion Date
                    <input v-model="form.completion_date" type="date" class="ui-input">
                </label>
                <label class="text-sm">
                    GST %
                    <input v-model="form.gst_percentage" type="number" min="0" max="100" step="0.01" class="ui-input">
                </label>
                <label class="text-sm md:col-span-2">
                    Scope of Work
                    <textarea v-model="form.scope" rows="2" class="ui-textarea"></textarea>
                </label>
                <label class="text-sm">
                    Payment Terms
                    <textarea v-model="form.payment_terms" rows="2" class="ui-textarea"></textarea>
                </label>
                <label class="text-sm">
                    Terms and Conditions
                    <textarea v-model="form.terms_conditions" rows="2" class="ui-textarea"></textarea>
                </label>
            </section>

            <section class="ui-card p-6">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">BOQ items</h2>
                    <button type="button" class="ui-btn-secondary" @click="addLine">Add item</button>
                </div>
                <p v-if="form.errors.items" class="mb-3 text-sm text-red-600">{{ form.errors.items }}</p>
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
                                <th class="px-3 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.items" :key="index" :class="remaining(line) !== null && remaining(line) < 0 ? 'bg-red-50' : ''">
                                <td class="px-3 py-3">{{ index + 1 }}</td>
                                <td class="px-3 py-3">
                                    <select v-model="line.boq_item_id" class="ui-input min-w-56" @change="onItem(line)">
                                        <option value="">Select</option>
                                        <option v-for="item in catalog" :key="item.id" :value="item.id">
                                            {{ item.item_number }} — {{ item.description }}
                                        </option>
                                    </select>
                                    <span v-if="form.errors[`items.${index}.boq_item_id`]" class="mt-1 block text-xs text-red-600">{{ form.errors[`items.${index}.boq_item_id`] }}</span>
                                </td>
                                <td class="px-3 py-3">{{ lineMeta(line)?.service_code || lineMeta(line)?.item_number || '—' }}</td>
                                <td class="px-3 py-3">{{ lineMeta(line)?.unit || '—' }}</td>
                                <td class="num-cell px-3 py-3">{{ lineMeta(line)?.quantity || '—' }}</td>
                                <td class="num-cell px-3 py-3">{{ lineMeta(line)?.previously_allocated || '—' }}</td>
                                <td class="px-3 py-3">
                                    <input v-model="line.quantity" type="number" min="0" step="0.001" class="ui-input num-input">
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    <span v-if="remaining(line) === null">—</span>
                                    <span v-else :class="remaining(line) < 0 ? 'font-medium text-red-700' : ''">{{ remaining(line).toFixed(3) }}</span>
                                    <p v-if="remaining(line) !== null && remaining(line) < 0" class="mt-1 text-xs font-medium text-red-700">
                                        Excess {{ Math.abs(remaining(line)).toFixed(3) }}. Approval is required before issue.
                                    </p>
                                </td>
                                <td class="px-3 py-3">
                                    <input v-model="line.rate" type="number" min="0" step="0.01" class="ui-input num-input">
                                </td>
                                <td class="num-cell px-3 py-3">{{ formatMoney(money(line.quantity, line.rate)) }}</td>
                                <td class="px-3 py-3">
                                    <button type="button" class="text-sm text-red-600 hover:underline" @click="removeLine(index)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <dl class="mt-4 grid justify-end gap-1 text-sm">
                    <div class="flex justify-between gap-8"><dt>Subtotal</dt><dd class="tabular-nums">₹ {{ formatMoney(subtotal) }}</dd></div>
                    <div class="flex justify-between gap-8"><dt>GST</dt><dd class="tabular-nums">₹ {{ formatMoney(gstAmount) }}</dd></div>
                    <div class="flex justify-between gap-8 font-semibold"><dt>Grand Total</dt><dd class="tabular-nums">₹ {{ formatMoney(grandTotal) }}</dd></div>
                </dl>
            </section>

            <div class="flex gap-3">
                <button class="ui-btn-primary" :disabled="form.processing">Save draft</button>
                <Link :href="`/projects/${project.id}/work-orders`" class="text-sm text-slate-600 hover:underline">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
