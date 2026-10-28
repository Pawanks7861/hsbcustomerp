<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    project: { type: Object, default: null },
    expenses: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    canManageHeads: { type: Boolean, default: false },
});

const base = props.project ? `/projects/${props.project.id}/expenses` : '/expenses';
const categoryId = ref(props.filters.category_id || '');
const projectId = ref(props.filters.project_id || '');
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

const filtered = () => Boolean(props.filters.search || props.filters.category_id || props.filters.project_id || props.filters.from || props.filters.to);

function applyFilters() {
    visitListing({
        category_id: categoryId.value,
        project_id: projectId.value,
        from: from.value,
        to: to.value,
    });
}
</script>

<template>
    <Head title="Expenses" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Expenses" :eyebrow="project?.project_name || 'All projects'">
                <ImportButton module="expenses" permission="expenses.import" :return-url="base" :project-id="project?.id" />
                <Link v-if="canManageHeads" href="/expense-categories" class="ui-btn-secondary">Expense Heads</Link>
                <Link :href="`${base}/create`" class="ui-btn-primary">+ Expense</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search number, date, head, project, payee, amount, status…" />
        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
            <label class="text-sm">
                <span class="mb-1 block font-medium">Expense Head</span>
                <select v-model="categoryId" class="ui-input w-48">
                    <option value="">All</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
            </label>
            <label v-if="!project" class="text-sm">
                <span class="mb-1 block font-medium">Project</span>
                <select v-model="projectId" class="ui-input w-48">
                    <option value="">All</option>
                    <option v-for="row in projects" :key="row.id" :value="row.id">{{ row.project_name }}</option>
                </select>
            </label>
            <label class="text-sm">
                <span class="mb-1 block font-medium">From</span>
                <input v-model="from" type="date" class="ui-input">
            </label>
            <label class="text-sm">
                <span class="mb-1 block font-medium">To</span>
                <input v-model="to" type="date" class="ui-input">
            </label>
            <button type="submit" class="ui-btn-secondary">Filter</button>
        </form>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr><th>No.</th><th>Date</th><th>Expense Head</th><th v-if="!project">Project</th><th>Payee</th><th class="text-right">Total</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-if="expenses.data.length === 0"><td :colspan="project ? 7 : 8" class="py-8 text-center text-slate-400">{{ filtered() ? 'No matching records found.' : 'No expenses yet.' }}</td></tr>
                    <tr v-for="expense in expenses.data" :key="expense.id">
                        <td>{{ expense.number }}</td>
                        <td>{{ expense.date }}</td>
                        <td>{{ expense.category }}</td>
                        <td v-if="!project">{{ expense.project || '—' }}</td>
                        <td>{{ expense.payee || '—' }}</td>
                        <td class="text-right">{{ formatInr(expense.total_amount) }}</td>
                        <td><StatusBadge :status="expense.status" /></td>
                        <td class="text-right"><TableActionIcon action="view" :href="`${base}/${expense.id}`" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="expenses" label="expenses" />
    </AdminLayout>
</template>
