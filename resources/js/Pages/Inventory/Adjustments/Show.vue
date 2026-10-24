<script setup>
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    adjustment: { type: Object, required: true },
});

function post() {
    if (!confirm('Post this stock adjustment?')) return;
    router.post(`/projects/${props.project.id}/inventory/adjustments/${props.adjustment.id}/post`);
}
</script>

<template>
    <Head :title="adjustment.adjustment_number" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="adjustment.adjustment_number" :eyebrow="project.project_name" />
        </template>
        <article class="ui-card space-y-3 p-5 text-sm">
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt class="text-slate-500">Date</dt><dd>{{ adjustment.adjustment_date }}</dd></div>
                <div><dt class="text-slate-500">Status</dt><dd class="capitalize">{{ adjustment.status }}</dd></div>
                <div><dt class="text-slate-500">Material</dt><dd>{{ adjustment.material }}</dd></div>
                <div><dt class="text-slate-500">Location</dt><dd>{{ adjustment.location || '—' }} <span v-if="adjustment.location_code" class="text-slate-400">({{ adjustment.location_code }})</span></dd></div>
                <div><dt class="text-slate-500">Type</dt><dd class="capitalize">{{ adjustment.adjustment_type }}</dd></div>
                <div><dt class="text-slate-500">Quantity</dt><dd class="tabular-nums">{{ adjustment.quantity }} {{ adjustment.unit }}</dd></div>
                <div><dt class="text-slate-500">Reference</dt><dd>{{ adjustment.reference_number || '—' }}</dd></div>
                <div><dt class="text-slate-500">Created by</dt><dd>{{ adjustment.created_by || '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-slate-500">Reason</dt><dd>{{ adjustment.reason }}</dd></div>
                <div v-if="adjustment.posted_by"><dt class="text-slate-500">Posted by</dt><dd>{{ adjustment.posted_by }} on {{ adjustment.posted_at }}</dd></div>
            </dl>
            <a v-if="adjustment.has_attachment" :href="`/projects/${project.id}/inventory/adjustments/${adjustment.id}/attachment`" class="ui-btn-secondary inline-flex">View attachment</a>
            <div>
                <button v-if="adjustment.status === 'draft'" type="button" class="ui-btn-primary" @click="post">Post adjustment</button>
            </div>
        </article>
    </AdminLayout>
</template>
