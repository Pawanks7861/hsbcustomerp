<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    staff: { type: Array, required: true },
    types: { type: Array, required: true },
});

const search = ref('');

const form = useForm({
    types: props.types.map((type) => ({ key: type.key, users: [...type.users] })),
});

const staffIds = computed(() => props.staff.map((person) => person.id));

const visibleStaff = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return props.staff;
    }

    return props.staff.filter((person) => [person.name, person.email, person.role, person.designation]
        .filter(Boolean)
        .some((value) => value.toLowerCase().includes(term)));
});

function row(type) {
    return form.types.find((item) => item.key === type.key);
}

function isApprover(type, person) {
    return row(type).users.includes(person.id);
}

function toggle(type, person) {
    const item = row(type);
    item.users = item.users.includes(person.id)
        ? item.users.filter((id) => id !== person.id)
        : [...item.users, person.id];
}

function columnState(type) {
    const ids = staffIds.value;

    if (!ids.length) {
        return 'all';
    }

    const selected = ids.filter((id) => row(type).users.includes(id)).length;

    return selected === ids.length ? 'all' : selected === 0 ? 'none' : 'some';
}

function toggleColumn(type) {
    const item = row(type);
    const ids = staffIds.value;
    item.users = columnState(type) === 'all'
        ? item.users.filter((id) => !ids.includes(id))
        : [...new Set([...item.users, ...ids])];
}

function rowState(person) {
    const selected = props.types.filter((type) => row(type).users.includes(person.id)).length;

    return selected === props.types.length ? 'all' : selected === 0 ? 'none' : 'some';
}

function toggleRow(person) {
    const grant = rowState(person) !== 'all';

    form.types.forEach((item) => {
        const has = item.users.includes(person.id);

        if (grant && !has) {
            item.users = [...item.users, person.id];
        }

        if (!grant && has) {
            item.users = item.users.filter((id) => id !== person.id);
        }
    });
}

function approverCount(type) {
    return props.staff.filter((person) => isApprover(type, person)).length;
}

function submit() {
    form.put('/approvals/settings', { preserveScroll: true });
}
</script>

<template>
    <Head title="Approval Settings" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Approval Settings" eyebrow="Admin">
                <Link href="/approvals" class="ui-btn-secondary">Open approvals</Link>
                <button type="button" class="ui-btn-primary" :disabled="form.processing || !form.isDirty" @click="submit">Save settings</button>
            </PageHeader>
        </template>

        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <p class="max-w-3xl text-sm text-slate-500">
                Tick the staff who can approve each document. Approvers see pending documents in the
                <span class="font-medium text-slate-700">Approvals</span> menu and get the approve / post buttons.
            </p>
            <input v-model="search" type="search" class="ui-input w-64" placeholder="Search staff">
        </div>

        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="min-w-[220px]">Staff</th>
                        <th v-for="type in types" :key="type.key" class="text-center" :title="type.description">
                            <div class="whitespace-nowrap">{{ type.label }}</div>
                            <div class="text-[11px] font-normal normal-case text-slate-400">{{ type.action }}</div>
                        </th>
                        <th class="text-center">All</th>
                    </tr>
                    <tr>
                        <th class="text-xs font-normal normal-case text-slate-400">Select whole column</th>
                        <th v-for="type in types" :key="`${type.key}-all`" class="text-center">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300"
                                :checked="columnState(type) === 'all'"
                                :indeterminate="columnState(type) === 'some'"
                                :disabled="!staffIds.length"
                                @change="toggleColumn(type)"
                            >
                            <div class="text-[11px] font-normal normal-case text-slate-400">{{ approverCount(type) }} approver(s)</div>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="person in visibleStaff" :key="person.id">
                        <td>
                            <div class="font-medium text-slate-800">{{ person.name }}</div>
                            <div class="text-xs text-slate-500">
                                {{ [person.designation, person.role, person.email].filter(Boolean).join(' · ') }}
                            </div>
                            <span v-if="person.super_admin" class="mt-1 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">Super admin</span>
                        </td>
                        <td v-for="type in types" :key="`${person.id}-${type.key}`" class="text-center">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :checked="isApprover(type, person)"
                                @change="toggle(type, person)"
                            >
                        </td>
                        <td class="text-center">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded border-slate-300"
                                :checked="rowState(person) === 'all'"
                                :indeterminate="rowState(person) === 'some'"
                                @change="toggleRow(person)"
                            >
                        </td>
                    </tr>
                    <tr v-if="!visibleStaff.length">
                        <td :colspan="types.length + 2" class="py-8 text-center text-sm text-slate-500">No staff match your search.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="button" class="ui-btn-primary" :disabled="form.processing || !form.isDirty" @click="submit">Save settings</button>
        </div>
    </AdminLayout>
</template>
