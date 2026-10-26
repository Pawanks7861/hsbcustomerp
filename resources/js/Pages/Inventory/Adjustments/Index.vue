<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import TablePagination from '../../../Components/TablePagination.vue';
import TableToolbar from '../../../Components/TableToolbar.vue';

const props = defineProps({
    project: { type: Object, default: null },
    central: { type: Boolean, default: false },
    store: { type: Object, default: null },
    adjustments: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <Head title="Stock Adjustments" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Stock Adjustments" :eyebrow="central ? (store?.name || 'Central Store') : project?.project_name">
                <ImportButton :module="central ? 'central_store_stock' : 'stock_adjustments'" permission="inventory.import" label="Import CSV/Excel" :return-url="central ? '/inventory/central/adjustments' : `/projects/${project.id}/inventory/adjustments`" :project-id="project?.id" />
                <Link :href="central ? '/inventory/central/adjustments/create?type=opening' : `/projects/${project.id}/inventory/adjustments/create?type=opening`" class="ui-btn-primary">Add Opening Stock</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search number, date, material, location, type…" />
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th>Number</th><th>Date</th><th>Material</th><th>Location</th><th>Type</th><th class="text-right">Quantity</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-if="adjustments.data.length === 0"><td colspan="7" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No stock adjustments.' }}</td></tr>
                    <tr v-for="row in adjustments.data" :key="row.id">
                        <td><Link :href="central ? `/inventory/central/adjustments/${row.id}` : `/projects/${project.id}/inventory/adjustments/${row.id}`" class="text-amber-700 hover:underline">{{ row.adjustment_number }}</Link></td>
                        <td>{{ row.adjustment_date }}</td>
                        <td>{{ row.material }}</td>
                        <td>{{ row.location || '—' }}</td>
                        <td class="capitalize">{{ row.adjustment_type }}</td>
                        <td class="text-right tabular-nums">{{ row.quantity }}</td>
                        <td class="capitalize">{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="adjustments" label="adjustments" />
    </AdminLayout>
</template>
