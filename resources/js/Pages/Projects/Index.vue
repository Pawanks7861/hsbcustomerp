<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ActionMenu from '../../Components/ActionMenu.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

defineProps({
    projects: { type: Array, required: true },
});

const pending = ref(null);

function destroy() {
    if (!pending.value) {
        return;
    }

    router.delete(`/projects/${pending.value.id}`);
    pending.value = null;
}
</script>

<template>
    <Head title="Projects" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Projects" eyebrow="Construction">
                <Link href="/projects/create" class="ui-btn-primary">+ New Project</Link>
            </PageHeader>
        </template>

        <div class="ui-table-wrap">
            <EmptyState v-if="projects.length === 0" title="No projects yet." />
            <table v-else class="ui-table">
                <thead>
                    <tr>
                        <th>Project Code</th>
                        <th>Project Name</th>
                        <th class="text-right">BOQ Total</th>
                        <th class="text-right">Budget Total</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="project in projects" :key="project.id">
                        <td class="font-medium text-slate-900">{{ project.project_code }}</td>
                        <td>{{ project.project_name }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(project.boq_total) }}</td>
                        <td class="text-right tabular-nums">{{ formatInr(project.budget_total) }}</td>
                        <td class="text-right">
                            <ActionMenu>
                                <Link :href="`/projects/${project.id}`" class="menu-item">Open</Link>
                                <Link :href="`/projects/${project.id}/edit`" class="menu-item">Edit</Link>
                                <button type="button" class="menu-item-danger" @click="pending = project">Delete</button>
                            </ActionMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="pending !== null"
            title="Delete project"
            :message="pending ? `Delete project ${pending.project_code}? Its BOQs, items and budgets will also be deleted.` : ''"
            confirm-label="Delete"
            danger
            @close="pending = null"
            @confirm="destroy"
        />
    </AdminLayout>
</template>
