<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    materials: { type: Array, required: true },
});

const form = useForm({
    issue_date: new Date().toISOString().slice(0, 10),
    issued_to: '',
    purpose: '',
    items: [{ material_id: '', quantity: '', remarks: '' }],
});

function material(id) {
    return props.materials.find((row) => String(row.id) === String(id));
}

function submit() {
    form.post(`/projects/${props.project.id}/inventory/issues`);
}
</script>

<template>
    <Head title="New Material Issue" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="New Material Issue" :eyebrow="project.project_name" />
        </template>
        <form class="ui-card space-y-4 p-5" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm"><span class="mb-1 block font-medium">Issue date</span><input v-model="form.issue_date" type="date" required class="ui-input w-full"></label>
                <label class="text-sm"><span class="mb-1 block font-medium">Issued to</span><input v-model="form.issued_to" required class="ui-input w-full"></label>
                <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Purpose / remarks</span><textarea v-model="form.purpose" rows="2" class="ui-input w-full"></textarea></label>
            </div>
            <div v-for="(row, index) in form.items" :key="index" class="grid gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3">
                <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Material</span>
                    <select v-model="row.material_id" required class="ui-input w-full">
                        <option value="">Select material</option>
                        <option v-for="item in materials" :key="item.id" :value="item.id">{{ item.label }} ({{ item.available }} {{ item.unit }})</option>
                    </select>
                </label>
                <label class="text-sm"><span class="mb-1 block font-medium">Issue quantity</span><input v-model="row.quantity" type="number" min="0.001" step="0.001" required class="ui-input w-full"></label>
                <p class="text-sm text-slate-500 sm:col-span-3">Available: {{ material(row.material_id)?.available || '0.000' }} {{ material(row.material_id)?.unit || '' }}</p>
                <p v-if="form.errors[`items.${index}.quantity`]" class="text-sm text-red-700 sm:col-span-3">{{ form.errors[`items.${index}.quantity`] }}</p>
            </div>
            <p v-if="form.errors.quantity" class="text-sm text-red-700">{{ form.errors.quantity }}</p>
            <button class="ui-btn-primary" :disabled="form.processing">Save draft</button>
        </form>
    </AdminLayout>
</template>
