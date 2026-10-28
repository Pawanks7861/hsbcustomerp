<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';

const props = defineProps({
    project: { type: Object, required: true },
    receipt: { type: Object, required: true },
});

const preview = ref(null);

function destroy() {
    if (confirm(`Delete ${props.receipt.receipt_number}? This cannot be undone.`)) {
        router.delete(`/projects/${props.project.id}/store/${props.receipt.id}`);
    }
}
</script>

<template>
    <Head :title="receipt.receipt_number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="receipt.receipt_number" :eyebrow="receipt.type">
                <DocumentPdfActions
                    :view-url="`/projects/${project.id}/store/${receipt.id}/pdf`"
                    :download-url="`/projects/${project.id}/store/${receipt.id}/pdf/download`"
                />
                <Link v-if="receipt.is_grn" :href="`/projects/${project.id}/grns/${receipt.id}`" class="ui-btn-secondary">Open GRN</Link>
                <TableActionIcon v-if="receipt.can_edit" action="edit" :href="`/projects/${project.id}/store/${receipt.id}/edit`" />
                <TableActionIcon v-if="receipt.can_delete" action="delete" @click="destroy" />
            </PageHeader>
        </template>
        <div class="ui-card mb-4 grid gap-3 p-5 text-sm md:grid-cols-3">
            <div><p class="text-slate-500">Date</p><p>{{ receipt.receipt_date }}</p></div>
            <div><p class="text-slate-500">PO</p><p>{{ receipt.po_number || 'Manual' }}</p></div>
            <div><p class="text-slate-500">Vendor</p><p>{{ receipt.vendor || '—' }}</p></div>
            <div><p class="text-slate-500">Received by</p><p>{{ receipt.received_by }}</p></div>
            <div class="md:col-span-2"><p class="text-slate-500">Remarks</p><p class="whitespace-pre-line">{{ receipt.remarks || '—' }}</p></div>
        </div>
        <div class="ui-table-wrap mb-4">
            <table class="ui-table">
                <thead><tr><th>Description</th><th>Unit</th><th class="text-right">Quantity Received</th><th>Remarks</th></tr></thead>
                <tbody>
                    <tr v-for="(item, index) in receipt.items" :key="index">
                        <td class="whitespace-pre-line">{{ item.description }}</td>
                        <td>{{ item.unit || '—' }}</td>
                        <td class="text-right">{{ item.quantity_received }}</td>
                        <td>{{ item.remarks || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <button v-for="image in receipt.images" :key="image.id" type="button" class="overflow-hidden rounded-xl border" @click="preview = image.url">
                <img :src="image.url" alt="" class="h-32 w-full object-cover" width="320" height="128" loading="lazy" decoding="async">
            </button>
        </div>
        <div v-if="preview" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-6" @click="preview = null">
            <img :src="preview" alt="" class="max-h-full max-w-full rounded-xl" decoding="async">
        </div>
    </AdminLayout>
</template>
