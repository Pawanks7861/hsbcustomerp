<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    note: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const rejectForm = useForm({ rejection_reason: '' });

function post(action) {
    rejectForm.post(`/projects/${props.project.id}/debit-notes/${props.note.id}/${action}`, {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset(),
    });
}
</script>

<template>
    <Head :title="note.number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="note.number" eyebrow="Debit Note">
                <DocumentPdfActions v-if="abilities.print" :view-url="`/projects/${project.id}/debit-notes/${note.id}/pdf`" :download-url="`/projects/${project.id}/debit-notes/${note.id}/pdf/download`" />
                <TableActionIcon v-if="abilities.edit" action="edit" :href="`/projects/${project.id}/debit-notes/${note.id}/edit`" />
                <StatusBadge :status="note.status" />
            </PageHeader>
        </template>
        <section class="ui-card mb-4 grid gap-2 p-5 text-sm md:grid-cols-2">
            <p>Date: {{ note.date }}</p>
            <p>Vendor: {{ note.vendor_name }}</p>
            <p>PO: {{ note.po_number || '—' }}</p>
            <p>Invoice: {{ note.invoice_number || '—' }}</p>
            <p class="md:col-span-2">Reason: {{ note.reason }}</p>
            <p v-if="note.remarks" class="md:col-span-2">{{ note.remarks }}</p>
        </section>
        <div v-if="note.items?.length" class="ui-table-wrap mb-4">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unit</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Rate</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in note.items" :key="line.id">
                        <td>{{ line.description }}</td>
                        <td>{{ line.unit || '—' }}</td>
                        <td class="text-right tabular-nums">{{ line.quantity }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.rate) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(line.amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <section class="ui-card mb-4 grid gap-2 p-5 text-sm md:grid-cols-2">
            <p>Subtotal: {{ formatInr(note.subtotal) }}</p>
            <p>Tax: {{ formatInr(note.tax_amount) }}</p>
            <p>Round off: {{ formatInr(note.round_off) }}</p>
            <p class="font-semibold">Total: {{ formatInr(note.total_amount) }}</p>
            <p v-if="note.approved_by">Approved by {{ note.approved_by }}</p>
            <p v-if="note.rejection_reason" class="text-rose-600 md:col-span-2">{{ note.rejection_reason }}</p>
        </section>
        <div class="flex flex-wrap gap-2">
            <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="post('submit')">Submit</button>
            <button v-if="abilities.approve" type="button" class="ui-btn-primary" @click="post('approve')">Approve</button>
        </div>
        <form v-if="abilities.reject" class="ui-card mt-4 space-y-3 p-4" @submit.prevent="post('reject')">
            <textarea v-model="rejectForm.rejection_reason" required placeholder="Rejection reason" class="ui-input w-full"></textarea>
            <button class="ui-btn-secondary">Reject</button>
        </form>
    </AdminLayout>
</template>
