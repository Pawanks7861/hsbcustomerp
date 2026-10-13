<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    issue: { type: Object, required: true },
    canPost: { type: Boolean, required: true },
    canCancel: { type: Boolean, required: true },
});

function post() {
    if (!confirm('Post this issue and deduct stock?')) return;
    router.post(`/projects/${props.project.id}/inventory/issues/${props.issue.id}/post`);
}

function cancel() {
    if (!confirm('Cancel this issue and reverse the stock deduction?')) return;
    router.post(`/projects/${props.project.id}/inventory/issues/${props.issue.id}/cancel`);
}
</script>

<template>
    <Head :title="issue.issue_number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="issue.issue_number" :eyebrow="project.project_name" />
        </template>
        <article class="ui-card space-y-4 p-5">
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500">Date</dt><dd>{{ issue.issue_date }}</dd></div>
                <div><dt class="text-slate-500">Status</dt><dd class="capitalize">{{ issue.status }}</dd></div>
                <div><dt class="text-slate-500">Issued to</dt><dd>{{ issue.issued_to }}</dd></div>
                <div><dt class="text-slate-500">Created by</dt><dd>{{ issue.created_by || '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Purpose</dt><dd>{{ issue.purpose || '—' }}</dd></div>
                <div v-if="issue.posted_by"><dt class="text-slate-500">Posted by</dt><dd>{{ issue.posted_by }} on {{ issue.posted_at }}</dd></div>
            </dl>
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th>Material</th><th>Unit</th><th class="text-right">Quantity</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <tr v-for="(line, index) in issue.items" :key="index">
                            <td>{{ line.material }}</td>
                            <td>{{ line.unit }}</td>
                            <td class="text-right tabular-nums">{{ line.quantity }}</td>
                            <td>{{ line.remarks || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap gap-2">
                <button v-if="canPost" type="button" class="ui-btn-primary" @click="post">Post issue</button>
                <button v-if="canCancel" type="button" class="ui-btn-secondary" @click="cancel">Cancel and reverse</button>
            </div>
        </article>
    </AdminLayout>
</template>
