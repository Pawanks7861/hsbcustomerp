<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    order: { type: Object, required: true },
    receipt: { type: Object, default: null },
    materials: { type: Array, required: true },
});

const form = useForm({
    receipt_date: props.receipt?.receipt_date || new Date().toISOString().slice(0, 10),
    received_by: props.receipt?.received_by || '',
    challan_number: props.receipt?.challan_number || '',
    challan_date: props.receipt?.challan_date || '',
    vehicle_number: props.receipt?.vehicle_number || '',
    inspection_notes: props.receipt?.inspection_notes || '',
    remarks: props.receipt?.remarks || '',
    images: [],
    items: props.order.items.map((item) => {
        const existing = props.receipt?.items?.find((line) => line.purchase_order_item_id === item.id);

        return {
            purchase_order_item_id: item.id,
            description: item.description,
            unit: item.unit,
            ordered: item.ordered,
            previously_received: item.previously_received,
            remaining: item.remaining,
            material_id: existing?.material_id || '',
            quantity_received: existing?.quantity_received || '',
            quantity_accepted: existing?.quantity_accepted || '',
            quantity_rejected: existing?.quantity_rejected || '',
            remarks: existing?.remarks || '',
        };
    }),
});

function materialsFor(unit) {
    return props.materials.filter((material) => material.unit.toLowerCase() === String(unit || '').toLowerCase());
}

function submit() {
    form.transform((data) => {
        const payload = {
            ...data,
            items: data.items.map((item) => ({
                ...item,
                material_id: item.material_id || null,
            })),
        };

        if (props.receipt) {
            payload._method = 'put';
        }

        return payload;
    });

    const url = props.receipt
        ? `/projects/${props.project.id}/grns/${props.receipt.id}`
        : `/projects/${props.project.id}/purchase-orders/${props.order.id}/grns`;

    form.post(url, { forceFormData: true });
}
</script>

<template>
    <Head title="Goods Receipt Note" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="receipt ? receipt.receipt_number : 'Receive Material'" :eyebrow="order.po_number" />
        </template>
        <form class="space-y-4" @submit.prevent="submit">
            <section class="ui-card grid gap-4 p-5 sm:grid-cols-2">
                <label class="text-sm"><span class="mb-1 block font-medium">Project</span><input :value="project.project_name" class="ui-input w-full" disabled></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Purchase order</span><input :value="order.po_number" class="ui-input w-full" disabled></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Vendor</span><input :value="order.vendor" class="ui-input w-full" disabled></label>
                <label class="text-sm"><span class="mb-1 block font-medium">GRN date</span><input v-model="form.receipt_date" type="date" required class="ui-input w-full"></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Received by</span><input v-model="form.received_by" required class="ui-input w-full"></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Delivery challan number</span><input v-model="form.challan_number" class="ui-input w-full"></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Challan date</span><input v-model="form.challan_date" type="date" class="ui-input w-full"></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Vehicle number</span><input v-model="form.vehicle_number" class="ui-input w-full"></label>
                <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Inspection notes</span><textarea v-model="form.inspection_notes" rows="2" class="ui-input w-full"></textarea></label>
                <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Remarks</span><textarea v-model="form.remarks" rows="2" class="ui-input w-full"></textarea></label>
                <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Delivery images</span><input type="file" multiple accept="image/*" class="ui-input w-full" @change="form.images = Array.from($event.target.files || [])"></label>
                <p class="text-sm text-slate-500 sm:col-span-2">PO receipt status: {{ order.receipt_status }}</p>
            </section>
            <div class="ui-table-wrap overflow-x-auto">
                <table class="min-w-[1100px] w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500">
                            <th class="px-2 py-2">Sr.</th><th class="px-2 py-2">PO Item</th><th class="px-2 py-2">Material</th><th class="px-2 py-2">Unit</th>
                            <th class="px-2 py-2 text-right">Ordered</th><th class="px-2 py-2 text-right">Previously Received</th><th class="px-2 py-2 text-right">Remaining</th>
                            <th class="px-2 py-2 text-right">Received Now</th><th class="px-2 py-2 text-right">Accepted</th><th class="px-2 py-2 text-right">Rejected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in form.items" :key="row.purchase_order_item_id">
                            <td class="px-2 py-2">{{ index + 1 }}</td>
                            <td class="px-2 py-2">{{ row.description }}</td>
                            <td class="px-2 py-2">
                                <select v-model="row.material_id" class="ui-input w-full min-w-40">
                                    <option value="">Not stocked</option>
                                    <option v-for="material in materialsFor(row.unit)" :key="material.id" :value="material.id">{{ material.label }}</option>
                                </select>
                            </td>
                            <td class="px-2 py-2">{{ row.unit || '—' }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ row.ordered }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ row.previously_received }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ row.remaining }}</td>
                            <td class="px-2 py-2"><input v-model="row.quantity_received" type="number" min="0" step="0.001" class="ui-input w-24 text-right"></td>
                            <td class="px-2 py-2"><input v-model="row.quantity_accepted" type="number" min="0" step="0.001" class="ui-input w-24 text-right"></td>
                            <td class="px-2 py-2"><input v-model="row.quantity_rejected" type="number" min="0" step="0.001" class="ui-input w-24 text-right"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>
            <p v-for="(error, key) in form.errors" :key="key" class="text-sm text-red-700">{{ key }}: {{ error }}</p>
            <button class="ui-btn-primary" :disabled="form.processing">Save draft</button>
        </form>
    </AdminLayout>
</template>
