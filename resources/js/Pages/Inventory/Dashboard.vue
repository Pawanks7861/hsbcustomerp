<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    filters: { type: Object, required: true },
    stats: { type: Object, required: true },
    grns: { type: Array, required: true },
    issues: { type: Array, required: true },
});

const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

function apply() {
    router.get(`/projects/${props.project.id}/inventory`, {
        from: from.value || undefined,
        to: to.value || undefined,
    }, { preserveState: true, replace: true });
}

const cards = [
    ['Active materials', props.stats.active_materials],
    ['With available stock', props.stats.in_stock],
    ['Low stock', props.stats.low_stock],
    ['Out of stock', props.stats.out_of_stock],
];
</script>

<template>
    <Head title="Inventory" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Inventory" :eyebrow="project.project_name" />
        </template>

        <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-sm"><span class="mb-1 block font-medium">From</span><input v-model="from" type="date" class="ui-input"></label>
            <label class="text-sm"><span class="mb-1 block font-medium">To</span><input v-model="to" type="date" class="ui-input"></label>
            <button class="ui-btn-secondary" type="submit">Filter</button>
        </form>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article v-for="[label, value] in cards" :key="label" class="ui-card p-4">
                <p class="text-sm text-slate-500">{{ label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</p>
            </article>
        </div>

        <div class="mt-6 grid gap-4 lg:grid-cols-2">
            <section class="ui-card p-4">
                <h2 class="mb-3 font-semibold">Recent GRNs</h2>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Number</th><th>Date</th><th>PO</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-if="grns.length === 0"><td colspan="4" class="py-6 text-center text-slate-400">No goods receipt notes.</td></tr>
                            <tr v-for="row in grns" :key="row.id">
                                <td><Link :href="`/projects/${project.id}/grns/${row.id}`" class="text-amber-700 hover:underline">{{ row.number }}</Link></td>
                                <td>{{ row.date }}</td>
                                <td>{{ row.po_number || '—' }}</td>
                                <td class="capitalize">{{ row.status }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="ui-card p-4">
                <h2 class="mb-3 font-semibold">Recent material issues</h2>
                <div class="overflow-x-auto">
                    <table class="ui-table">
                        <thead><tr><th>Number</th><th>Date</th><th>Issued to</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr v-if="issues.length === 0"><td colspan="4" class="py-6 text-center text-slate-400">No material issues.</td></tr>
                            <tr v-for="row in issues" :key="row.id">
                                <td><Link :href="`/projects/${project.id}/inventory/issues/${row.id}`" class="text-amber-700 hover:underline">{{ row.number }}</Link></td>
                                <td>{{ row.date }}</td>
                                <td>{{ row.issued_to }}</td>
                                <td class="capitalize">{{ row.status }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
