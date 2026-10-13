<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { formatInr, formatQty, lineAmount } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    item: { type: Object, required: true },
    purchaseRequest: { type: Object, default: null },
    quantityControl: { type: Object, required: true },
});

function blankLine(serviceCode = '') {
    return { description: '', service_code: serviceCode, unit: '', quantity: '', estimated_rate: '', remarks: '' };
}

const form = useForm({
    request_date: props.purchaseRequest?.request_date ?? new Date().toISOString().slice(0, 10),
    required_date: props.purchaseRequest?.required_date ?? '',
    description: props.purchaseRequest?.description ?? '',
    requested_boq_quantity: props.purchaseRequest?.requested_boq_quantity ?? '',
    items: props.purchaseRequest?.items?.length
        ? props.purchaseRequest.items.map((line) => ({
            description: line.description,
            service_code: line.service_code ?? '',
            unit: line.unit ?? '',
            quantity: line.quantity,
            estimated_rate: line.estimated_rate ?? '',
            remarks: line.remarks ?? '',
        }))
        : [blankLine(props.item.service_code ?? '')],
});

function toMilli(value) {
    if (value === '' || value === null || value === undefined) {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) ? Math.round(number * 1000) : null;
}

const preview = computed(() => {
    if (!props.quantityControl.has_quantity) {
        return null;
    }

    const boq = toMilli(props.quantityControl.boq_quantity);
    const previous = toMilli(props.quantityControl.used_quantity) ?? 0;
    const current = toMilli(form.requested_boq_quantity);

    if (boq === null || current === null) {
        return null;
    }

    const cumulative = previous + current;
    const excess = Math.max(0, cumulative - boq);

    return {
        cumulative: cumulative / 1000,
        excess: excess / 1000,
        exceeded: excess > 0,
    };
});

const unit = computed(() => props.quantityControl.unit || props.item.unit || '');

function submit() {
    if (props.purchaseRequest?.id) {
        form.put(`/projects/${props.project.id}/purchase-requests/${props.purchaseRequest.id}`);
        return;
    }

    form.post(`/projects/${props.project.id}/boqs/${props.boq.id}/items/${props.item.id}/purchase-requests`);
}
</script>

<template>
    <Head :title="purchaseRequest ? 'Edit Purchase Request' : 'Create Purchase Request'" />
    <AdminLayout :project="project">
        <template #header>
            <div>
                <h1 class="text-xl font-semibold">{{ purchaseRequest ? 'Edit purchase request' : 'Create purchase request' }}</h1>
                <p class="text-sm text-slate-500">{{ item.item_number }} — {{ item.description }}</p>
            </div>
        </template>

        <form class="space-y-5" @submit.prevent="submit">
            <div class="ui-card grid gap-5 p-6 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">Request date</span>
                    <input v-model="form.request_date" type="date" required class="ui-input">
                    <span v-if="form.errors.request_date" class="mt-1 block text-red-700">{{ form.errors.request_date }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Required date</span>
                    <input v-model="form.required_date" type="date" class="ui-input">
                </label>
                <label class="block text-sm sm:col-span-2">
                    <span class="font-medium">Description</span>
                    <textarea v-model="form.description" rows="2" class="ui-textarea"></textarea>
                </label>
            </div>

            <section
                class="rounded-2xl border p-5"
                :class="!quantityControl.has_quantity || preview?.exceeded ? 'border-orange-200 bg-orange-50' : 'border-emerald-100 bg-emerald-50/60'"
            >
                <h2 class="text-sm font-semibold" :class="preview?.exceeded ? 'text-orange-900' : 'text-slate-800'">
                    {{ preview?.exceeded ? 'BOQ Quantity Exceeded' : 'BOQ Quantity Control' }}
                </h2>
                <p v-if="!quantityControl.has_quantity" class="mt-2 text-sm text-orange-800">
                    This BOQ item does not have a quantity defined.
                </p>
                <dl v-else class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-slate-500">BOQ Quantity</dt>
                        <dd class="font-medium tabular-nums">{{ formatQty(quantityControl.boq_quantity, unit) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Already Requested</dt>
                        <dd class="font-medium tabular-nums">{{ formatQty(quantityControl.used_quantity, unit) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Available Quantity</dt>
                        <dd class="font-medium tabular-nums">{{ formatQty(quantityControl.remaining_quantity, unit) }}</dd>
                    </div>
                    <label class="block sm:col-span-3">
                        <span class="font-medium">Requested BOQ Quantity</span>
                        <input v-model="form.requested_boq_quantity" type="number" min="0" step="0.001" required class="ui-input mt-1 max-w-xs">
                        <span v-if="form.errors.requested_boq_quantity" class="mt-1 block text-red-700">{{ form.errors.requested_boq_quantity }}</span>
                    </label>
                    <div>
                        <dt class="text-slate-500">After This PR</dt>
                        <dd class="font-medium tabular-nums">{{ preview ? formatQty(preview.cumulative, unit) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Excess Quantity</dt>
                        <dd class="font-semibold tabular-nums" :class="preview?.exceeded ? 'text-orange-800' : ''">{{ preview ? formatQty(preview.excess, unit) : '—' }}</dd>
                    </div>
                </dl>
                <p v-if="preview && !preview.exceeded" class="mt-4 text-sm text-emerald-800">Within BOQ Quantity</p>
                <div v-else-if="preview?.exceeded" class="mt-4 space-y-1 text-sm text-orange-900">
                    <p>
                        Requested cumulative quantity is {{ formatQty(preview.cumulative, unit) }} against BOQ quantity of {{ formatQty(quantityControl.boq_quantity, unit) }}.
                    </p>
                    <p>Excess Quantity: {{ formatQty(preview.excess, unit) }}</p>
                    <p>This PR requires explicit approval.</p>
                </div>
            </section>

            <div class="ui-table-wrap">
                <table class="min-w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Service Code</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Description</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Unit</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Qty</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Estimated Rate</th>
                            <th class="px-3 py-2 font-medium text-slate-500 text-right">Estimated Amount</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Remarks</th>
                            <th class="px-3 py-2 font-medium text-slate-500"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.items" :key="index">
                            <td class="border border-slate-200 px-2 py-2">
                                <input v-model="line.service_code" type="text" class="w-28 rounded border border-slate-300 px-2 py-1">
                            </td>
                            <td class="border border-slate-200 px-2 py-2">
                                <input v-model="line.description" type="text" required class="w-full rounded border border-slate-300 px-2 py-1">
                            </td>
                            <td class="border border-slate-200 px-2 py-2">
                                <input v-model="line.unit" type="text" class="w-24 rounded border border-slate-300 px-2 py-1">
                            </td>
                            <td class="border border-slate-200 px-2 py-2">
                                <input v-model="line.quantity" type="number" min="0" step="0.001" required class="w-24 rounded border border-slate-300 px-2 py-1 text-right">
                            </td>
                            <td class="border border-slate-200 px-2 py-2">
                                <input v-model="line.estimated_rate" type="number" min="0" step="0.01" class="w-28 rounded border border-slate-300 px-2 py-1 text-right">
                            </td>
                            <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">
                                {{ lineAmount(line.quantity, line.estimated_rate) === null ? '—' : formatInr(lineAmount(line.quantity, line.estimated_rate)) }}
                            </td>
                            <td class="border border-slate-200 px-2 py-2">
                                <input v-model="line.remarks" type="text" class="w-full rounded border border-slate-300 px-2 py-1">
                            </td>
                            <td class="border border-slate-200 px-2 py-2">
                                <button v-if="form.items.length > 1" type="button" class="text-red-700 hover:underline" @click="form.items.splice(index, 1)">Remove</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>

            <div class="flex flex-wrap items-center gap-4">
                <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm" @click="form.items.push(blankLine())">Add line</button>
                <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save draft</button>
                <Link :href="purchaseRequest?.id ? `/projects/${project.id}/purchase-requests/${purchaseRequest.id}` : `/projects/${project.id}/boqs/${boq.id}/items/${item.id}/procurement`" class="text-sm text-slate-600 hover:underline">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
