<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    member: { type: Object, required: true },
    modules: { type: Array, required: true },
});

const open = ref(props.modules[0]?.module || '');
const form = useForm({
    overrides: props.modules.flatMap((module) => module.actions.map((action) => ({
        permission: action.permission,
        mode: action.mode,
    }))),
});

function mode(permission) {
    return form.overrides.find((row) => row.permission === permission);
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
                <span class="text-sm text-slate-500">{{ member.role || 'No role' }}</span>
            </PageHeader>
        </template>
        <form class="space-y-3" @submit.prevent="save">
            <section v-for="module in modules" :key="module.module" class="ui-card">
                <button type="button" class="flex w-full items-center justify-between px-4 py-3 text-left font-medium" @click="open = open === module.module ? '' : module.module">
                    {{ module.module }}
                </button>
                <div v-if="open === module.module" class="overflow-x-auto border-t px-4 py-3">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500">
                                <th class="py-2">Action</th>
                                <th>Setting</th>
                                <th>Effective</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="action in module.actions" :key="action.permission">
                                <td class="py-2">{{ action.action }}</td>
                                <td>
                                    <select v-if="mode(action.permission)" v-model="mode(action.permission).mode" class="ui-input">
                                        <option value="inherit">Inherit from role</option>
                                        <option value="allow">Allow</option>
                                        <option value="deny">Deny</option>
                                    </select>
                                </td>
                                <td>{{ action.source }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <button class="ui-btn-primary" :disabled="form.processing">Save permissions</button>
        </form>
    </AdminLayout>
</template>
