<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, default: null },
    expense: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const base = props.project ? `/projects/${props.project.id}/expenses` : '/expenses';
const rejectForm = useForm({ rejection_reason: '' });

function post(action) {
    rejectForm.post(`${base}/${props.expense.id}/${action}`, { preserveScroll: true, onSuccess: () => rejectForm.reset() });
}
</script>

<template>
    <Head :title="expense.number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="expense.number" eyebrow="Expense">
                <Link v-if="abilities.edit" :href="`${base}/${expense.id}/edit`" class="ui-btn-secondary">Edit</Link>
                <a v-if="abilities.print" :href="`${base}/${expense.id}/pdf`" target="_blank" class="ui-btn-secondary">Print</a>
                <a v-if="abilities.print" :href="`${base}/${expense.id}/pdf/download`" class="ui-btn-secondary">PDF</a>
                <StatusBadge :status="expense.status" />
            </PageHeader>
        </template>
        <section class="ui-card mb-4 grid gap-2 p-5 text-sm md:grid-cols-2">
            <p>Date: {{ expense.date }}</p>
            <p>Expense Head: {{ expense.category_name }}</p>
            <p v-if="expense.project_name">Project: {{ expense.project_name }}</p>
            <p>Payment status: {{ expense.payment_status }}</p>
            <p class="md:col-span-2">{{ expense.description }}</p>
            <p>Payee: {{ expense.payee || '—' }}</p>
            <p>Vendor: {{ expense.vendor_name || '—' }}</p>
            <p>Total: {{ formatInr(expense.total_amount) }}</p>
            <p v-if="expense.notes" class="md:col-span-2">{{ expense.notes }}</p>
        </section>
        <div class="flex flex-wrap gap-2">
            <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="post('submit')">Submit</button>
            <button v-if="abilities.approve" type="button" class="ui-btn-primary" @click="post('approve')">Approve</button>
        </div>
        <form v-if="abilities.reject" class="ui-card mt-4 space-y-3 p-4" @submit.prevent="post('reject')">
            <textarea v-model="rejectForm.rejection_reason" required class="ui-input w-full"></textarea>
            <button class="ui-btn-secondary">Reject</button>
        </form>
    </AdminLayout>
</template>
