<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';

defineProps({
    roles: { type: Object, required: true },
    filters: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Roles" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Roles & Permissions" eyebrow="Admin">
                <Link v-if="canManage" href="/roles/create" class="ui-btn-primary">New role</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search role, description, or user name…" />
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Users</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="roles.data.length === 0"><td colspan="4" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No roles yet.' }}</td></tr>
                    <tr v-for="role in roles.data" :key="role.id">
                        <td>
                            <div class="font-medium">{{ role.name }}</div>
                            <div class="text-xs text-slate-500">{{ role.description }}</div>
                        </td>
                        <td class="max-w-xs whitespace-normal">{{ role.users.length ? role.users.join(', ') : '—' }}</td>
                        <td>{{ role.is_active ? 'Active' : 'Inactive' }}</td>
                        <td><Link v-if="canManage" :href="`/roles/${role.id}/edit`" class="text-sm">Edit</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="roles" label="roles" />
    </AdminLayout>
</template>
