<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';

defineProps({
    client: { type: Object, required: true },
    summary: { type: Object, required: true },
    invoices: { type: Object, required: true },
    filters: { type: Object, default: () => ({ search: '' }) },
    abilities: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head :title="client.name" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="client.name" :eyebrow="client.client_code">
                <TableActionIcon v-if="abilities.edit" action="edit" :href="`/clients/${client.id}/edit`" />
                <Link v-if="abilities.ledger" :href="`/clients/${client.id}/ledger`" class="ui-btn-primary">Ledger</Link>
            </PageHeader>
        </template>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="ui-card p-4"><p class="text-slate-500">Invoiced</p><p class="text-lg font-semibold">{{ formatInr(summary.invoiced) }}</p></div>
            <div class="ui-card p-4"><p class="text-slate-500">Received</p><p class="text-lg font-semibold">{{ formatInr(summary.received) }}</p></div>
            <div class="ui-card p-4"><p class="text-slate-500">Outstanding</p><p class="text-lg font-semibold">{{ formatInr(summary.outstanding) }}</p></div>
        </div>
        <div class="ui-card mb-4 grid gap-3 p-5 text-sm md:grid-cols-2">
            <div><p class="text-slate-500">Contact</p><p>{{ client.contact_person }} · {{ client.phone }}</p></div>
            <div><p class="text-slate-500">Email</p><p>{{ client.email }}</p></div>
            <div class="md:col-span-2"><p class="text-slate-500">Address</p><p>{{ client.address }}, {{ client.city }}, {{ client.state }} {{ client.pincode }}</p></div>
            <div><p class="text-slate-500">GSTIN</p><p>{{ client.gst_number || '—' }}</p></div>
            <div><p class="text-slate-500">PAN</p><p>{{ client.pan_number || '—' }}</p></div>
        </div>
        <TableToolbar :search="filters.search" placeholder="Search invoice, date, project, amount, status…" />
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Project</th>
                        <th class="text-right">Total</th>
                        <th>Approval</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="invoices.data.length === 0"><td colspan="6" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No client invoices yet.' }}</td></tr>
                    <tr v-for="invoice in invoices.data" :key="invoice.id">
                        <td><Link :href="`/projects/${invoice.project_id}/client-invoices/${invoice.id}`" class="hover:underline">{{ invoice.invoice_number }}</Link></td>
                        <td>{{ invoice.invoice_date }}</td>
                        <td>{{ invoice.project_code }}</td>
                        <td class="text-right">{{ formatInr(invoice.grand_total) }}</td>
                        <td class="capitalize">{{ invoice.approval_status }}</td>
                        <td class="capitalize">{{ invoice.payment_status.replaceAll('_', ' ') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="invoices" label="invoices" />
    </AdminLayout>
</template>
