<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import CompanyHeader from '../../Components/CompanyHeader.vue';
import DocumentPdfActions from '../../Components/DocumentPdfActions.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    company: { type: Object, default: null },
    receipt: { type: Object, required: true },
    receiptStatus: { type: String, default: '' },
    canSubmit: Boolean,
    canEdit: Boolean,
    canDecide: Boolean,
    canCancel: Boolean,
});

const rejecting = ref(false);
const reason = ref('');

function submit() {
    router.post(`/projects/${props.project.id}/grns/${props.receipt.id}/submit`);
}

function post() {
    if (!confirm('Post this GRN and add accepted quantities to project stock?')) return;
    router.post(`/projects/${props.project.id}/grns/${props.receipt.id}/post`);
}

function reject() {
    router.post(`/projects/${props.project.id}/grns/${props.receipt.id}/reject`, { rejection_reason: reason.value });
}

function cancel() {
    if (!confirm('Cancel this posted GRN and reverse accepted stock?')) return;
    router.post(`/projects/${props.project.id}/grns/${props.receipt.id}/cancel`);
}
</script>

<template>
    <Head :title="receipt.receipt_number" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <PageHeader :title="receipt.receipt_number" eyebrow="Goods Receipt Note" />
                <DocumentPdfActions
                    :view-url="`/projects/${project.id}/grns/${receipt.id}/pdf`"
                    :download-url="`/projects/${project.id}/grns/${receipt.id}/pdf/download`"
                />
            </div>
        </template>
        <article class="ui-card space-y-5 p-5 sm:p-8">
            <CompanyHeader :company="company" />
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Project</dt><dd>{{ project.project_name }} ({{ project.project_code }})</dd></div>
                <div><dt class="text-slate-500">PO number</dt><dd>{{ receipt.po_number }}</dd></div>
                <div><dt class="text-slate-500">Vendor</dt><dd>{{ receipt.vendor || '—' }}</dd></div>
                <div><dt class="text-slate-500">GRN date</dt><dd>{{ receipt.receipt_date }}</dd></div>
                <div><dt class="text-slate-500">Status</dt><dd class="capitalize">{{ receipt.status }}</dd></div>
                <div><dt class="text-slate-500">PO receipt status</dt><dd>{{ receiptStatus || '—' }}</dd></div>
                <div><dt class="text-slate-500">Challan</dt><dd>{{ receipt.challan_number || '—' }} <span v-if="receipt.challan_date">/ {{ receipt.challan_date }}</span></dd></div>
                <div><dt class="text-slate-500">Vehicle</dt><dd>{{ receipt.vehicle_number || '—' }}</dd></div>
                <div><dt class="text-slate-500">Received by</dt><dd>{{ receipt.received_by }}</dd></div>
                <div><dt class="text-slate-500">Created by</dt><dd>{{ receipt.created_by || '—' }}</dd></div>
                <div v-if="receipt.posted_by"><dt class="text-slate-500">Posted by</dt><dd>{{ receipt.posted_by }} on {{ receipt.posted_at }}</dd></div>
            </dl>
            <p v-if="receipt.inspection_notes" class="text-sm"><span class="font-medium">Inspection: </span>{{ receipt.inspection_notes }}</p>
            <p v-if="receipt.remarks" class="text-sm"><span class="font-medium">Remarks: </span>{{ receipt.remarks }}</p>
            <p v-if="receipt.rejection_reason" class="text-sm text-red-700">Rejected: {{ receipt.rejection_reason }}</p>
            <div class="overflow-x-auto">
                <table class="ui-table min-w-[860px]">
                    <thead>
                        <tr>
                            <th>Sr.</th><th>PO Item</th><th>Material</th><th>Unit</th>
                            <th class="text-right">Ordered</th><th class="text-right">Previous</th>
                            <th class="text-right">Received</th><th class="text-right">Accepted</th><th class="text-right">Rejected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in receipt.items" :key="index">
                            <td>{{ index + 1 }}</td>
                            <td>{{ line.description }}</td>
                            <td>{{ line.material || 'Not stocked' }}</td>
                            <td>{{ line.unit || '—' }}</td>
                            <td class="text-right tabular-nums">{{ line.ordered }}</td>
                            <td class="text-right tabular-nums">{{ line.previously_received }}</td>
                            <td class="text-right tabular-nums">{{ line.quantity_received }}</td>
                            <td class="text-right tabular-nums">{{ line.quantity_accepted }}</td>
                            <td class="text-right tabular-nums">{{ line.quantity_rejected }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap gap-2">
                <a v-if="canEdit" :href="`/projects/${project.id}/grns/${receipt.id}/edit`" class="ui-btn-secondary">Edit</a>
                <button v-if="canSubmit" type="button" class="ui-btn-primary" @click="submit">Submit for approval</button>
                <button v-if="canDecide" type="button" class="ui-btn-primary" @click="post">Approve and post</button>
                <button v-if="canDecide" type="button" class="ui-btn-secondary" @click="rejecting = !rejecting">Reject</button>
                <button v-if="canCancel" type="button" class="ui-btn-secondary" @click="cancel">Cancel and reverse stock</button>
            </div>
            <form v-if="rejecting" class="space-y-2" @submit.prevent="reject">
                <textarea v-model="reason" required rows="3" class="ui-input w-full" placeholder="Rejection reason"></textarea>
                <button class="ui-btn-primary" type="submit">Confirm rejection</button>
            </form>
        </article>
    </AdminLayout>
</template>
