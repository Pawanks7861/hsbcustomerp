<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    filters: { type: Object, required: true },
    units: { type: Array, required: true },
    materials: { type: Array, required: true },
});

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const editing = ref(null);

const form = useForm({
    name: '',
    category: '',
    description: '',
    unit: props.units[0] || 'Nos',
    minimum_stock: '0',
    is_active: true,
});

function apply() {
    router.get(`/projects/${props.project.id}/inventory/materials`, {
        search: search.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true });
}

function edit(material) {
    editing.value = material.id;
    form.name = material.name;
    form.category = material.category || '';
    form.description = material.description || '';
    form.unit = material.unit;
    form.minimum_stock = material.minimum_stock;
    form.is_active = material.is_active;
}

function reset() {
    editing.value = null;
    form.reset();
    form.unit = props.units[0] || 'Nos';
    form.minimum_stock = '0';
    form.is_active = true;
}

function save() {
    const url = editing.value
        ? `/projects/${props.project.id}/inventory/materials/${editing.value}`
        : `/projects/${props.project.id}/inventory/materials`;
    const method = editing.value ? form.put : form.post;

    method.call(form, url, { onSuccess: () => reset() });
}

function destroy(material) {
    if (!confirm(`Delete ${material.material_code}?`)) {
        return;
    }

    router.delete(`/projects/${props.project.id}/inventory/materials/${material.id}`);
}
</script>

<template>
    <Head title="Material Master" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Material Master" :eyebrow="project.project_name" />
        </template>

        <form class="ui-card mb-4 grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="save">
            <label class="text-sm"><span class="mb-1 block font-medium">Material name</span><input v-model="form.name" required class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Category</span><input v-model="form.category" class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Unit</span>
                <select v-model="form.unit" class="ui-input w-full"><option v-for="unit in units" :key="unit" :value="unit">{{ unit }}</option></select>
            </label>
            <label class="text-sm"><span class="mb-1 block font-medium">Minimum stock</span><input v-model="form.minimum_stock" type="number" min="0" step="0.001" required class="ui-input w-full"></label>
            <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Description</span><textarea v-model="form.description" rows="2" class="ui-input w-full"></textarea></label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Active</label>
            <div class="flex gap-2">
                <button class="ui-btn-primary" type="submit" :disabled="form.processing">{{ editing ? 'Update material' : 'Create material' }}</button>
                <button v-if="editing" class="ui-btn-secondary" type="button" @click="reset">Cancel</button>
            </div>
            <p v-if="form.errors.name" class="text-sm text-red-700 sm:col-span-2">{{ form.errors.name }}</p>
        </form>

        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm"><span class="mb-1 block font-medium">Search</span><input v-model="search" class="ui-input w-56" placeholder="Code, name, category"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Status</span>
                <select v-model="status" class="ui-input w-36"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </label>
            <button class="ui-btn-secondary" type="submit">Filter</button>
        </form>

        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th class="text-right">Minimum</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-if="materials.length === 0"><td colspan="7" class="py-8 text-center text-slate-400">No materials.</td></tr>
                    <tr v-for="material in materials" :key="material.id">
                        <td>{{ material.material_code }}</td>
                        <td>{{ material.name }}</td>
                        <td>{{ material.category || '—' }}</td>
                        <td>{{ material.unit }}</td>
                        <td class="text-right tabular-nums">{{ material.minimum_stock }}</td>
                        <td>{{ material.is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="space-x-3">
                            <button type="button" class="text-amber-700" @click="edit(material)">Edit</button>
                            <button type="button" class="text-red-700" @click="destroy(material)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
