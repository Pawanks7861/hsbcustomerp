<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, default: null },
    expenses: { type: Array, required: true },
});

const base = props.project ? `/projects/${props.project.id}/expenses` : '/expenses';
</script>

<template>
    <Head title="Expenses" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Expenses" :eyebrow="project?.project_name || 'All projects'">
                <Link :href="`${base}/create`" class="ui-btn-primary">+ Expense</Link>
            </PageHeader>
        </template>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr><th>No.</th><th>Date</th><th>Category</th><th v-if="!project">Project</th><th>Payee</th><th class="text-right">Total</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-if="expenses.length === 0"><td :colspan="project ? 7 : 8" class="py-8 text-center text-slate-400">No expenses yet.</td></tr>
                    <tr v-for="expense in expenses" :key="expense.id">
                        <td>{{ expense.number }}</td>
                        <td>{{ expense.date }}</td>
                        <td>{{ expense.category }}</td>
                        <td v-if="!project">{{ expense.project || '—' }}</td>
                        <td>{{ expense.payee || '—' }}</td>
                        <td class="text-right">{{ formatInr(expense.total_amount) }}</td>
                        <td><StatusBadge :status="expense.status" /></td>
                        <td class="text-right"><Link :href="`${base}/${expense.id}`" class="hover:underline">View</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
