<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';

defineProps({
    project: { type: Object, required: true },
    invoices: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <Head title="Purchase Invoices" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Purchase Invoices" :eyebrow="project.project_name">
                <ImportButton module="invoices" permission="invoices.import" :return-url="`/projects/${project.id}/purchase-invoices`" :project-id="project.id" />
                <Link :href="`/projects/${project.id}/purchase-invoices/create`" class="ui-btn-primary">+ Invoice</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search invoice, date, PO, vendor, amount, status…" />
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Invoice No.</th>
                        <th>Invoice Date</th>
                        <th>PO No.</th>
                        <th>Vendor</th>
                        <th class="text-right">Invoice Total</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Outstanding</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="invoices.data.length === 0"><td colspan="9" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No invoices yet.' }}</td></tr>
                    <tr v-for="invoice in invoices.data" :key="invoice.id">
                        <td>{{ invoice.invoice_number }}</td>
                        <td>{{ invoice.invoice_date }}</td>
                        <td>{{ invoice.po_number }}</td>
                        <td>{{ invoice.vendor }}</td>
                        <td class="text-right">{{ formatInr(invoice.total_amount) }}</td>
                        <td class="text-right">{{ formatInr(invoice.paid) }}</td>
                        <td class="text-right">{{ formatInr(invoice.outstanding) }}</td>
                        <td class="capitalize">{{ (invoice.approval_status && invoice.approval_status !== 'approved' ? invoice.approval_status : invoice.status).replaceAll('_', ' ') }}</td>
                        <td class="text-right"><TableActionIcon action="view" :href="`/projects/${project.id}/purchase-invoices/${invoice.id}`" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="invoices" label="invoices" />
    </AdminLayout>
</template>
