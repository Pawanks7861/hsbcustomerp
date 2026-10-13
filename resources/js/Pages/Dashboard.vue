<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { IndianRupee, Wallet } from '@lucide/vue';
import AdminLayout from '../Layouts/AdminLayout.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import StatCard from '../Components/StatCard.vue';
import { formatInr } from '../lib/money';

defineProps({
    projects: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <Head title="Dashboard" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Dashboard" eyebrow="Overview" />
        </template>

        <EmptyState v-if="projects.length === 0" title="No projects yet.">
            <Link href="/projects/create" class="ui-btn-primary">Create a project</Link>
        </EmptyState>

        <div v-else class="space-y-8">
            <section v-for="project in projects" :key="project.id">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">{{ project.project_name }}</h2>
                        <p class="text-xs text-slate-400">{{ project.project_code }}</p>
                    </div>
                    <Link :href="`/projects/${project.id}`" class="text-sm font-medium text-slate-600 hover:text-slate-900">Open</Link>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <StatCard label="BOQ Total" :value="formatInr(project.boq_total)" tone="mint">
                        <template #icon><IndianRupee class="h-4 w-4" /></template>
                    </StatCard>
                    <StatCard label="Budget Value" :value="formatInr(project.budget_total)" tone="beige">
                        <template #icon><Wallet class="h-4 w-4" /></template>
                    </StatCard>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
