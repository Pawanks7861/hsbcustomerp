<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    project: { type: Object, default: null },
    invoices: { type: Object, required: true },
    clients: { type: Array, required: true },
    filters: { type: Object, required: true },
    canCreate: { type: Boolean, default: false },
});

const base = props.project ? `/projects/${props.project.id}/client-invoices` : '/client-invoices';

const filtered = () => Boolean(props.filters.search || props.filters.client_id || props.filters.approval_status || props.filters.payment_status);

function apply(event) {
    const data = Object.fromEntries(new FormData(event.target).entries());
    visitListing({
        client_id: data.client_id || '',
        approval_status: data.approval_status || '',
        payment_status: data.payment_status || '',
    });
}

function invoiceHref(invoice) {
    return `/projects/${invoice.project_id}/client-invoices/${invoice.id}`;
}
</script>

<template>
    <Head title="Client Invoices" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Client Invoices" :eyebrow="project?.project_name || 'Receivables'">
                <Link href="/clients" class="ui-btn-secondary">Clients</Link>
                <Link v-if="canCreate" :href="`${base}/create`" class="ui-btn-primary">+ Client Invoice</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search invoice, client, project, date, amount, status…" />
        <form class="ui-card mb-4 grid gap-3 p-4 md:grid-cols-4" @submit.prevent="apply">
            <select name="client_id" class="ui-input" :value="filters.client_id || ''">
                <option value="">All clients</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
            </select>
            <select name="approval_status" class="ui-input" :value="filters.approval_status || ''">
                <option value="">All approvals</option>
                <option value="draft">Draft</option>
                <option value="submitted">Submitted</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </select>
            <select name="payment_status" class="ui-input" :value="filters.payment_status || ''">
                <option value="">All payments</option>
                <option value="unpaid">Unpaid</option>
                <option value="partially_paid">Partially paid</option>
                <option value="paid">Paid</option>
            </select>
            <button class="ui-btn-primary">Filter</button>
        </form>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Invoice No.</th>
                        <th>Date</th>
                        <th v-if="!project">Project</th>
                        <th>Client</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Received</th>
                        <th class="text-right">Outstanding</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="invoices.data.length === 0"><td :colspan="project ? 8 : 9" class="py-8 text-center text-slate-400">{{ filtered() ? 'No matching records found.' : 'No client invoices yet.' }}</td></tr>
                    <tr v-for="invoice in invoices.data" :key="invoice.id">
                        <td>{{ invoice.invoice_number }}</td>
                        <td>{{ invoice.invoice_date }}</td>
                        <td v-if="!project">{{ invoice.project_code }}</td>
                        <td>{{ invoice.client }}</td>
                        <td class="text-right">{{ formatInr(invoice.grand_total) }}</td>
                        <td class="text-right">{{ formatInr(invoice.paid) }}</td>
                        <td class="text-right">{{ formatInr(invoice.outstanding) }}</td>
                        <td>
                            <span class="capitalize">{{ invoice.approval_status }}</span>
                            <span v-if="invoice.approval_status === 'approved'" class="block text-xs capitalize text-slate-500">{{ invoice.payment_status.replaceAll('_', ' ') }}{{ invoice.overdue ? ' · overdue' : '' }}</span>
                        </td>
                        <td class="text-right"><TableActionIcon action="view" :href="invoiceHref(invoice)" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="invoices" label="client invoices" />
    </AdminLayout>
</template>
