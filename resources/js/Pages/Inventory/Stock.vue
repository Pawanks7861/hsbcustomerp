<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { visitListing } from '../../lib/listing';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, default: null },
    central: { type: Boolean, default: false },
    store: { type: Object, default: null },
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
    return props.central
        ? `/inventory/central/adjustments/create?${params.toString()}`
        : `/projects/${props.project.id}/inventory/adjustments/create?${params.toString()}`;
});

function apply() {
    visitListing({ location_id: locationId.value });
}

function tone(status) {
    if (status === 'Out of Stock') return 'text-red-700';
    return 'text-emerald-700';
}
</script>

<template>
    <Head :title="central ? 'Central Store' : 'Stock Overview'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="central ? 'Central Store' : 'Stock Overview'" :eyebrow="central ? (store?.name || 'Independent of projects') : project?.project_name">
                <a v-if="central" href="/inventory/central/export" class="ui-btn-secondary">Export CSV</a>
                <ImportButton
                    v-if="canImport || can('inventory.import')"
                    :module="central ? 'central_store_stock' : 'stock_adjustments'"
                    permission="inventory.import"
                    :return-url="central ? '/inventory/central' : `/projects/${project.id}/inventory/stock`"
                    :project-id="project?.id"
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
        <form v-if="!central" class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
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
            <table class="ui-table min-w-[980px]">
                <thead>
                    <tr>
                        <th>Material Code</th>
                        <th>Material Name</th>
                        <th>Class</th>
                        <th>Unit</th>
                        <th>Location</th>
                        <th class="text-right">Location Qty</th>
                        <th class="text-right">Total Qty</th>
                        <th class="text-right">Default price</th>
                        <th class="text-right">Valuation unit cost</th>
                        <th class="text-right">Stock value</th>
                        <th>Cost basis</th>
                        <th class="text-right">Total Received</th>
                        <th class="text-right">Total Issued</th>
                        <th class="text-right">Adjustments</th>
                        <th>Stock Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.data.length === 0">
                        <td colspan="15" class="py-8 text-center text-slate-400">
                            <template v-if="filters.search">No matching records found.</template>
                            <template v-else-if="central">No stock has been posted to the Central Store.</template>
                            <template v-else>No stock has been posted for this project{{ locationId ? ' at the selected location' : '' }}.</template>
                        </td>
                    </tr>
                    <tr v-for="row in rows.data" :key="`${row.material_id}-${row.location_id}`">
                        <td>{{ row.material_code }}</td>
                        <td>{{ row.material_name }}</td>
                        <td class="capitalize">{{ row.classification || 'consumable' }}</td>
                        <td>{{ row.unit }}</td>
                        <td>{{ row.location }}</td>
                        <td class="text-right tabular-nums">{{ row.available }}</td>
                        <td class="text-right tabular-nums">{{ row.total_available }}</td>
                        <td class="text-right tabular-nums">{{ row.default_unit_price != null && row.default_unit_price !== '' ? formatInr(row.default_unit_price) : '—' }}</td>
                        <td class="text-right tabular-nums">{{ row.unit_cost != null && row.unit_cost !== '' ? formatInr(row.unit_cost) : '—' }}</td>
                        <td class="text-right tabular-nums">{{ row.stock_value != null && row.stock_value !== '' ? formatInr(row.stock_value) : '—' }}</td>
                        <td>{{ row.cost_basis || '—' }}</td>
                        <td class="text-right tabular-nums">{{ row.total_received }}</td>
                        <td class="text-right tabular-nums">{{ row.total_issued }}</td>
                        <td class="text-right tabular-nums">{{ row.adjustments }}</td>
                        <td :class="tone(row.status)">{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="rows" label="stock rows" />
    </AdminLayout>
</template>
