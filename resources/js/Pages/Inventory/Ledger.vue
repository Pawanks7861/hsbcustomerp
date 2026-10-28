<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { visitListing } from '../../lib/listing';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, default: null },
    central: { type: Boolean, default: false },
    store: { type: Object, default: null },
    materials: { type: Array, required: true },
    locations: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    entries: { type: Object, required: true },
});

const materialId = ref(props.filters.material_id || '');
const locationId = ref(props.filters.location_id || '');

function apply() {
    visitListing({ material_id: materialId.value, location_id: locationId.value });
}
</script>

<template>
    <Head title="Stock Ledger" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Stock Ledger" :eyebrow="central ? (store?.name || 'Central Store') : project?.project_name" />
        </template>
        <TableToolbar :search="filters.search" placeholder="Search transaction, date, material, type, source, cost, remarks…" />
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm"><span class="mb-1 block font-medium">Material</span>
                <select v-model="materialId" class="ui-input w-72">
                    <option value="">All materials</option>
                    <option v-for="material in materials" :key="material.id" :value="material.id">{{ material.material_code }} {{ material.name }}</option>
                </select>
            </label>
            <label v-if="!central" class="text-sm"><span class="mb-1 block font-medium">Location</span>
                <select v-model="locationId" class="ui-input w-72">
                    <option value="">All locations</option>
                    <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.name }}</option>
                </select>
            </label>
            <button class="ui-btn-secondary" type="submit">Filter</button>
        </form>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[1180px]">
                <thead>
                    <tr>
                        <th>Transaction</th><th>Date</th><th>Material</th><th>Location</th><th>Type</th><th>Source</th>
                        <th class="text-right">Stock In</th><th class="text-right">Stock Out</th><th class="text-right">Balance</th>
                        <th class="text-right">Unit cost</th><th class="text-right">Amount</th><th>Cost basis</th>
                        <th>Posted By</th><th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.data.length === 0"><td colspan="14" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No ledger entries.' }}</td></tr>
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td>{{ entry.transaction_number }}</td>
                        <td>{{ entry.transaction_date }}</td>
                        <td>{{ entry.material }}</td>
                        <td>{{ entry.location || '—' }}</td>
                        <td>{{ entry.transaction_type }}</td>
                        <td>{{ entry.source_number }}</td>
                        <td class="text-right tabular-nums">{{ entry.stock_in }}</td>
                        <td class="text-right tabular-nums">{{ entry.stock_out }}</td>
                        <td class="text-right tabular-nums">{{ entry.balance }}</td>
                        <td class="text-right tabular-nums">{{ entry.unit_cost != null && entry.unit_cost !== '' ? formatInr(entry.unit_cost) : '—' }}</td>
                        <td class="text-right tabular-nums">{{ entry.amount != null && entry.amount !== '' ? formatInr(entry.amount) : '—' }}</td>
                        <td>{{ entry.is_estimated ? 'Estimated' : (entry.amount != null ? 'Actual' : '—') }}</td>
                        <td>{{ entry.posted_by || '—' }}</td>
                        <td>{{ entry.remarks || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="entries" label="entries" />
    </AdminLayout>
</template>
