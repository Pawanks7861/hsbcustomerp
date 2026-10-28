<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import TableActionIcon from '../../../Components/TableActionIcon.vue';
import TablePagination from '../../../Components/TablePagination.vue';
import TableToolbar from '../../../Components/TableToolbar.vue';

const props = defineProps({
    locations: { type: Object, required: true },
    filters: { type: Object, required: true },
    projects: { type: Array, required: true },
    types: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
});

const editing = ref(null);
const form = useForm({
    name: '',
    location_type: 'project_store',
    project_id: '',
    address: '',
    responsible_user_id: '',
    is_active: true,
});

function edit(location) {
    editing.value = location.id;
    form.name = location.name;
    form.location_type = location.location_type;
    form.project_id = location.project_id || '';
    form.address = location.address || '';
    form.responsible_user_id = location.responsible_user_id || '';
    form.is_active = location.is_active;
}

function submit() {
    const payload = { ...form.data(), project_id: form.project_id || null, responsible_user_id: form.responsible_user_id || null };

    if (editing.value) {
        form.transform(() => payload).put(`/inventory/locations/${editing.value}`, { onSuccess: reset });
        return;
    }

    form.transform(() => payload).post('/inventory/locations', { onSuccess: reset });
}

function reset() {
    editing.value = null;
    form.reset();
    form.location_type = 'project_store';
    form.is_active = true;
}
</script>

<template>
    <Head title="Locations" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Locations" eyebrow="Inventory">
                <ImportButton module="inventory_locations" permission="inventory.import" return-url="/inventory/locations" />
            </PageHeader>
        </template>
        <div class="grid gap-6 lg:grid-cols-[320px_1fr]">
            <form v-if="canManage" class="ui-card space-y-3 p-4" @submit.prevent="submit">
                <h2 class="font-semibold">{{ editing ? 'Edit location' : 'New location' }}</h2>
                <input v-model="form.name" required placeholder="Location name" class="ui-input w-full">
                <select v-model="form.location_type" class="ui-input w-full">
                    <option v-for="type in types" :key="type" :value="type">{{ type.replaceAll('_', ' ') }}</option>
                </select>
                <select v-model="form.project_id" class="ui-input w-full">
                    <option value="">No project</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option>
                </select>
                <textarea v-model="form.address" rows="2" placeholder="Address" class="ui-input w-full"></textarea>
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Active</label>
                <button class="ui-btn-primary" :disabled="form.processing">Save</button>
                <button v-if="editing" type="button" class="ui-btn-secondary ml-2" @click="reset">Cancel</button>
            </form>
            <div>
            <TableToolbar :search="filters.search" placeholder="Search code, name, type, project, status…" />
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Project</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="locations.data.length === 0"><td colspan="6" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No locations yet.' }}</td></tr>
                        <tr v-for="location in locations.data" :key="location.id">
                            <td>{{ location.location_code }}</td>
                            <td>{{ location.name }}</td>
                            <td>{{ location.location_type }}</td>
                            <td>{{ location.project || '—' }}</td>
                            <td>{{ location.is_active ? 'Active' : 'Inactive' }}</td>
                            <td class="text-right"><TableActionIcon v-if="canManage" action="edit" @click="edit(location)" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <TablePagination :paginator="locations" label="locations" />
            </div>
        </div>
    </AdminLayout>
</template>
