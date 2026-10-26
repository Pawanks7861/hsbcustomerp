<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';

defineProps({
    clients: { type: Object, required: true },
    filters: { type: Object, required: true },
    canCreate: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Clients" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Clients">
                <Link href="/client-invoices" class="ui-btn-secondary">Client invoices</Link>
                <Link v-if="canCreate" href="/clients/create" class="ui-btn-primary">+ Add Client</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search code, client, phone, email, GST, status…" />
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Client</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>GST</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="clients.data.length === 0">
                        <td colspan="7" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No clients yet.' }}</td>
                    </tr>
                    <tr v-for="client in clients.data" :key="client.id">
                        <td>{{ client.client_code }}</td>
                        <td class="font-medium">{{ client.name }}</td>
                        <td>{{ client.phone || '—' }}</td>
                        <td>{{ client.email || '—' }}</td>
                        <td>{{ client.gst_number || '—' }}</td>
                        <td class="capitalize">{{ client.status }}</td>
                        <td class="text-right"><Link :href="`/clients/${client.id}`" class="text-sm font-medium hover:underline">View</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="clients" label="clients" />
    </AdminLayout>
</template>
