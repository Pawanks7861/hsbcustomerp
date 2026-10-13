<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({
    vendors: { type: Array, required: true },
});
</script>

<template>
    <Head title="Vendors" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Vendors">
                <Link href="/vendors/report" class="ui-btn-secondary">Vendor Report</Link>
                <Link href="/vendors/create" class="ui-btn-primary">+ Add Vendor</Link>
            </PageHeader>
        </template>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Vendor</th>
                        <th>Phone</th>
                        <th>GST</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="vendors.length === 0">
                        <td colspan="6" class="py-8 text-center text-slate-400">No vendors yet.</td>
                    </tr>
                    <tr v-for="vendor in vendors" :key="vendor.id">
                        <td>{{ vendor.vendor_code }}</td>
                        <td class="font-medium">{{ vendor.name }}</td>
                        <td>{{ vendor.phone || '—' }}</td>
                        <td>{{ vendor.gst_number || '—' }}</td>
                        <td class="capitalize">{{ vendor.status }}</td>
                        <td class="text-right"><Link :href="`/vendors/${vendor.id}`" class="text-sm font-medium hover:underline">View</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
