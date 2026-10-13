<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({
    roles: { type: Array, required: true },
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
                    <tr v-for="role in roles" :key="role.id">
                        <td>
                            <div class="font-medium">{{ role.name }}</div>
                            <div class="text-xs text-slate-500">{{ role.description }}</div>
                        </td>
                        <td>{{ role.users_count }}</td>
                        <td>{{ role.is_active ? 'Active' : 'Inactive' }}</td>
                        <td><Link v-if="canManage" :href="`/roles/${role.id}/edit`" class="text-sm">Edit</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
