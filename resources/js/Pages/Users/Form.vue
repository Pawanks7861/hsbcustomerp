<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';

const props = defineProps({
    user: {
        type: Object,
        default: null,
    },
    roles: {
        type: Array,
        default: () => [],
    },
    projects: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    designation: props.user?.designation ?? '',
    status: props.user?.status ?? 'active',
    is_admin: props.user?.is_admin ?? false,
    role_ids: [...(props.user?.role_ids ?? [])],
    project_access: props.user?.project_access ?? 'all',
    project_ids: props.user?.project_ids ?? [],
    password: '',
    password_confirmation: '',
});

function submit() {
    if (props.user) {
        form.put(`/users/${props.user.id}`);
        return;
    }

    form.post('/users');
}
</script>

<template>
    <Head :title="user ? 'Edit User' : 'Create User'" />
    <AdminLayout>
        <template #header>
            <div>
                <h1 class="text-xl font-semibold">{{ user ? 'Edit user' : 'Create user' }}</h1>
                <p class="text-sm text-slate-500">{{ user ? 'Leave the password blank to keep the current one.' : 'Set a password of at least 8 characters.' }}</p>
            </div>
        </template>

        <form class="ui-card max-w-3xl space-y-5 p-6" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="font-medium">Name</span>
                <input v-model="form.name" type="text" required class="ui-input">
                <span v-if="form.errors.name" class="mt-1 block text-red-700">{{ form.errors.name }}</span>
            </label>
            <label class="block text-sm">
                <span class="font-medium">Email</span>
                <input v-model="form.email" type="email" required class="ui-input">
                <span v-if="form.errors.email" class="mt-1 block text-red-700">{{ form.errors.email }}</span>
            </label>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">Phone</span>
                    <input v-model="form.phone" type="text" class="ui-input">
                    <span v-if="form.errors.phone" class="mt-1 block text-red-700">{{ form.errors.phone }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Designation</span>
                    <input v-model="form.designation" type="text" class="ui-input">
                    <span v-if="form.errors.designation" class="mt-1 block text-red-700">{{ form.errors.designation }}</span>
                </label>
            </div>
            <label class="block text-sm">
                <span class="font-medium">Status</span>
                <select v-model="form.status" class="ui-input">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <span v-if="form.errors.status" class="mt-1 block text-red-700">{{ form.errors.status }}</span>
            </label>
            <div class="block text-sm">
                <span class="font-medium">Roles</span>
                <p class="mt-1 text-xs text-slate-500">Select one or more roles. Permissions from all selected roles are combined.</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    <label v-for="role in roles" :key="role.id" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <input v-model="form.role_ids" type="checkbox" :value="role.id" class="rounded border-slate-300">
                        <span>{{ role.name }}</span>
                    </label>
                </div>
                <p v-if="roles.length === 0" class="mt-2 text-slate-500">No active roles available.</p>
                <span v-if="form.errors.role_ids" class="mt-1 block text-red-700">{{ form.errors.role_ids }}</span>
            </div>
            <label class="block text-sm">
                <span class="font-medium">Project access</span>
                <select v-model="form.project_access" class="ui-input">
                    <option value="all">All projects</option>
                    <option value="selected">Selected projects only</option>
                </select>
            </label>
            <div v-if="form.project_access === 'selected'" class="grid gap-2 sm:grid-cols-2">
                <label v-for="project in projects" :key="project.id" class="flex items-center gap-2 text-sm">
                    <input v-model="form.project_ids" type="checkbox" :value="project.id">
                    {{ project.project_name }}
                </label>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_admin" type="checkbox" class="rounded border-slate-300">
                <span class="font-medium">Super Admin</span>
            </label>
            <Link v-if="user" :href="`/users/${user.id}/permissions`" class="text-sm text-slate-700 underline">Individual permissions</Link>
            <span v-if="form.errors.is_admin" class="block text-sm text-red-700">{{ form.errors.is_admin }}</span>
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="font-medium">{{ user ? 'New password' : 'Password' }}</span>
                    <input v-model="form.password" type="password" :required="!user" autocomplete="new-password" class="ui-input">
                    <span v-if="form.errors.password" class="mt-1 block text-red-700">{{ form.errors.password }}</span>
                </label>
                <label class="block text-sm">
                    <span class="font-medium">Confirm password</span>
                    <input v-model="form.password_confirmation" type="password" :required="!user || form.password !== ''" autocomplete="new-password" class="ui-input">
                </label>
            </div>
            <div class="flex items-center gap-4">
                <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save</button>
                <Link href="/users" class="text-sm text-slate-600 hover:underline">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
