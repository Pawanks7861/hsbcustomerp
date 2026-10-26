<script setup>
import { reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import TablePagination from '../../Components/TablePagination.vue';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    filters: { type: Object, required: true },
    logs: { type: Object, required: true },
    events: { type: Array, required: true },
    modules: { type: Array, required: true },
    users: { type: Array, required: true },
    projects: { type: Array, required: true },
});

const form = reactive({
    search: props.filters.search || '',
    user_id: props.filters.user_id || '',
    event: props.filters.event || '',
    module: props.filters.module || '',
    project_id: props.filters.project_id || '',
    from: props.filters.from || '',
    to: props.filters.to || '',
    batch: props.filters.batch || '',
});

const expanded = ref(null);

const eventTones = {
    created: 'bg-emerald-50 text-emerald-700',
    updated: 'bg-sky-50 text-sky-700',
    deleted: 'bg-red-50 text-red-700',
    action: 'bg-amber-50 text-amber-700',
    login: 'bg-slate-100 text-slate-700',
    logout: 'bg-slate-100 text-slate-500',
    login_failed: 'bg-red-50 text-red-700',
    password_reset: 'bg-violet-50 text-violet-700',
};

function eventLabel(key) {
    return props.events.find((event) => event.key === key)?.label || key;
}

function apply() {
    visitListing({ ...form, search: String(form.search || '').trim().slice(0, 100) });
}

function reset() {
    Object.keys(form).forEach((key) => { form[key] = ''; });
    apply();
}

function showBatch(batch) {
    Object.keys(form).forEach((key) => { form[key] = ''; });
    form.batch = batch;
    apply();
}

function toggle(id) {
    expanded.value = expanded.value === id ? null : id;
}

function display(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function changeRows(properties) {
    if (!properties || typeof properties !== 'object') {
        return null;
    }

    if (properties.old && properties.new) {
        return Object.keys(properties.new).map((field) => ({ field, old: properties.old[field], new: properties.new[field] }));
    }

    return null;
}

function attributeRows(properties) {
    return properties?.attributes ? Object.entries(properties.attributes).map(([field, value]) => ({ field, value })) : null;
}
</script>

<template>
    <Head title="Audit Log" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Audit Log" eyebrow="Everything that happens in the ERP" />
        </template>

        <form class="ui-card mb-4 grid gap-3 p-4 md:grid-cols-4 xl:grid-cols-8" @submit.prevent="apply">
            <label class="text-sm md:col-span-2">Search
                <input v-model="form.search" type="search" maxlength="100" class="ui-input" placeholder="Search records...">
            </label>
            <label class="text-sm">User
                <select v-model="form.user_id" class="ui-input">
                    <option value="">All users</option>
                    <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                </select>
            </label>
            <label class="text-sm">Event
                <select v-model="form.event" class="ui-input">
                    <option value="">All events</option>
                    <option v-for="event in events" :key="event.key" :value="event.key">{{ event.label }}</option>
                </select>
            </label>
            <label class="text-sm">Module
                <select v-model="form.module" class="ui-input">
                    <option value="">All modules</option>
                    <option v-for="module in modules" :key="module.key" :value="module.key">{{ module.label }}</option>
                </select>
            </label>
            <label class="text-sm">Project
                <select v-model="form.project_id" class="ui-input">
                    <option value="">All projects</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_code }} · {{ project.project_name }}</option>
                </select>
            </label>
            <label class="text-sm">From<input v-model="form.from" type="date" class="ui-input"></label>
            <label class="text-sm">To<input v-model="form.to" type="date" class="ui-input"></label>
            <div class="flex flex-wrap items-center gap-2 md:col-span-4 xl:col-span-8">
                <button class="ui-btn-primary">Apply filters</button>
                <button type="button" class="ui-btn-secondary" @click="reset">Reset</button>
                <span v-if="form.batch" class="rounded-full bg-amber-50 px-3 py-1 text-xs text-amber-700">Showing one request only</span>
                <span class="ml-auto text-sm text-slate-500">{{ logs.total }} entries</span>
            </div>
        </form>

        <div class="ui-table-wrap">
            <table v-if="logs.data.length" class="ui-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Event</th>
                        <th>Record</th>
                        <th>Project</th>
                        <th>IP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="log in logs.data" :key="log.id">
                        <tr class="cursor-pointer" @click="toggle(log.id)">
                            <td class="whitespace-nowrap text-slate-600">{{ log.created_at }}</td>
                            <td>
                                <div>{{ log.user || 'System' }}</div>
                                <div v-if="log.user_email" class="text-xs text-slate-500">{{ log.user_email }}</div>
                            </td>
                            <td>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="eventTones[log.event] || eventTones.action">{{ eventLabel(log.event) }}</span>
                                <div class="mt-1 text-xs text-slate-500">{{ log.action }}</div>
                            </td>
                            <td class="max-w-xs">
                                <div v-if="log.module" class="text-xs text-slate-500">{{ log.module }} #{{ log.subject_id }}</div>
                                <div class="truncate" :title="log.subject_label">{{ log.subject_label || (log.module ? '' : '—') }}</div>
                            </td>
                            <td class="whitespace-nowrap">{{ log.project || '—' }}</td>
                            <td class="whitespace-nowrap text-slate-500">{{ log.ip_address || '—' }}</td>
                            <td class="text-right text-sm text-amber-700">{{ expanded === log.id ? 'Hide' : 'Details' }}</td>
                        </tr>
                        <tr v-if="expanded === log.id">
                            <td colspan="7" class="bg-slate-50">
                                <div class="space-y-3 p-2 text-sm">
                                    <table v-if="changeRows(log.properties)" class="w-full max-w-4xl text-left">
                                        <thead><tr class="text-xs uppercase text-slate-500"><th class="py-1 pr-4">Field</th><th class="py-1 pr-4">Before</th><th class="py-1">After</th></tr></thead>
                                        <tbody>
                                            <tr v-for="row in changeRows(log.properties)" :key="row.field" class="border-t border-slate-200 align-top">
                                                <td class="py-1 pr-4 font-medium">{{ row.field }}</td>
                                                <td class="break-all py-1 pr-4 text-red-700">{{ display(row.old) }}</td>
                                                <td class="break-all py-1 text-emerald-700">{{ display(row.new) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <table v-else-if="attributeRows(log.properties)" class="w-full max-w-4xl text-left">
                                        <tbody>
                                            <tr v-for="row in attributeRows(log.properties)" :key="row.field" class="border-t border-slate-200 align-top">
                                                <td class="w-56 py-1 pr-4 font-medium">{{ row.field }}</td>
                                                <td class="break-all py-1">{{ display(row.value) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <pre v-else-if="log.properties" class="max-w-4xl overflow-x-auto whitespace-pre-wrap rounded-lg bg-white p-3 text-xs">{{ JSON.stringify(log.properties, null, 2) }}</pre>
                                    <p v-else class="text-slate-500">No extra details recorded.</p>
                                    <div class="grid gap-1 text-xs text-slate-500">
                                        <p v-if="log.url"><span class="font-medium text-slate-700">{{ log.method }}</span> <span class="break-all">{{ log.url }}</span></p>
                                        <p v-if="log.user_agent" class="break-all">{{ log.user_agent }}</p>
                                        <button v-if="log.batch_id && !filters.batch" type="button" class="w-fit text-amber-700 hover:underline" @click.stop="showBatch(log.batch_id)">Show everything saved in this same request</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <EmptyState v-else title="No matching records found." />
        </div>

        <TablePagination :paginator="logs" label="entries" />
    </AdminLayout>
</template>
