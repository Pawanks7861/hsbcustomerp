<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    vendor: { type: Object, required: true },
    projects: { type: Array, required: true },
    filters: { type: Object, required: true },
    opening: { type: String, required: true },
    closing: { type: String, required: true },
    total_debit: { type: String, required: true },
    total_credit: { type: String, required: true },
    entries: { type: Object, required: true },
});

const projectId = ref(props.filters.project_id || '');
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

const outstandingHref = computed(() => {
    const params = new URLSearchParams();
    if (props.filters.from) {
        params.set('from', props.filters.from);
    }
    if (props.filters.to) {
        params.set('to', props.filters.to);
    }
    if (props.filters.project_id) {
        params.set('project_id', String(props.filters.project_id));
    }
    const qs = params.toString();

    return `/reports/vendor-outstanding${qs ? `?${qs}` : ''}`;
});

function apply() {
    visitListing({ project_id: projectId.value, from: from.value, to: to.value });
}
</script>

<template>
    <Head :title="`${vendor.name} Ledger`" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="`${vendor.name} Ledger`" :eyebrow="vendor.vendor_code">
                <Link :href="outstandingHref" class="ui-btn-secondary">Back to Vendor Outstanding</Link>
                <Link :href="`/vendors/${vendor.id}`" class="ui-btn-secondary">Vendor</Link>
            </PageHeader>
        </template>
        <form class="mb-4 grid gap-3 md:grid-cols-4" @submit.prevent="apply">
            <select v-model="projectId" class="ui-input"><option value="">All projects</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option></select>
            <input v-model="from" type="date" class="ui-input">
            <input v-model="to" type="date" class="ui-input">
            <button class="ui-btn-primary">Filter</button>
        </form>
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Opening</p><p class="text-lg font-semibold">{{ formatInr(opening) }}</p></div>
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Debit</p><p class="text-lg font-semibold">{{ formatInr(total_debit) }}</p></div>
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Credit</p><p class="text-lg font-semibold">{{ formatInr(total_credit) }}</p></div>
            <div class="ui-card p-4 text-sm"><p class="text-slate-500">Closing</p><p class="text-lg font-semibold">{{ formatInr(closing) }}</p></div>
        </div>
        <TableToolbar :search="filters.search" placeholder="Search date, type, reference, project, amount…" />
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[980px]">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>PO</th>
                        <th>Project</th>
                        <th class="text-right">Debit</th>
                        <th class="text-right">Credit</th>
                        <th class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.data.length === 0"><td colspan="8" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No entries.' }}</td></tr>
                    <tr v-for="(entry, index) in entries.data" :key="`${entries.current_page}-${index}`">
                        <td>{{ entry.date }}</td>
                        <td>{{ entry.type }}</td>
                        <td>
                            <Link
                                v-if="entry.href_reference && entry.reference"
                                :href="entry.href_reference"
                                class="text-amber-900 underline-offset-2 hover:underline focus:underline"
                            >
                                {{ entry.reference }}
                            </Link>
                            <template v-else>{{ entry.reference || '—' }}</template>
                        </td>
                        <td>
                            <Link
                                v-if="entry.href_po && entry.po_number"
                                :href="entry.href_po"
                                class="text-amber-900 underline-offset-2 hover:underline focus:underline"
                            >
                                {{ entry.po_number }}
                            </Link>
                            <template v-else>{{ entry.po_number || '—' }}</template>
                        </td>
                        <td>
                            <Link
                                v-if="entry.href_project && entry.project"
                                :href="entry.href_project"
                                class="text-amber-900 underline-offset-2 hover:underline focus:underline"
                            >
                                {{ entry.project }}
                            </Link>
                            <template v-else>{{ entry.project || '—' }}</template>
                        </td>
                        <td class="text-right">{{ entry.debit ? formatInr(entry.debit) : '—' }}</td>
                        <td class="text-right">{{ entry.credit ? formatInr(entry.credit) : '—' }}</td>
                        <td class="text-right">{{ formatInr(entry.balance) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="entries" label="entries" />
    </AdminLayout>
</template>
