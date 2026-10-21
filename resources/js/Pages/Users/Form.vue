<script setup>
import { computed, ref, watch } from 'vue';
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
    can_assign_super_admin: {
        type: Boolean,
        default: false,
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

const staffRoles = computed(() => props.roles.filter((role) => !role.privileged));
const privilegedRoles = computed(() => props.roles.filter((role) => role.privileged));

const selectedStaffRoles = computed(() => staffRoles.value.filter((role) => form.role_ids.includes(role.id)));

const tutorialMenus = computed(() => {
    if (form.is_admin) {
        return [];
    }

    const groups = new Map();
    selectedStaffRoles.value.forEach((role) => {
        (role.menus || []).forEach((group) => {
            if (!groups.has(group.group)) {
                groups.set(group.group, new Map());
            }
            const items = groups.get(group.group);
            (group.items || []).forEach((item) => {
                if (!items.has(item.label)) {
                    items.set(item.label, item);
                }
            });
        });
    });

    return [...groups.entries()].map(([group, items]) => ({
        group,
        items: [...items.values()],
    }));
});

const showMenuTutorial = computed(() => !form.is_admin && selectedStaffRoles.value.length > 0);

const previewSteps = computed(() => tutorialMenus.value.flatMap((group) => (group.items || []).map((item) => ({
    group: group.group,
    label: item.label,
    action: item.action || item.hint || `Click ${item.label}.`,
    hint: item.hint || '',
}))));

const previewIndex = ref(0);
const previewDone = ref(false);
const previewStep = computed(() => previewSteps.value[previewIndex.value] ?? null);
const previewLast = computed(() => previewSteps.value.length > 0 && previewIndex.value >= previewSteps.value.length - 1);

watch(previewSteps, () => {
    previewIndex.value = 0;
    previewDone.value = false;
});

function previewNext() {
    if (previewLast.value) {
        previewDone.value = true;
        return;
    }
    previewIndex.value += 1;
}

function previewBack() {
    previewDone.value = false;
    if (previewIndex.value > 0) {
        previewIndex.value -= 1;
    }
}

function previewSkip() {
    previewDone.value = true;
}

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

        <form class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]" @submit.prevent="submit">
            <div class="ui-card max-w-3xl space-y-5 p-6">
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
                    <p class="mt-1 text-xs text-slate-500">Assign staff roles. The sidebar on the right shows the menus that role can use. Super Admin and Administrator are not assigned here.</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label v-for="role in staffRoles" :key="role.id" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                            <input v-model="form.role_ids" type="checkbox" :value="role.id" class="rounded border-slate-300">
                            <span>{{ role.name }}</span>
                        </label>
                    </div>
                    <p v-if="staffRoles.length === 0" class="mt-2 text-slate-500">No staff roles available.</p>
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
                <label v-if="can_assign_super_admin" class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_admin" type="checkbox" class="rounded border-slate-300">
                    <span class="font-medium">Super Admin</span>
                    <span class="text-xs text-slate-500">(sees every menu — skip the role tutorial)</span>
                </label>
                <p v-if="privilegedRoles.length" class="text-xs text-slate-500">
                    This account already has: {{ privilegedRoles.map((role) => role.name).join(', ') }}.
                </p>
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
            </div>

            <aside class="lg:sticky lg:top-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Role menus</p>
                    <h2 class="mt-1 text-sm font-semibold text-slate-900">What this user will see</h2>

                    <p v-if="form.is_admin" class="mt-3 text-sm text-slate-600">
                        Super Admin skips this tutorial and can open every ERP menu.
                    </p>
                    <p v-else-if="!showMenuTutorial" class="mt-3 text-sm text-slate-600">
                        Choose a staff role to preview the sidebar menus for that person. Admin and Super Admin are not included.
                    </p>
                    <div v-else class="mt-3 space-y-4">
                        <p class="text-xs text-slate-500">
                            Combined click tour for {{ selectedStaffRoles.map((role) => role.name).join(', ') }}.
                            That person can Next or Skip the same guide on first login.
                        </p>
                        <div v-if="previewStep && !previewDone" class="rounded-xl border border-sky-100 bg-sky-50/70 p-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-sky-700">{{ previewStep.group }}</p>
                                <p class="text-[11px] tabular-nums text-sky-700">{{ previewIndex + 1 }} / {{ previewSteps.length }}</p>
                            </div>
                            <div class="mt-2 flex items-start gap-2">
                                <span class="mt-1 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-500 text-[10px] font-bold text-white">click</span>
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ previewStep.label }}</p>
                                    <p class="mt-0.5 text-xs leading-snug text-slate-600">{{ previewStep.action }}</p>
                                </div>
                            </div>
                            <div class="mt-3 flex items-center justify-between gap-2">
                                <button type="button" class="text-xs font-medium text-slate-500 hover:text-slate-800" @click="previewSkip">Skip</button>
                                <div class="flex items-center gap-2">
                                    <button v-if="previewIndex > 0" type="button" class="ui-btn-secondary" @click="previewBack">Back</button>
                                    <button type="button" class="ui-btn-primary" @click="previewNext">{{ previewLast ? 'Done' : 'Next' }}</button>
                                </div>
                            </div>
                        </div>
                        <section v-for="group in tutorialMenus" :key="group.group">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">{{ group.group }}</p>
                            <ul class="mt-1.5 space-y-1.5">
                                <li
                                    v-for="item in group.items"
                                    :key="item.label"
                                    class="rounded-lg px-2.5 py-1.5"
                                    :class="!previewDone && previewStep?.label === item.label ? 'bg-sky-50 ring-1 ring-sky-200' : 'bg-slate-50'"
                                >
                                    <p class="text-sm font-medium text-slate-800">{{ item.label }}</p>
                                    <p class="text-[11px] leading-snug text-slate-500">{{ item.action || item.hint }}</p>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </aside>
        </form>
    </AdminLayout>
</template>
