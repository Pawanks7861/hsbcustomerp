<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    vendor: { type: Object, required: true },
    projects: { type: Array, required: true },
    filters: { type: Object, required: true },
    opening: { type: String, required: true },
    closing: { type: String, required: true },
    total_debit: { type: String, required: true },
    total_credit: { type: String, required: true },
    entries: { type: Array, required: true },
});

const projectId = ref(props.filters.project_id || '');
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

function apply() {
    router.get(`/vendors/${props.vendor.id}/ledger`, {
        project_id: projectId.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true });
}
</script>

<template>
    <Head :title="`${vendor.name} Ledger`" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="`${vendor.name} Ledger`" :eyebrow="vendor.vendor_code" />
        </template>
        <form class="mb-4 grid gap-3 md:grid-cols-4" @submit.prevent="apply">
            <select v-model="projectId" class="ui-input"><option value="">All projects</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option></select>
            <input v-model="from" type="date" class="ui-input">
            <input v-model="to" type="date" class="ui-input">
            <button class="ui-btn-primary">Filter</button>
        </form>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Opening</p><p class="text-lg font-semibold">{{ formatInr(opening) }}</p></div>
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Debit</p><p class="text-lg font-semibold">{{ formatInr(total_debit) }}</p></div>
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Credit</p><p class="text-lg font-semibold">{{ formatInr(total_credit) }}</p></div>
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Closing</p><p class="text-lg font-semibold">{{ formatInr(closing) }}</p></div>
        </div>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[900px]">
                <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Project</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th></tr></thead>
                <tbody>
                    <tr v-if="entries.length === 0"><td colspan="7" class="py-8 text-center text-slate-400">No entries.</td></tr>
                    <tr v-for="(entry, index) in entries" :key="index">
                        <td>{{ entry.date }}</td>
                        <td>{{ entry.type }}</td>
                        <td>{{ entry.reference }}</td>
                        <td>{{ entry.project || '—' }}</td>
                        <td class="text-right">{{ entry.debit ? formatInr(entry.debit) : '—' }}</td>
                        <td class="text-right">{{ entry.credit ? formatInr(entry.credit) : '—' }}</td>
                        <td class="text-right">{{ formatInr(entry.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
