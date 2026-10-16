<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    filters: { type: Object, required: true },
    vendors: { type: Array, required: true },
    projects: { type: Array, required: true },
    rows: { type: Array, required: true },
});

function apply(event) {
    const data = new FormData(event.target);
    router.get('/vendors/report', Object.fromEntries(data.entries()), { preserveState: true });
}
</script>

<template>
    <Head title="Vendor Report" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Vendor Report" eyebrow="All vendors">
                <Link href="/vendors/reconciliation" class="ui-btn-secondary">Reconciliation</Link>
                <Link href="/vendors" class="ui-btn-secondary">Vendors</Link>
            </PageHeader>
        </template>
        <form class="ui-card mb-4 grid gap-3 p-4 md:grid-cols-5" @submit.prevent="apply">
            <select name="vendor_id" class="ui-input" :value="filters.vendor_id || ''"><option value="">All vendors</option><option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option></select>
            <select name="project_id" class="ui-input" :value="filters.project_id || ''"><option value="">All projects</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option></select>
            <input name="from" type="date" class="ui-input" :value="filters.from || ''">
            <input name="to" type="date" class="ui-input" :value="filters.to || ''">
            <button class="ui-btn-primary">Filter</button>
        </form>
        <div class="ui-table-wrap">
            <table class="ui-table min-w-[1200px]">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th class="text-right">Total PO Value</th>
                        <th class="text-right">Total Invoice Value</th>
                        <th class="text-right">Advance Paid</th>
                        <th class="text-right">Advance Adjusted</th>
                        <th class="text-right">Available Advance</th>
                        <th class="text-right">Invoice Payments</th>
                        <th class="text-right">Outstanding Payable</th>
                        <th class="text-right">Refund Due From Vendor</th>
                        <th class="text-right">Refund Received</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td><Link :href="`/vendors/${row.id}`" class="font-medium hover:underline">{{ row.name }}</Link></td>
                        <td class="text-right">{{ formatInr(row.total_po_value) }}</td>
                        <td class="text-right">{{ formatInr(row.total_invoice_value) }}</td>
                        <td class="text-right">{{ formatInr(row.advance_paid) }}</td>
                        <td class="text-right">{{ formatInr(row.advance_adjusted) }}</td>
                        <td class="text-right">{{ formatInr(row.available_advance) }}</td>
                        <td class="text-right">{{ formatInr(row.invoice_payments) }}</td>
                        <td class="text-right">{{ formatInr(row.outstanding_payable) }}</td>
                        <td class="text-right">{{ formatInr(row.refund_due) }}</td>
                        <td class="text-right">{{ formatInr(row.refund_received) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
