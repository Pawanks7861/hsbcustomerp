<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import BudgetEditor from '../../Components/BudgetEditor.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    item: { type: Object, required: true },
    entries: { type: Array, required: true },
});
</script>

<template>
    <Head title="Set Budget" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Set Budget" :eyebrow="`${item.item_number} — ${item.description}`" />
        </template>

        <div class="ui-card max-w-xl p-6">
            <BudgetEditor
                :project-id="project.id"
                :boq-id="boq.id"
                :item-id="item.id"
                :initial-entries="entries"
            />
            <Link :href="`/projects/${project.id}/boqs/${boq.id}`" class="mt-4 inline-block text-sm text-slate-500 hover:text-slate-800">
                Back to BOQ
            </Link>
        </div>
    </AdminLayout>
</template>
