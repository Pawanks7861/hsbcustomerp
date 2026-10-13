<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({
    project: { type: Object, required: true },
    rows: { type: Array, required: true },
});

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
            <PageHeader title="Stock Overview" :eyebrow="project.project_name" />
        </template>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[880px]">
                <thead>
                    <tr>
                        <th>Project</th><th>Material Code</th><th>Material Name</th><th>Unit</th>
                        <th class="text-right">Total Received</th><th class="text-right">Total Issued</th>
                        <th class="text-right">Adjustments</th><th class="text-right">Available Stock</th>
                        <th class="text-right">Minimum Stock</th><th>Stock Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.length === 0"><td colspan="10" class="py-8 text-center text-slate-400">No stock has been posted for this project.</td></tr>
                    <tr v-for="row in rows" :key="row.material_id">
                        <td>{{ project.project_code }}</td>
                        <td>{{ row.material_code }}</td>
                        <td>{{ row.material_name }}</td>
                        <td>{{ row.unit }}</td>
                        <td class="text-right tabular-nums">{{ row.total_received }}</td>
                        <td class="text-right tabular-nums">{{ row.total_issued }}</td>
                        <td class="text-right tabular-nums">{{ row.adjustments }}</td>
                        <td class="text-right tabular-nums">{{ row.available }}</td>
                        <td class="text-right tabular-nums">{{ row.minimum_stock }}</td>
                        <td :class="tone(row.status)">{{ row.status }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
