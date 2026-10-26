<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ActionMenu from '../../Components/ActionMenu.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    vendors: { type: Object, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
});

const pending = ref(null);
const processing = ref(false);

const dialog = computed(() => {
    const vendor = pending.value;
    if (!vendor) {
        return { title: '', message: '', label: '' };
    }

    const name = `${vendor.vendor_code} · ${vendor.name}`;
    if (vendor.has_transactions) {
        return {
            title: 'Vendor cannot be deleted',
            message: `${name} has linked purchase orders, invoices, payments, or other transactions, so it cannot be deleted. Its history must be kept.${vendor.status === 'active' ? ' You can mark it inactive instead so it is not used for new transactions.' : ''}`,
            label: vendor.status === 'active' ? 'Mark inactive' : '',
        };
    }

    return {
        title: 'Delete vendor',
        message: `Delete ${name}? This cannot be undone. Deletion is refused if the vendor has any linked transactions.`,
        label: 'Delete',
    };
});

function confirm() {
    const vendor = pending.value;
    if (!vendor || processing.value) {
        return;
    }

    if (vendor.has_transactions && vendor.status !== 'active') {
        pending.value = null;

        return;
    }

    processing.value = true;
    const options = {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            processing.value = false;
            pending.value = null;
        },
    };

    if (vendor.has_transactions) {
        router.patch(`/vendors/${vendor.id}/status`, {}, options);
    } else {
        router.delete(`/vendors/${vendor.id}`, options);
    }
}

function filterStatus(event) {
    visitListing({ status: event.target.value });
}
</script>

<template>
    <Head title="Vendors" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Vendors">
                <ImportButton module="vendors" permission="vendors.import" return-url="/vendors" />
                <Link href="/vendors/report" class="ui-btn-secondary">Vendor Report</Link>
                <Link href="/vendors/create" class="ui-btn-primary">+ Add Vendor</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search code, name, phone, GST, status…">
            <label class="text-sm">
                <span class="sr-only">Status</span>
                <select class="ui-input w-40" :value="filters.status || ''" @change="filterStatus">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>
        </TableToolbar>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Vendor</th>
                        <th>Phone</th>
                        <th>GST</th>
                        <th>Status</th>
                        <th>Profile</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="vendors.data.length === 0">
                        <td colspan="7" class="py-8 text-center text-slate-400">
                            {{ filters.search || filters.status ? 'No matching records found.' : 'No vendors yet.' }}
                        </td>
                    </tr>
                    <tr v-for="vendor in vendors.data" :key="vendor.id">
                        <td>{{ vendor.vendor_code }}</td>
                        <td class="font-medium">{{ vendor.name }}</td>
                        <td>{{ vendor.phone || '—' }}</td>
                        <td>{{ vendor.gst_number || '—' }}</td>
                        <td class="capitalize">{{ vendor.status }}</td>
                        <td>
                            <span v-if="vendor.profile_complete" class="text-xs text-emerald-700">Complete</span>
                            <span v-else class="text-xs text-amber-700">Incomplete</span>
                        </td>
                        <td class="text-right">
                            <div class="inline-flex items-center gap-3">
                                <Link :href="`/vendors/${vendor.id}`" class="text-sm font-medium hover:underline">View</Link>
                                <button
                                    v-if="can.delete"
                                    type="button"
                                    class="text-sm font-medium text-red-600 hover:underline"
                                    @click="pending = vendor"
                                >Delete</button>
                                <ActionMenu v-if="can.edit">
                                    <Link :href="`/vendors/${vendor.id}/edit`" class="menu-item">Edit</Link>
                                    <Link :href="`/vendors/${vendor.id}/status`" method="patch" as="button" class="menu-item" preserve-scroll>
                                        {{ vendor.status === 'active' ? 'Mark inactive' : 'Mark active' }}
                                    </Link>
                                </ActionMenu>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="vendors" label="vendors" />

        <ConfirmDialog
            :open="pending !== null"
            :title="dialog.title"
            :message="dialog.message"
            :confirm-label="dialog.label || 'Close'"
            :danger="!pending?.has_transactions"
            :processing="processing"
            @close="pending = null"
            @confirm="confirm"
        />
    </AdminLayout>
</template>
