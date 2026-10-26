<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import MaterialUnitSelect from '../../../Components/MaterialUnitSelect.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import TablePagination from '../../../Components/TablePagination.vue';
import TableToolbar from '../../../Components/TableToolbar.vue';
import { visitListing } from '../../../lib/listing';

const props = defineProps({
    project: { type: Object, default: null },
    central: { type: Boolean, default: false },
    filters: { type: Object, required: true },
    units: { type: Array, required: true },
    materials: { type: Object, required: true },
    canCreateUnit: { type: Boolean, default: false },
});

const editing = ref(null);
const units = ref([...props.units]);

const form = useForm({
    name: '',
    category: '',
    description: '',
    unit: props.units[0] || 'Nos',
    opening_quantity: '',
    is_active: true,
});

function basePath() {
    return props.project ? `/projects/${props.project.id}/inventory/materials` : '/inventory/materials';
}

function edit(material) {
    editing.value = material.id;
    form.name = material.name;
    form.category = material.category || '';
    form.description = material.description || '';
    form.unit = material.unit;
    form.opening_quantity = '';
    form.is_active = material.is_active;
}

function reset() {
    editing.value = null;
    form.reset();
    form.unit = units.value[0] || 'Nos';
    form.opening_quantity = '';
    form.is_active = true;
}

function save() {
    const url = editing.value ? `${basePath()}/${editing.value}` : basePath();
    const method = editing.value ? form.put : form.post;

    method.call(form, url, { onSuccess: () => reset() });
}

function destroy(material) {
    if (!confirm(`Delete ${material.material_code}?`)) {
        return;
    }

    router.delete(`${basePath()}/${material.id}`, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head :title="central ? 'Central Store materials' : 'Material Master'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="central ? 'Material Master' : 'Material Master'" :eyebrow="central ? 'Central Store' : project?.project_name">
                <ImportButton
                    :module="central ? 'central_store_stock' : 'materials'"
                    permission="inventory.import"
                    :return-url="basePath()"
                    :project-id="project?.id"
                />
            </PageHeader>
        </template>

        <form class="ui-card mb-4 grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="save">
            <label class="text-sm"><span class="mb-1 block font-medium">Material name</span><input v-model="form.name" required class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Category</span><input v-model="form.category" class="ui-input w-full"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">Unit</span>
                <MaterialUnitSelect v-model="form.unit" :units="units" :can-create="canCreateUnit" :error="form.errors.unit" @units="units = $event" />
            </label>
            <label v-if="central && !editing" class="text-sm"><span class="mb-1 block font-medium">Opening quantity</span>
                <input v-model="form.opening_quantity" type="number" min="0.001" step="0.001" class="ui-input w-full" placeholder="Optional">
                <span class="mt-1 block text-xs text-slate-500">Leave blank to add the material without creating stock.</span>
            </label>
            <p v-if="central" class="text-sm text-slate-500 sm:col-span-2">Stock is recorded against the Central Store. A project is not required.</p>
            <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Description</span><textarea v-model="form.description" rows="2" class="ui-input w-full"></textarea></label>
            <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Active</label>
            <div class="flex gap-2">
                <button class="ui-btn-primary" type="submit" :disabled="form.processing">{{ editing ? 'Update material' : 'Create material' }}</button>
                <button v-if="editing" class="ui-btn-secondary" type="button" @click="reset">Cancel</button>
            </div>
            <p v-if="form.errors.name" class="text-sm text-red-700 sm:col-span-2">{{ form.errors.name }}</p>
            <p v-if="form.errors.opening_quantity" class="text-sm text-red-700 sm:col-span-2">{{ form.errors.opening_quantity }}</p>
        </form>

        <TableToolbar :search="filters.search" placeholder="Search code, name, category, unit…">
            <label class="text-sm"><span class="sr-only">Status</span>
                <select class="ui-input w-36" :value="filters.status || ''" @change="visitListing({ status: $event.target.value })"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </label>
        </TableToolbar>

        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-if="materials.data.length === 0"><td colspan="6" class="py-8 text-center text-slate-400">{{ filters.search || filters.status ? 'No matching records found.' : 'No materials.' }}</td></tr>
                    <tr v-for="material in materials.data" :key="material.id">
                        <td>{{ material.material_code }}</td>
                        <td>{{ material.name }}</td>
                        <td>{{ material.category || '—' }}</td>
                        <td>{{ material.unit }}</td>
                        <td>{{ material.is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="space-x-3">
                            <button type="button" class="text-amber-700" @click="edit(material)">Edit</button>
                            <button type="button" class="text-red-700" @click="destroy(material)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="materials" label="materials" />
    </AdminLayout>
</template>
