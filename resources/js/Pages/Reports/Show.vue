<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    report: { type: Object, required: true },
    filters: { type: Object, required: true },
    listing: { type: Object, default: () => ({ search: '', per_page: 30 }) },
    rows: { type: Object, required: true },
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
    const data = Object.fromEntries(new FormData(event.target).entries());
    const keys = ['project_id', 'vendor_id', 'client_id', 'from', 'to', 'status', 'balance'];
    const changes = Object.fromEntries(keys.map((key) => [key, data[key] || '']));
    visitListing(changes);
}

function formatCell(row, column) {
    const value = row[column.key];
    if (column.money) {
        return formatInr(value);
    }

    return value ?? '—';
}

function cellHref(row, column) {
    const explicit = row[`href_${column.key}`];
    if (explicit) {
        return explicit;
    }

    if (!column.link) {
        return null;
    }

    const value = row[column.key];
    if (value === null || value === undefined || value === '' || value === '—') {
        return null;
    }

    switch (column.link) {
        case 'project':
            return row.project_id ? `/projects/${row.project_id}` : null;
        case 'purchase_order':
            return row.purchase_order_id && row.project_id
                ? `/projects/${row.project_id}/purchase-orders/${row.purchase_order_id}`
                : null;
        case 'purchase_invoice':
            return row.purchase_invoice_id && row.project_id
                ? `/projects/${row.project_id}/purchase-invoices/${row.purchase_invoice_id}`
                : null;
        case 'vendor_ledger': {
            if (!row.vendor_id) {
                return null;
            }
            const params = new URLSearchParams();
            if (props.filters.from) {
                params.set('from', props.filters.from);
            }
            if (props.filters.to) {
                params.set('to', props.filters.to);
            }
            if (props.filters.project_id) {
                params.set('project_id', props.filters.project_id);
            }
            const qs = params.toString();

            return `/vendors/${row.vendor_id}/ledger${qs ? `?${qs}` : ''}`;
        }
        case 'note':
            if (!row.note_id || !row.project_id) {
                return null;
            }

            return row.note_kind === 'credit'
                ? `/projects/${row.project_id}/credit-notes/${row.note_id}`
                : `/projects/${row.project_id}/debit-notes/${row.note_id}`;
        case 'client_invoice':
            return row.client_invoice_id && row.project_id
                ? `/projects/${row.project_id}/client-invoices/${row.client_invoice_id}`
                : null;
        case 'client':
            return row.client_id ? `/clients/${row.client_id}` : null;
        case 'material':
            if (row.project_id) {
                return `/projects/${row.project_id}/inventory/materials`;
            }

            return '/inventory/materials';
        default:
            return null;
    }
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
                :required="['boq-quantity-consumption', 'stock-balance', 'project-material-consumption'].includes(report.id)"
            >
                <option value="">{{ report.id === 'stock-valuation' ? 'Central Store' : 'Select project' }}</option>
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
            <select v-if="filterSet.has('balance')" name="balance" class="ui-input" :value="filters.balance || 'outstanding'">
                <option value="outstanding">Outstanding only</option>
                <option value="all">All vendors</option>
                <option value="zero">Zero outstanding</option>
                <option value="credit">Advance / credit balances</option>
            </select>
            <button class="ui-btn-primary md:col-span-1">Apply</button>
        </form>

        <TableToolbar :search="listing.search" placeholder="Search any value in this report…">
            <p v-if="listing.search" class="text-xs text-slate-500">Totals and exports use the report filters above, not the search box.</p>
        </TableToolbar>

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
                                    <Link
                                        v-if="cellHref(row, column)"
                                        :href="cellHref(row, column)"
                                        class="text-amber-900 underline-offset-2 hover:underline focus:underline"
                                    >
                                        {{ formatCell(row, column) }}
                                    </Link>
                                    <template v-else>{{ formatCell(row, column) }}</template>
                                </td>
                            </tr>
                            <tr v-if="!section.rows.length">
                                <td :colspan="section.columns.length" class="text-center text-slate-500">{{ listing.search ? 'No matching records found.' : 'No data for the selected filters.' }}</td>
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
                        <tr v-for="(row, index) in rows.data" :key="`${rows.current_page}-${index}`">
                            <td
                                v-for="column in report.columns"
                                :key="column.key"
                                :class="column.align === 'right' ? 'text-right' : ''"
                            >
                                <Link
                                    v-if="cellHref(row, column)"
                                    :href="cellHref(row, column)"
                                    class="text-amber-900 underline-offset-2 hover:underline focus:underline"
                                >
                                    {{ formatCell(row, column) }}
                                </Link>
                                <template v-else>{{ formatCell(row, column) }}</template>
                            </td>
                        </tr>
                        <tr v-if="!rows.data.length">
                            <td :colspan="report.columns.length || 1" class="text-center text-slate-500">{{ listing.search ? 'No matching records found.' : 'No data for the selected filters.' }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="Object.keys(totals).length && rows.data.length">
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
            <TablePagination :paginator="rows" label="rows" />

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
