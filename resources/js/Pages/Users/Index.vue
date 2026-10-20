<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ActionMenu from '../../Components/ActionMenu.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

defineProps({
    users: {
        type: Array,
        required: true,
    },
});

const pending = ref(null);
const statusTarget = ref(null);

function setStatus(user, status) {
    statusTarget.value = { user, status };
}

function confirmStatus() {
    if (!statusTarget.value) {
        return;
    }

    router.patch(`/users/${statusTarget.value.user.id}/status`, { status: statusTarget.value.status });
    statusTarget.value = null;
}

function destroy() {
    if (!pending.value) {
        return;
    }

    router.delete(`/users/${pending.value.id}`);
    pending.value = null;
}

function initials(name) {
    return String(name || '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}
</script>

<template>
    <Head title="Users" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Users" eyebrow="Admin">
                <ImportButton module="users" permission="users.import" return-url="/users" />
                <Link href="/users/create" class="ui-btn-primary">+ Add User</Link>
            </PageHeader>
        </template>

        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Designation</th>
                        <th>Role</th>
                        <th>Projects</th>
                        <th>Last login</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td>
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ initials(user.name) }}</span>
                                <span class="font-medium text-slate-900">{{ user.name }}</span>
                            </div>
                        </td>
                        <td>{{ user.email }}</td>
                        <td>{{ user.phone || '—' }}</td>
                        <td>{{ user.designation || '—' }}</td>
                        <td>{{ user.role }}</td>
                        <td>{{ user.projects || '—' }}</td>
                        <td>{{ user.last_login_at || '—' }}</td>
                        <td>{{ user.created_at || '—' }}</td>
                        <td><StatusBadge :status="user.status" /></td>
                        <td class="text-right">
                            <ActionMenu>
                                <Link :href="`/users/${user.id}/edit`" class="menu-item">Edit</Link>
                                <button v-if="!user.is_self" type="button" class="menu-item" @click="setStatus(user, user.status === 'active' ? 'inactive' : 'active')">
                                    {{ user.status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                                <button v-if="!user.is_self" type="button" class="menu-item-danger" @click="pending = user">Delete</button>
                            </ActionMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :open="pending !== null"
            title="Delete user"
            :message="pending ? `Delete ${pending.name}?` : ''"
            confirm-label="Delete"
            danger
            @close="pending = null"
            @confirm="destroy"
        />
        <ConfirmDialog
            :open="statusTarget !== null"
            :title="statusTarget?.status === 'inactive' ? 'Deactivate user' : 'Activate user'"
            :message="statusTarget ? `Do you want to ${statusTarget.status === 'active' ? 'activate' : 'deactivate'} ${statusTarget.user.name}?` : ''"
            confirm-label="Confirm"
            @close="statusTarget = null"
            @confirm="confirmStatus"
        />
    </AdminLayout>
</template>
