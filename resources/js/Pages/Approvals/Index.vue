<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    items: { type: Array, required: true },
    canConfigure: { type: Boolean, default: false },
});

const total = computed(() => props.types.reduce((sum, type) => sum + type.count, 0));

function filterHref(type) {
    return type ? `/approvals?type=${type}` : '/approvals';
}

function chipClass(active) {
    return [
        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition',
        active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300',
    ];
}

function open(item) {
    router.visit(item.url);
}
</script>

<template>
    <Head title="Approvals" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Approvals" eyebrow="My pending actions">
                <Link v-if="canConfigure" href="/approvals/settings" class="ui-btn-secondary">Approval settings</Link>
            </PageHeader>
        </template>

        <div class="mb-4 flex flex-wrap gap-2">
            <Link :href="filterHref(null)" :class="chipClass(!filters.type)" preserve-scroll>
                All
                <span class="rounded-full bg-amber-500 px-1.5 text-xs font-semibold text-white">{{ total }}</span>
            </Link>
            <Link
                v-for="type in types"
                :key="type.key"
                :href="filterHref(type.key)"
                :class="chipClass(filters.type === type.key)"
                preserve-scroll
            >
                {{ type.label }}
                <span
                    class="rounded-full px-1.5 text-xs font-semibold"
                    :class="type.count > 0 ? 'bg-amber-500 text-white' : 'bg-slate-100 text-slate-500'"
                >{{ type.count }}</span>
            </Link>
        </div>

        <div class="ui-table-wrap">
            <table v-if="items.length" class="ui-table">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Project</th>
                        <th>Date</th>
                        <th>Details</th>
                        <th>Requested by</th>
                        <th class="text-right">Amount</th>
                        <th>Waiting since</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in items" :key="item.key" class="cursor-pointer" @click="open(item)">
                        <td>
                            <div class="text-xs text-slate-500">{{ item.type_label }}</div>
                            <Link :href="item.url" class="font-medium text-amber-700 hover:underline" @click.stop>{{ item.number }}</Link>
                        </td>
                        <td>
                            <span v-if="item.project" :title="item.project.name">{{ item.project.code }}</span>
                            <span v-else class="text-slate-400">Multi-site</span>
                        </td>
                        <td class="whitespace-nowrap">{{ item.date }}</td>
                        <td class="max-w-sm">
                            <div class="truncate" :title="item.summary">{{ item.summary }}</div>
                            <span v-if="item.flag" class="mt-1 inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-600">{{ item.flag }}</span>
                        </td>
                        <td>{{ item.requested_by || '-' }}</td>
                        <td class="whitespace-nowrap text-right">{{ item.amount !== null ? formatInr(item.amount) : '-' }}</td>
                        <td class="whitespace-nowrap text-slate-500">{{ item.waiting_since }}</td>
                        <td class="text-right">
                            <Link :href="item.url" class="ui-btn-primary whitespace-nowrap" @click.stop>Open</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else :title="types.length ? 'Nothing is waiting for your approval.' : 'You are not set up as an approver for any document type.'" />
        </div>
    </AdminLayout>
</template>
