<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    note: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const rejectForm = useForm({ rejection_reason: '' });

function post(action) {
    rejectForm.post(`/projects/${props.project.id}/credit-notes/${props.note.id}/${action}`, {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset(),
    });
}
</script>

<template>
    <Head :title="note.number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="note.number" eyebrow="Credit Note">
                <DocumentPdfActions v-if="abilities.print" :view-url="`/projects/${project.id}/credit-notes/${note.id}/pdf`" :download-url="`/projects/${project.id}/credit-notes/${note.id}/pdf/download`" />
                <Link v-if="abilities.edit" :href="`/projects/${project.id}/credit-notes/${note.id}/edit`" class="ui-btn-secondary">Edit</Link>
                <StatusBadge :status="note.status" />
            </PageHeader>
        </template>
        <section class="ui-card mb-4 grid gap-2 p-5 text-sm md:grid-cols-2">
            <p>Date: {{ note.date }}</p>
            <p>Vendor: {{ note.vendor_name }}</p>
            <p>Vendor ref.: {{ note.vendor_reference || '—' }}</p>
            <p>PO: {{ note.po_number || '—' }}</p>
            <p>Invoice: {{ note.invoice_number || '—' }}</p>
            <p class="md:col-span-2">Reason: {{ note.reason }}</p>
            <p v-if="note.remarks" class="md:col-span-2">{{ note.remarks }}</p>
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
