<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import PageHeader from '../../../Components/PageHeader.vue';

defineProps({
    project: { type: Object, required: true },
    adjustments: { type: Array, required: true },
});
</script>

<template>
    <Head title="Stock Adjustments" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Stock Adjustments" :eyebrow="project.project_name">
                <ImportButton module="stock_adjustments" permission="inventory.import" label="Import CSV/Excel" :return-url="`/projects/${project.id}/inventory/adjustments`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/inventory/adjustments/create?type=opening`" class="ui-btn-primary">Add Opening Stock</Link>
            </PageHeader>
        </template>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead><tr><th>Number</th><th>Date</th><th>Material</th><th>Location</th><th>Type</th><th class="text-right">Quantity</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-if="adjustments.length === 0"><td colspan="7" class="py-8 text-center text-slate-400">No stock adjustments.</td></tr>
                    <tr v-for="row in adjustments" :key="row.id">
                        <td><Link :href="`/projects/${project.id}/inventory/adjustments/${row.id}`" class="text-amber-700 hover:underline">{{ row.adjustment_number }}</Link></td>
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
    </AdminLayout>
</template>
