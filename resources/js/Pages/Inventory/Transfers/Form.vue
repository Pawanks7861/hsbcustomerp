<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, default: null },
    transfer: { type: Object, default: null },
    locations: { type: Array, required: true },
});

const stock = ref([]);
const form = useForm({
    transfer_date: props.transfer?.transfer_date || new Date().toISOString().slice(0, 10),
    source_location_id: props.transfer?.source_location_id || '',
    destination_location_id: props.transfer?.destination_location_id || '',
    expected_arrival_date: props.transfer?.expected_arrival_date || '',
    vehicle_number: props.transfer?.vehicle_number || '',
    driver_name: props.transfer?.driver_name || '',
    remarks: props.transfer?.remarks || '',
    items: props.transfer?.items?.length
        ? props.transfer.items.map((item) => ({ material_id: item.material_id, quantity: item.quantity, remarks: item.remarks || '' }))
        : [{ material_id: '', quantity: '', remarks: '' }],
    attachments: [],
});

async function loadStock() {
    stock.value = [];
    if (!form.source_location_id) {
        return;
    }
    const response = await fetch(`/inventory/locations/${form.source_location_id}/stock`, { headers: { Accept: 'application/json' } });
    stock.value = response.ok ? await response.json() : [];
}

if (form.source_location_id) {
    loadStock();
}

function material(id) {
    return stock.value.find((item) => String(item.id) === String(id))
        || props.transfer?.items?.find((item) => String(item.material_id) === String(id));
}

function addItem() {
    form.items.push({ material_id: '', quantity: '', remarks: '' });
}

function removeItem(index) {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
}

function submit() {
    const url = props.transfer ? `/inventory/transfers/${props.transfer.id}` : '/inventory/transfers';
    form.transform((data) => ({ ...data, _method: props.transfer ? 'put' : undefined })).post(url, { forceFormData: true });
}
</script>

<template>
    <Head title="Internal Delivery Note" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="transfer ? 'Edit Internal Delivery Note' : 'New Internal Delivery Note'" eyebrow="Inventory" />
        </template>
        <form class="space-y-6" @submit.prevent="submit">
            <section class="ui-card grid gap-4 p-5 md:grid-cols-2">
                <label class="text-sm">Transfer date<input v-model="form.transfer_date" type="date" required class="ui-input w-full"></label>
                <label class="text-sm">Expected arrival<input v-model="form.expected_arrival_date" type="date" class="ui-input w-full"></label>
                <label class="text-sm">Source location
                    <select v-model="form.source_location_id" required class="ui-input w-full" @change="loadStock">
                        <option value="">Select</option>
                        <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                    </select>
                </label>
                <label class="text-sm">Destination location
                    <select v-model="form.destination_location_id" required class="ui-input w-full">
                        <option value="">Select</option>
                        <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                    </select>
                </label>
                <label class="text-sm">Vehicle<input v-model="form.vehicle_number" class="ui-input w-full"></label>
                <label class="text-sm">Driver<input v-model="form.driver_name" class="ui-input w-full"></label>
                <label class="text-sm md:col-span-2">Remarks<textarea v-model="form.remarks" rows="2" class="ui-input w-full"></textarea></label>
                <label class="text-sm md:col-span-2">Attachments<input type="file" multiple class="ui-input w-full" @change="form.attachments = Array.from($event.target.files || [])"></label>
            </section>
            <section class="ui-card space-y-3 p-5">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Materials</h2>
                    <button type="button" class="ui-btn-secondary" @click="addItem">Add Item</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-[760px] w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="px-2 py-2">Material</th>
                                <th class="px-2 py-2">Unit</th>
                                <th class="px-2 py-2">Available</th>
                                <th class="px-2 py-2">Transfer qty</th>
                                <th class="px-2 py-2">Remarks</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in form.items" :key="index">
                                <td class="px-2 py-2">
                                    <select v-model="row.material_id" required class="ui-input w-full min-w-56">
                                        <option value="">Select material</option>
                                        <option v-for="item in stock" :key="item.id" :value="item.id">{{ item.code }} — {{ item.name }}</option>
                                    </select>
                                </td>
                                <td class="px-2 py-2">{{ material(row.material_id)?.unit || '—' }}</td>
                                <td class="num-cell px-2 py-2">{{ material(row.material_id)?.available || '—' }}</td>
                                <td class="px-2 py-2"><input v-model="row.quantity" type="number" min="0.001" step="0.001" required class="ui-input num-input"></td>
                                <td class="px-2 py-2"><input v-model="row.remarks" class="ui-input w-full"></td>
                                <td class="px-2 py-2"><button v-if="form.items.length > 1" type="button" class="text-red-700" @click="removeItem(index)">Remove</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>
            </section>
            <button class="ui-btn-primary" :disabled="form.processing">Save draft</button>
        </form>
    </AdminLayout>
</template>
