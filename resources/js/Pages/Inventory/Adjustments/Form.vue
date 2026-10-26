<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, default: null },
    central: { type: Boolean, default: false },
    store: { type: Object, default: null },
    materials: { type: Array, required: true },
    locations: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
});

const form = useForm({
    material_id: props.defaults.material_id ? String(props.defaults.material_id) : '',
    location_id: props.defaults.location_id ? String(props.defaults.location_id) : '',
    adjustment_date: new Date().toISOString().slice(0, 10),
    adjustment_type: props.defaults.adjustment_type || 'opening',
    quantity: '',
    reference_number: '',
    reason: '',
    attachment: null,
});

const selectedMaterial = computed(() => props.materials.find((material) => String(material.id) === String(form.material_id)));
const isOpening = computed(() => form.adjustment_type === 'opening');
const title = computed(() => (isOpening.value ? 'Add Opening Stock' : 'New Stock Adjustment'));

function submit() {
    form.post(props.central ? '/inventory/central/adjustments' : `/projects/${props.project.id}/inventory/adjustments`, { forceFormData: true });
}
</script>

<template>
    <Head :title="title" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="title" :eyebrow="central ? (store?.name || 'Central Store') : project?.project_name" />
        </template>
        <form class="ui-card grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit">
            <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Item</span>
                <select v-model="form.material_id" required class="ui-input w-full">
                    <option value="">Select item</option>
                    <option v-for="material in materials" :key="material.id" :value="String(material.id)">{{ material.material_code }} {{ material.name }}</option>
                </select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Location / Store</span>
                <select v-model="form.location_id" required class="ui-input w-full">
                    <option value="">Select location</option>
                    <option v-for="location in locations" :key="location.id" :value="String(location.id)">{{ location.name }} ({{ location.location_code }})</option>
                </select>
                <p v-if="locations.length === 0" class="mt-1 text-sm text-rose-600">Create a location first from Inventory → Locations.</p>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Unit</span>
                <input :value="selectedMaterial?.unit || '—'" readonly class="ui-input w-full bg-slate-50">
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Entry Date</span><input v-model="form.adjustment_date" type="date" required class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Type</span>
                <select v-model="form.adjustment_type" class="ui-input w-full">
                    <option value="opening">Opening stock</option>
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Quantity</span><input v-model="form.quantity" type="number" min="0.001" step="0.001" required class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Reference Number</span><input v-model="form.reference_number" maxlength="80" class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Attachment</span><input type="file" class="ui-input w-full" @change="form.attachment = $event.target.files[0]"></label>
            <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Remarks</span>
                <textarea v-model="form.reason" :required="!isOpening" rows="3" class="ui-input w-full" :placeholder="isOpening ? 'Optional explanation' : 'Required for increases and decreases'"></textarea>
            </label>
            <button class="ui-btn-primary sm:col-span-2" :disabled="form.processing || locations.length === 0">Save draft</button>
        </form>
    </AdminLayout>
</template>
