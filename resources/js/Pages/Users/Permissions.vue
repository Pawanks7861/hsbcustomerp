<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    member: { type: Object, required: true },
    modules: { type: Array, required: true },
});

const search = ref('');
const open = ref(props.modules[0]?.module || '');
const form = useForm({
    overrides: props.modules.flatMap((module) => module.actions.map((action) => ({
        permission: action.permission,
        mode: action.mode,
    }))),
});

const filteredModules = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) {
        return props.modules;
    }

    return props.modules
        .map((module) => ({
            ...module,
            actions: module.actions.filter((action) => {
                return module.module.toLowerCase().includes(query)
                    || action.action.toLowerCase().includes(query)
                    || (action.label || '').toLowerCase().includes(query)
                    || (action.action_label || '').toLowerCase().includes(query)
                    || action.permission.toLowerCase().includes(query)
                    || action.source.toLowerCase().includes(query);
            }),
        }))
        .filter((module) => module.actions.length > 0);
});

function mode(permission) {
    return form.overrides.find((row) => row.permission === permission);
}

function resetModule(module) {
    module.actions.forEach((action) => {
        const row = mode(action.permission);

        if (row && !action.approval) {
            row.mode = 'inherit';
        }
    });
}

function resetAllOverrides() {
    form.overrides.forEach((row) => {
        row.mode = 'inherit';
    });
}

function save() {
    if (!window.confirm('Save these individual permission changes?')) {
        return;
    }
    form.put(`/users/${props.member.id}/permissions`);
}
</script>

<template>
    <Head title="Individual Permissions" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="member.name" eyebrow="Individual permissions">
                <span class="text-sm text-slate-500">{{ member.role || 'No roles' }}</span>
            </PageHeader>
        </template>
        <form class="space-y-3" @submit.prevent="save">
            <div class="flex flex-wrap items-center gap-3">
                <input
                    v-model="search"
                    type="search"
                    class="ui-input max-w-md flex-1"
                    placeholder="Search module, action, or permission key…"
                />
                <button type="button" class="ui-btn-secondary" @click="resetAllOverrides">
                    Reset all overrides
                </button>
            </div>
            <section v-for="module in filteredModules" :key="module.module" class="ui-card">
                <div class="flex w-full items-center justify-between gap-3 px-4 py-3">
                    <button type="button" class="flex-1 text-left font-medium" @click="open = open === module.module ? '' : module.module">
                        {{ module.module }}
                    </button>
                    <button
                        type="button"
                        class="text-xs text-slate-500 hover:text-slate-800 hover:underline"
                        @click="resetModule(module)"
                    >
                        Reset module to role default
                    </button>
                </div>
                <div v-if="open === module.module" class="overflow-x-auto border-t px-4 py-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="py-2 pr-4">Action</th>
                                <th class="py-2 pr-4">Permission key</th>
                                <th class="py-2 pr-4">Inherited (source)</th>
                                <th class="py-2 pr-4">Override</th>
                                <th class="py-2">Effective</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="action in module.actions" :key="action.permission" class="border-t border-slate-100">
                                <td class="py-2 pr-4">{{ action.label || action.action_label || action.action.replaceAll('_', ' ') }}</td>
                                <td class="py-2 pr-4 font-mono text-xs text-slate-600">{{ action.permission }}</td>
                                <td class="py-2 pr-4 text-slate-600">{{ action.source }}</td>
                                <td class="py-2 pr-4">
                                    <a v-if="action.approval" href="/approvals/settings" class="text-xs text-slate-500 hover:underline">Set in Approval Settings</a>
                                    <select v-else-if="mode(action.permission)" v-model="mode(action.permission).mode" class="ui-input">
                                        <option value="inherit">Inherit from roles</option>
                                        <option value="allow">Allow</option>
                                        <option value="deny">Deny</option>
                                    </select>
                                </td>
                                <td class="py-2">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="action.effective ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
                                    >
                                        {{ action.effective ? 'Yes' : 'No' }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <p v-if="filteredModules.length === 0" class="text-sm text-slate-500">No permissions match your search.</p>
            <div class="flex flex-wrap items-center gap-3">
                <button class="ui-btn-primary" :disabled="form.processing">Save permissions</button>
                <a href="/users" class="text-sm text-slate-600 hover:underline">Cancel</a>
            </div>
        </form>
    </AdminLayout>
</template>
