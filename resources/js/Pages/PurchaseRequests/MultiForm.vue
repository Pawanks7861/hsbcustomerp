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
    return { id: null, name: '', description: '', unit: '', quantity: '', boq_item_id: '', remarks: '' };
}

const form = useForm({
    request_date: props.purchaseRequest?.request_date || new Date().toISOString().slice(0, 10),
    required_date: props.purchaseRequest?.required_date || '',
    description: props.purchaseRequest?.description || '',
    items: props.purchaseRequest?.items?.length
        ? props.purchaseRequest.items.map((item) => ({
            id: item.id || null,
            name: item.name || '',
            description: item.description || '',
            unit: item.unit || '',
            quantity: item.quantity || '',
            boq_item_id: item.boq_item_id || '',
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
    };
}

function onBoqTag(row) {
    const item = option(row.boq_item_id);

    if (item && !String(row.unit || '').trim()) {
        row.unit = item.unit || '';
    }
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

            <section class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">Purchase Request Items</h2>
                    <button type="button" class="ui-btn-secondary" @click="addItem">Add Item</button>
                </div>

                <article
                    v-for="(row, index) in form.items"
                    :key="row.id || index"
                    class="ui-card space-y-4 p-5"
                    :class="rowState(row)?.exceeded ? 'ring-1 ring-orange-200' : ''"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-slate-700">Item {{ index + 1 }}</h3>
                        <button v-if="form.items.length > 1" type="button" class="text-sm text-red-700" @click="removeItem(index)">Remove</button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm sm:col-span-2">
                            <span class="mb-1 block font-medium">Item Name</span>
                            <input v-model="row.name" type="text" required maxlength="5000" class="ui-input w-full">
                            <p v-if="form.errors[`items.${index}.name`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.name`] }}</p>
                        </label>
                        <label class="text-sm sm:col-span-2">
                            <span class="mb-1 block font-medium">Item Description</span>
                            <textarea v-model="row.description" rows="2" maxlength="2000" class="ui-input w-full"></textarea>
                            <p v-if="form.errors[`items.${index}.description`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.description`] }}</p>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Unit</span>
                            <input v-model="row.unit" type="text" maxlength="50" class="ui-input w-full">
                            <p v-if="form.errors[`items.${index}.unit`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.unit`] }}</p>
                        </label>
                        <label class="text-sm">
                            <span class="mb-1 block font-medium">Requested Quantity</span>
                            <input v-model="row.quantity" type="number" min="0.001" step="0.001" required class="ui-input w-full text-right">
                            <p v-if="form.errors[`items.${index}.quantity`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.quantity`] }}</p>
                        </label>
                        <label class="text-sm sm:col-span-2">
                            <span class="mb-1 block font-medium">BOQ Item Tag (Optional)</span>
                            <select v-model="row.boq_item_id" class="ui-input w-full" @change="onBoqTag(row)">
                                <option value="">No BOQ tag</option>
                                <option v-for="item in boqItems" :key="item.id" :value="item.id">{{ shortLabel(item) }}</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Optional internal tag. Selecting a BOQ item does not change the item name or description.</p>
                            <p v-if="form.errors[`items.${index}.boq_item_id`]" class="mt-1 text-red-700">{{ form.errors[`items.${index}.boq_item_id`] }}</p>
                        </label>
                    </div>

                    <p v-if="rowState(row)" class="text-xs text-slate-500">
                        Tagged BOQ remaining: {{ rowState(row).remaining.toFixed(3) }} {{ rowState(row).item.unit }}
                    </p>
                    <p v-if="rowState(row)?.exceeded" class="text-sm font-semibold text-orange-800">
                        BOQ QUANTITY EXCEEDED — APPROVAL REQUIRED
                    </p>
                </article>

                <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>
            </section>

            <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save Purchase Request</button>
        </form>
    </AdminLayout>
</template>
