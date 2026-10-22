<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr } from '../../lib/money';

defineProps({
    client: { type: Object, required: true },
    entries: { type: Array, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`${client.name} ledger`" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="`${client.name} ledger`" :eyebrow="client.client_code">
                <Link :href="`/clients/${client.id}`" class="ui-btn-secondary">Client</Link>
            </PageHeader>
        </template>
        <div class="mb-4 grid gap-3 md:grid-cols-3">
            <div class="ui-card p-4"><p class="text-slate-500">Invoiced</p><p class="font-semibold">{{ formatInr(totals.invoiced) }}</p></div>
            <div class="ui-card p-4"><p class="text-slate-500">Received</p><p class="font-semibold">{{ formatInr(totals.received) }}</p></div>
            <div class="ui-card p-4"><p class="text-slate-500">Outstanding receivable</p><p class="font-semibold">{{ formatInr(totals.outstanding) }}</p></div>
        </div>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Project</th>
                        <th>Particulars</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right">Credit</th>
                        <th class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.length === 0"><td colspan="8" class="py-8 text-center text-slate-400">No posted receivable activity.</td></tr>
                    <tr v-for="(entry, index) in entries" :key="index">
                        <td>{{ entry.date }}</td>
                        <td class="capitalize">{{ entry.type }}</td>
                        <td>{{ entry.reference }}</td>
                        <td>{{ entry.project_code }}</td>
                        <td>{{ entry.particulars }}</td>
                        <td class="text-right">{{ formatInr(entry.debit) }}</td>
                        <td class="text-right">{{ formatInr(entry.credit) }}</td>
                        <td class="text-right">{{ formatInr(entry.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
