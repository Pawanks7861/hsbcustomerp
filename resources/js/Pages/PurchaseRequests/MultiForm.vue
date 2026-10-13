<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    boqItems: { type: Array, required: true },
    purchaseRequest: { type: Object, default: null },
});

function blank() {
    return { boq_item_id: '', quantity: '', remarks: '' };
}

const form = useForm({
    request_date: props.purchaseRequest?.request_date || new Date().toISOString().slice(0, 10),
    required_date: props.purchaseRequest?.required_date || '',
    description: props.purchaseRequest?.description || '',
    items: props.purchaseRequest?.items?.length
        ? props.purchaseRequest.items.map((item) => ({
            boq_item_id: item.boq_item_id || '',
            quantity: item.quantity || '',
            remarks: item.remarks || '',
        }))
        : [blank()],
});

function option(id) {
    return props.boqItems.find((item) => String(item.id) === String(id));
}

function shortLabel(item) {
    const text = String(item.label || '').replace(/\s+/g, ' ').trim();

    return text.length > 140 ? `${text.slice(0, 137)}...` : text;
}

function rowState(row) {
    const item = option(row.boq_item_id);

    if (!item) {
        return null;
    }

    const previous = Number(item.used_quantity || 0);
    const requested = Number(row.quantity || 0);
    const boq = Number(item.quantity || 0);
    const cumulative = previous + requested;

    return {
        item,
        previous,
        requested,
        cumulative,
        remaining: boq - previous,
        excess: Math.max(0, cumulative - boq),
        exceeded: cumulative - boq > 0.0000001,
        amount: item.rate === null || row.quantity === '' ? null : Number(item.rate) * requested,
    };
}

function addItem() {
    form.items.push(blank());
}

function removeItem(index) {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
}

function submit() {
    const url = props.purchaseRequest
        ? `/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}/lines`
        : `/projects/${props.project.id}/purchase-requests`;

    if (props.purchaseRequest) {
        form.put(url);
    } else {
        form.post(url);
    }
}
</script>

<template>
    <Head title="Purchase Request" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="purchaseRequest ? 'Edit Purchase Request' : 'New Purchase Request'" :eyebrow="project.project_name" />
        </template>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="ui-card space-y-4 p-5">
                <h2 class="font-semibold">Purchase Request Details</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Project</span>
                        <input :value="project.project_name" class="ui-input w-full" disabled>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">PR Date</span>
                        <input v-model="form.request_date" type="date" required class="ui-input w-full">
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block font-medium">Required Date</span>
                        <input v-model="form.required_date" type="date" class="ui-input w-full">
                    </label>
                    <label class="text-sm sm:col-span-2">
                        <span class="mb-1 block font-medium">Remarks</span>
                        <textarea v-model="form.description" rows="2" class="ui-input w-full"></textarea>
                    </label>
                </div>
            </section>

            <section class="ui-card space-y-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">Purchase Request Items</h2>
                    <button type="button" class="ui-btn-secondary" @click="addItem">Add Item</button>
                </div>
                <p v-if="boqItems.length === 0" class="text-sm text-amber-800">No BOQ items are available on the current revision.</p>
                <div class="overflow-x-auto">
                    <table class="min-w-[920px] w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="px-2 py-2">Sr.</th>
                                <th class="px-2 py-2">BOQ Item</th>
                                <th class="px-2 py-2">Unit</th>
                                <th class="px-2 py-2 text-right">BOQ Qty</th>
                                <th class="px-2 py-2 text-right">Previously Requested</th>
                                <th class="px-2 py-2 text-right">Requested Qty</th>
                                <th class="px-2 py-2 text-right">Rate</th>
                                <th class="px-2 py-2 text-right">Amount</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(row, index) in form.items" :key="index">
                                <tr :class="rowState(row)?.exceeded ? 'bg-orange-50' : ''">
                                    <td class="px-2 py-2">{{ index + 1 }}</td>
                                    <td class="px-2 py-2">
                                        <select v-model="row.boq_item_id" required class="ui-input w-full min-w-56">
                                            <option value="">Select BOQ item</option>
                                            <option v-for="item in boqItems" :key="item.id" :value="item.id">{{ shortLabel(item) }}</option>
                                        </select>
                                        <p v-if="rowState(row)" class="mt-1 text-xs text-slate-500">
                                            <span v-if="rowState(row).item.service_code">{{ rowState(row).item.service_code }} · </span>{{ shortLabel(rowState(row).item) }}
                                        </p>
                                        <p v-if="form.errors[`items.${index}.boq_item_id`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.boq_item_id`] }}</p>
                                    </td>
                                    <td class="px-2 py-2">{{ rowState(row)?.item.unit || '—' }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ rowState(row)?.item.quantity || '—' }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ rowState(row)?.previous?.toFixed(3) || '—' }}</td>
                                    <td class="px-2 py-2">
                                        <input v-model="row.quantity" type="number" min="0.001" step="0.001" required class="ui-input w-28 text-right">
                                        <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.quantity`] }}</p>
                                    </td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ rowState(row)?.item.rate ?? '—' }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ rowState(row)?.amount == null ? '—' : rowState(row).amount.toFixed(2) }}</td>
                                    <td class="px-2 py-2">
                                        <button v-if="form.items.length > 1" type="button" class="text-red-700" @click="removeItem(index)">Remove</button>
                                    </td>
                                </tr>
                                <tr v-if="rowState(row)?.exceeded">
                                    <td colspan="9" class="px-2 pb-3 text-sm font-semibold text-orange-800">BOQ QUANTITY EXCEEDED — APPROVAL REQUIRED</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>
            </section>

            <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save Purchase Request</button>
        </form>
    </AdminLayout>
</template>
