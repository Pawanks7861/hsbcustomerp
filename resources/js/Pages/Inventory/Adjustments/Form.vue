<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    materials: { type: Array, required: true },
});

const form = useForm({
    material_id: '',
    adjustment_date: new Date().toISOString().slice(0, 10),
    adjustment_type: 'opening',
    quantity: '',
    reason: '',
    attachment: null,
});

function submit() {
    form.post(`/projects/${props.project.id}/inventory/adjustments`, { forceFormData: true });
}
</script>

<template>
    <Head title="New Stock Adjustment" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="New Stock Adjustment" :eyebrow="project.project_name" />
        </template>
        <form class="ui-card grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="submit">
            <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Material</span>
                <select v-model="form.material_id" required class="ui-input w-full">
                    <option value="">Select material</option>
                    <option v-for="material in materials" :key="material.id" :value="material.id">{{ material.material_code }} {{ material.name }} ({{ material.unit }})</option>
                </select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Date</span><input v-model="form.adjustment_date" type="date" required class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Type</span>
                <select v-model="form.adjustment_type" class="ui-input w-full">
                    <option value="opening">Opening stock</option>
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Quantity</span><input v-model="form.quantity" type="number" min="0.001" step="0.001" required class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Attachment</span><input type="file" class="ui-input w-full" @change="form.attachment = $event.target.files[0]"></label>
            <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Reason</span><textarea v-model="form.reason" required rows="3" class="ui-input w-full"></textarea></label>
            <button class="ui-btn-primary sm:col-span-2" :disabled="form.processing">Save draft</button>
        </form>
    </AdminLayout>
</template>
