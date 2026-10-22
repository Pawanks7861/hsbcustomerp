<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Object, required: true },
    rows: { type: Array, required: true },
    totals: { type: Object, default: () => ({}) },
    sections: { type: Array, default: () => [] },
    projects: { type: Array, required: true },
    vendors: { type: Array, required: true },
    clients: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    abilities: { type: Object, required: true },
});

const page = usePage();
const permissions = computed(() => page.props.auth?.permissions ?? []);

function can(permission) {
    return permissions.value.includes(permission);
}

const filterSet = computed(() => new Set(props.report.filters ?? []));

const queryString = computed(() => {
    const params = new URLSearchParams();
    Object.entries(props.filters).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    });

    const qs = params.toString();

    return qs ? `?${qs}` : '';
});

function apply(event) {
    const data = new FormData(event.target);
    router.get(`/reports/${props.report.id}`, Object.fromEntries(data.entries()), { preserveState: true });
}

function formatCell(row, column) {
    const value = row[column.key];
    if (column.money) {
        return formatInr(value);
    }

    return value ?? '—';
}

function totalLabel(key) {
    return key.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatTotal(key, value) {
    if (typeof value === 'string' && /^-?\d+\.\d+$/.test(value) && key.includes('total') || key.includes('amount') || key.includes('value') || key.includes('outstanding') || key.includes('paid') || key.includes('advance')) {
        return formatInr(value);
    }

    return value;
}
</script>

<template>
    <Head :title="report.title" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="report.title" :eyebrow="report.group">
                <Link href="/reports" class="ui-btn-secondary">All reports</Link>
                <a
                    v-if="abilities.export && can('reports.export')"
                    :href="`/reports/${report.id}/export/excel${queryString}`"
                    class="ui-btn-secondary"
                >
                    Export Excel
                </a>
                <a
                    v-if="abilities.print && (can('reports.print') || can('reports.export'))"
                    :href="`/reports/${report.id}/export/pdf${queryString}`"
                    class="ui-btn-secondary"
                >
                    Export PDF
                </a>
            </PageHeader>
        </template>

        <form class="ui-card mb-4 grid gap-3 p-4 md:grid-cols-6" @submit.prevent="apply">
            <select
                v-if="filterSet.has('project')"
                name="project_id"
                class="ui-input"
                :value="filters.project_id || ''"
                :required="['boq-quantity-consumption', 'stock-balance'].includes(report.id)"
            >
                <option value="">Select project</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">
                    {{ project.project_code }} — {{ project.project_name }}
                </option>
            </select>
            <select v-if="filterSet.has('vendor')" name="vendor_id" class="ui-input" :value="filters.vendor_id || ''">
                <option value="">All vendors</option>
                <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option>
            </select>
            <select v-if="filterSet.has('client')" name="client_id" class="ui-input" :value="filters.client_id || ''">
                <option value="">All clients</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
            </select>
            <input v-if="filterSet.has('from')" name="from" type="date" class="ui-input" :value="filters.from || ''">
            <input v-if="filterSet.has('to')" name="to" type="date" class="ui-input" :value="filters.to || ''">
            <select v-if="filterSet.has('status')" name="status" class="ui-input" :value="filters.status || ''">
                <option value="">All statuses</option>
                <option v-for="status in statusOptions" :key="status" :value="status">{{ status }}</option>
            </select>
            <button class="ui-btn-primary md:col-span-1">Apply</button>
        </form>

        <template v-if="sections.length">
            <section v-for="section in sections" :key="section.key" class="mb-8">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ section.title }}</h2>
                <div class="ui-table-wrap">
                    <table class="ui-table min-w-full">
                        <thead>
                            <tr>
                                <th
                                    v-for="column in section.columns"
                                    :key="column.key"
                                    :class="column.align === 'right' ? 'text-right' : ''"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in section.rows" :key="section.key + '-' + index">
                                <td
                                    v-for="column in section.columns"
                                    :key="column.key"
                                    :class="column.align === 'right' ? 'text-right' : ''"
                                >
                                    {{ formatCell(row, column) }}
                                </td>
                            </tr>
                            <tr v-if="!section.rows.length">
                                <td :colspan="section.columns.length" class="text-center text-slate-500">No data for the selected filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <template v-else>
            <div class="ui-table-wrap">
                <table class="ui-table min-w-full">
                    <thead>
                        <tr>
                            <th
                                v-for="column in report.columns"
                                :key="column.key"
                                :class="column.align === 'right' ? 'text-right' : ''"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, index) in rows" :key="index">
                            <td
                                v-for="column in report.columns"
                                :key="column.key"
                                :class="column.align === 'right' ? 'text-right' : ''"
                            >
                                {{ formatCell(row, column) }}
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="report.columns.length || 1" class="text-center text-slate-500">No data for the selected filters.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="Object.keys(totals).length && rows.length">
                        <tr class="font-semibold bg-slate-50">
                            <td :colspan="Math.max(1, (report.columns.length || 1) - Object.keys(totals).length)">Totals</td>
                            <td
                                v-for="column in report.columns.slice(-Object.keys(totals).length)"
                                :key="'total-' + column.key"
                                class="text-right"
                            >
                                {{ totals[column.key] !== undefined ? formatCell({ [column.key]: totals[column.key] }, column) : '' }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="report.metric && Object.keys(totals).length" class="ui-card mt-4 p-4 text-sm text-slate-600">
                <dl class="grid gap-2 md:grid-cols-2">
                    <div v-for="(value, key) in totals" :key="key" class="flex justify-between gap-4">
                        <dt>{{ totalLabel(key) }}</dt>
                        <dd class="font-medium">{{ formatTotal(key, value) }}</dd>
                    </div>
                </dl>
            </div>
        </template>

        <div v-if="sections.length && Object.keys(totals).length" class="ui-card mt-4 p-4">
            <p class="text-sm font-semibold text-slate-700">Grand total: {{ formatInr(totals.total_amount) }}</p>
        </div>
    </AdminLayout>
</template>
