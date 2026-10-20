<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ActionMenu from '../../Components/ActionMenu.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    boqs: { type: Array, required: true },
});

const pending = ref(null);

function destroy() {
    if (!pending.value) {
        return;
    }

    router.delete(`/projects/${props.project.id}/boqs/${pending.value.id}`);
    pending.value = null;
}
</script>

<template>
    <Head title="BOQ" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="BOQ" :eyebrow="project.project_name">
                <ImportButton module="boq" permission="boq.import" :return-url="`/projects/${project.id}/boqs`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/boqs/create`" class="ui-btn-primary">+ New BOQ</Link>
            </PageHeader>
        </template>

        <div class="ui-table-wrap">
            <EmptyState v-if="boqs.length === 0" title="No BOQs for this project." />
            <table v-else class="ui-table">
                <thead>
                    <tr>
                        <th>BOQ Number</th>
                        <th>Title</th>
                        <th>Current Revision</th>
                        <th class="text-right">GST %</th>
                        <th class="text-right">BOQ Total</th>
                        <th class="text-right">Budget Total</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="boq in boqs" :key="boq.id">
                        <td class="font-medium text-slate-900">{{ boq.boq_number }}</td>
                        <td>{{ boq.title }}</td>
                        <td>Rev {{ boq.revision_number ?? 0 }}</td>
                        <td class="text-right tabular-nums">{{ boq.gst_percentage }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(boq.grand_total) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(boq.budget_total) }}</td>
                        <td class="text-right">
                            <ActionMenu>
                                <Link :href="`/projects/${project.id}/boqs/${boq.id}`" class="menu-item">Open</Link>
                                <button type="button" class="menu-item-danger" @click="pending = boq">Delete</button>
                            </ActionMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="pending !== null"
            title="Delete BOQ"
            :message="pending ? `Delete BOQ “${pending.title}”? Its items and budgets will also be deleted.` : ''"
            confirm-label="Delete"
            danger
            @close="pending = null"
            @confirm="destroy"
        />
    </AdminLayout>
</template>
