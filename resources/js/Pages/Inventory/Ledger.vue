<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    materials: { type: Array, required: true },
    filters: { type: Object, required: true },
    entries: { type: Array, required: true },
});

const materialId = ref(props.filters.material_id || '');

function apply() {
    router.get(`/projects/${props.project.id}/inventory/ledger`, {
        material_id: materialId.value || undefined,
    }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Stock Ledger" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Stock Ledger" :eyebrow="project.project_name" />
        </template>
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm"><span class="mb-1 block font-medium">Material</span>
                <select v-model="materialId" class="ui-input w-72">
                    <option value="">All materials</option>
                    <option v-for="material in materials" :key="material.id" :value="material.id">{{ material.material_code }} {{ material.name }}</option>
                </select>
            </label>
            <button class="ui-btn-secondary" type="submit">Filter</button>
        </form>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[980px]">
                <thead>
                    <tr>
                        <th>Transaction</th><th>Date</th><th>Material</th><th>Type</th><th>Source</th>
                        <th class="text-right">Stock In</th><th class="text-right">Stock Out</th><th class="text-right">Balance</th><th>Posted By</th><th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.length === 0"><td colspan="10" class="py-8 text-center text-slate-400">No ledger entries.</td></tr>
                    <tr v-for="entry in entries" :key="entry.id">
                        <td>{{ entry.transaction_number }}</td>
                        <td>{{ entry.transaction_date }}</td>
                        <td>{{ entry.material }}</td>
                        <td>{{ entry.transaction_type }}</td>
                        <td>{{ entry.source_number }}</td>
                        <td class="text-right tabular-nums">{{ entry.stock_in }}</td>
                        <td class="text-right tabular-nums">{{ entry.stock_out }}</td>
                        <td class="text-right tabular-nums">{{ entry.balance }}</td>
                        <td>{{ entry.posted_by || '—' }}</td>
                        <td>{{ entry.remarks || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
