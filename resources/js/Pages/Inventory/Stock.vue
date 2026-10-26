<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    project: { type: Object, required: true },
    locations: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    rows: { type: Object, required: true },
    canAdjust: { type: Boolean, default: false },
    canImport: { type: Boolean, default: false },
});

const page = usePage();
const locationId = ref(props.filters.location_id ? String(props.filters.location_id) : '');
const can = (permission) => {
    const user = page.props.auth?.user;
    if (!user) return false;
    if (user.is_admin) return true;
    return (user.permissions || []).includes(permission);
};

const createUrl = computed(() => {
    const params = new URLSearchParams({ type: 'opening' });
    if (locationId.value) params.set('location_id', locationId.value);
    return `/projects/${props.project.id}/inventory/adjustments/create?${params.toString()}`;
});

function apply() {
    visitListing({ location_id: locationId.value });
}

function tone(status) {
    if (status === 'Out of Stock') return 'text-red-700';
    if (status === 'Low Stock') return 'text-orange-700';
    return 'text-emerald-700';
}
</script>

<template>
    <Head title="Stock Overview" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Stock Overview" :eyebrow="project.project_name">
                <ImportButton
                    v-if="canImport || can('inventory.import')"
                    module="stock_adjustments"
                    permission="inventory.import"
                    :return-url="`/projects/${project.id}/inventory/stock`"
                    :project-id="project.id"
                    label="Import CSV/Excel"
                />
                <Link
                    v-if="canAdjust || can('inventory.adjust')"
                    :href="createUrl"
                    class="ui-btn-primary"
                >Add Opening Stock</Link>
            </PageHeader>
        </template>

        <TableToolbar :search="filters.search" placeholder="Search material, code, location, status, quantity…" />
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm">
                <span class="mb-1 block font-medium">Location</span>
                <select v-model="locationId" class="ui-input w-72">
                    <option value="">All locations</option>
                    <option v-for="location in locations" :key="location.id" :value="String(location.id)">
                        {{ location.name }} ({{ location.location_code }})
                    </option>
                </select>
            </label>
            <button class="ui-btn-secondary" type="submit">Filter</button>
            <p v-if="locations.length === 0" class="text-sm text-slate-500">Create a location before recording stock.</p>
        </form>

        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[1080px]">
                <thead>
                    <tr>
                        <th>Material Code</th>
                        <th>Material Name</th>
                        <th>Unit</th>
                        <th>Location</th>
                        <th class="text-right">Location Qty</th>
                        <th class="text-right">Total Qty</th>
                        <th class="text-right">Total Received</th>
                        <th class="text-right">Total Issued</th>
                        <th class="text-right">Adjustments</th>
                        <th class="text-right">Minimum Stock</th>
                        <th>Stock Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.data.length === 0">
                        <td colspan="11" class="py-8 text-center text-slate-400">
                            <template v-if="filters.search">No matching records found.</template>
                            <template v-else>No stock has been posted for this project{{ locationId ? ' at the selected location' : '' }}.</template>
                        </td>
                    </tr>
                    <tr v-for="row in rows.data" :key="`${row.material_id}-${row.location_id}`">
                        <td>{{ row.material_code }}</td>
                        <td>{{ row.material_name }}</td>
                        <td>{{ row.unit }}</td>
                        <td>{{ row.location }}</td>
                        <td class="text-right tabular-nums">{{ row.available }}</td>
                        <td class="text-right tabular-nums">{{ row.total_available }}</td>
                        <td class="text-right tabular-nums">{{ row.total_received }}</td>
                        <td class="text-right tabular-nums">{{ row.total_issued }}</td>
                        <td class="text-right tabular-nums">{{ row.adjustments }}</td>
                        <td class="text-right tabular-nums">{{ row.minimum_stock }}</td>
                        <td :class="tone(row.status)">{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="rows" label="stock rows" />
    </AdminLayout>
</template>
