<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    role: { type: Object, default: null },
    modules: { type: Array, required: true },
});

const open = ref(props.modules[0]?.module || '');
const form = useForm({
    name: props.role?.name || '',
    description: props.role?.description || '',
    is_active: props.role?.is_active ?? true,
    permissions: props.modules.flatMap((module) => module.actions.filter((action) => action.granted && !action.approval).map((action) => action.permission)),
});

function toggle(permission, checked) {
    form.permissions = checked
        ? [...form.permissions, permission]
        : form.permissions.filter((item) => item !== permission);
}

function setModule(module, checked) {
    const names = module.actions.filter((action) => !action.approval).map((action) => action.permission);
    form.permissions = checked
        ? [...new Set([...form.permissions, ...names])]
        : form.permissions.filter((item) => !names.includes(item));
}

function save() {
    if (props.role) {
        form.put(`/roles/${props.role.id}`);
        return;
    }
    form.post('/roles');
}
</script>

<template>
    <Head :title="role ? 'Edit role' : 'New role'" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="role ? role.name : 'New role'" eyebrow="Roles" />
        </template>
        <form class="space-y-4" @submit.prevent="save">
            <div class="ui-card grid gap-3 p-4 md:grid-cols-2">
                <input v-model="form.name" required class="ui-input" placeholder="Role name" :disabled="role?.is_system && role?.name === 'Super Admin'">
                <input v-model="form.description" class="ui-input" placeholder="Description">
                <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Active</label>
            </div>
            <section v-for="module in modules" :key="module.module" class="ui-card">
                <div class="flex items-center justify-between px-4 py-3">
                    <button type="button" class="font-medium" @click="open = open === module.module ? '' : module.module">{{ module.module }}</button>
                    <span class="space-x-3 text-xs">
                        <button type="button" @click="setModule(module, true)">Select all</button>
                        <button type="button" @click="setModule(module, false)">Clear</button>
                    </span>
                </div>
                <div v-if="open === module.module" class="flex flex-wrap gap-3 border-t px-4 py-3">
                    <template v-for="action in module.actions" :key="action.permission">
                        <a v-if="action.approval" href="/approvals/settings" class="flex items-center gap-2 text-sm text-slate-400 hover:underline" title="Approvers are chosen per staff member in Approval Settings">
                            <input type="checkbox" disabled>
                            {{ action.label || action.action_label || action.action }} (Approval Settings)
                        </a>
                        <label v-else class="flex items-center gap-2 text-sm" :title="action.permission">
                            <input type="checkbox" :checked="form.permissions.includes(action.permission)" :disabled="role?.slug === 'super_admin' || (role?.is_system && role?.name === 'Super Admin')" @change="toggle(action.permission, $event.target.checked)">
                            {{ action.label || action.action_label || action.action }}
                        </label>
                    </template>
                </div>
            </section>
            <div v-if="role?.users?.length" class="text-sm text-slate-600">
                Assigned users: {{ role.users.map((user) => user.name).join(', ') }}
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button class="ui-btn-primary" :disabled="form.processing || (role?.is_system && role?.name === 'Super Admin')">Save role</button>
                <a href="/roles" class="text-sm text-slate-600 hover:underline">Cancel</a>
                <button v-if="role" type="button" class="text-sm text-slate-500 hover:underline" @click="form.permissions = modules.flatMap((module) => module.actions.filter((action) => action.granted && !action.approval).map((action) => action.permission))">Reset</button>
            </div>
        </form>
    </AdminLayout>
</template>
