<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { formatInr } from '../../lib/money';

defineProps({
    project: { type: Object, required: true },
    purchaseOrder: { type: Object, required: true },
    history: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`${purchaseOrder.po_number} rate history`" />
    <AdminLayout :project="project">
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Rate history</h1>
                    <p class="text-sm text-slate-500">{{ purchaseOrder.po_number }}</p>
                </div>
                <Link
                    :href="`/projects/${project.id}/purchase-orders/${purchaseOrder.id}`"
                    class="ui-btn-secondary"
                >
                    Back to purchase order
                </Link>
            </div>
        </template>

        <div class="ui-card space-y-8 p-6 sm:p-8">
            <section>
                <h2 class="text-sm font-semibold text-slate-700">Amendment requests</h2>
                <div v-if="history.amendments.length === 0" class="mt-2 text-sm text-slate-500">No amendment records.</div>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="min-w-full border-collapse text-sm">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left font-medium text-slate-500">Line</th>
                                <th class="px-3 py-2 text-left font-medium text-slate-500">Status</th>
                                <th class="px-3 py-2 text-right font-medium text-slate-500">Previous</th>
                                <th class="px-3 py-2 text-right font-medium text-slate-500">Proposed</th>
                                <th class="px-3 py-2 text-left font-medium text-slate-500">Reason</th>
                                <th class="px-3 py-2 text-left font-medium text-slate-500">Requested</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in history.amendments" :key="row.id">
                                <td class="border border-slate-200 px-3 py-2">{{ row.item_description || '—' }}</td>
                                <td class="border border-slate-200 px-3 py-2 capitalize">{{ row.status }}</td>
                                <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ formatInr(row.previous_rate) }}</td>
                                <td class="border border-slate-200 px-3 py-2 text-right tabular-nums">{{ formatInr(row.proposed_rate) }}</td>
                                <td class="border border-slate-200 px-3 py-2">{{ row.reason }}</td>
                                <td class="border border-slate-200 px-3 py-2 text-xs text-slate-600">
                                    {{ row.requested_by || '—' }}<br />
                                    {{ row.requested_at || '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="text-sm font-semibold text-slate-700">Activity log</h2>
                <div v-if="history.activity.length === 0" class="mt-2 text-sm text-slate-500">No rate-related activity.</div>
                <ul v-else class="mt-3 space-y-3 text-sm">
                    <li
                        v-for="entry in history.activity"
                        :key="entry.id"
                        class="rounded-md border border-slate-200 bg-stone-50 p-3"
                    >
                        <p class="font-medium">{{ entry.action }}</p>
                        <p class="text-slate-600">{{ entry.user || 'System' }} · {{ entry.created_at }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
