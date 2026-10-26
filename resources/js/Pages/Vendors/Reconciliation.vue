<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';
import { visitListing } from '../../lib/listing';

const props = defineProps({
    vendor: { type: Object, default: null },
    filters: { type: Object, required: true },
    vendors: { type: Array, required: true },
    projects: { type: Array, required: true },
    reconciliation: { type: Object, default: null },
    rows: { type: Object, default: null },
});

const vendorId = ref(props.filters.vendor_id || '');
const projectId = ref(props.filters.project_id || '');
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');

const singleVendor = computed(() => props.vendor !== null);

function apply() {
    const query = {
        project_id: projectId.value || undefined,
        from: from.value || undefined,
        to: to.value || undefined,
    };

    if (singleVendor.value) {
        router.get(`/vendors/${props.vendor.id}/reconciliation`, query, { preserveState: true });

        return;
    }

    visitListing({
        project_id: projectId.value,
        from: from.value,
        to: to.value,
        vendor_id: vendorId.value,
    });
}

function ledgerHref(id) {
    const query = new URLSearchParams();
    if (projectId.value) {
        query.set('project_id', projectId.value);
    }
    if (from.value) {
        query.set('from', from.value);
    }
    if (to.value) {
        query.set('to', to.value);
    }
    const suffix = query.toString() ? `?${query.toString()}` : '';

    return `/vendors/${id}/ledger${suffix}`;
}

function badgeClass(reconciled) {
    return reconciled
        ? 'bg-emerald-100 text-emerald-800'
        : 'bg-amber-100 text-amber-900';
}
</script>

<template>
    <Head :title="singleVendor ? `${vendor.name} Reconciliation` : 'Vendor Reconciliation'" />
    <AdminLayout>
        <template #header>
            <PageHeader
                :title="singleVendor ? `${vendor.name} Reconciliation` : 'Vendor Reconciliation'"
                :eyebrow="singleVendor ? vendor.vendor_code : 'Ledger vs balances'"
            >
                <Link v-if="singleVendor" :href="ledgerHref(vendor.id)" class="ui-btn-secondary">View ledger</Link>
                <Link href="/vendors/report" class="ui-btn-secondary">Vendor report</Link>
            </PageHeader>
        </template>

        <form class="mb-4 grid gap-3 md:grid-cols-5" @submit.prevent="apply">
            <select v-if="!singleVendor" v-model="vendorId" class="ui-input">
                <option value="">All vendors</option>
                <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
            </select>
            <select v-model="projectId" class="ui-input">
                <option value="">All projects</option>
                <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option>
            </select>
            <input v-model="from" type="date" class="ui-input">
            <input v-model="to" type="date" class="ui-input">
            <button class="ui-btn-primary">Filter</button>
        </form>

        <template v-if="singleVendor && reconciliation">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <span class="rounded-full px-3 py-1 text-sm font-medium" :class="badgeClass(reconciliation.reconciled)">
                    {{ reconciliation.reconciled ? 'Reconciled' : 'Discrepancy' }}
                </span>
                <span class="text-sm text-slate-600">
                    Difference (expected vs outstanding): <strong>{{ formatInr(reconciliation.difference) }}</strong>
                </span>
                <span class="text-sm text-slate-600">
                    Payable basis closing vs outstanding: <strong>{{ formatInr(reconciliation.payable_basis_difference) }}</strong>
                </span>
            </div>

            <div class="mb-6 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Ledger closing (full)</p>
                    <p class="text-lg font-semibold">{{ formatInr(reconciliation.ledger_closing) }}</p>
                </div>
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Payable basis closing</p>
                    <p class="text-lg font-semibold">{{ formatInr(reconciliation.payable_basis_closing) }}</p>
                </div>
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Expected payable</p>
                    <p class="text-lg font-semibold">{{ formatInr(reconciliation.expected_payable) }}</p>
                </div>
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Outstanding payable</p>
                    <p class="text-lg font-semibold">{{ formatInr(reconciliation.outstanding_payable) }}</p>
                </div>
            </div>

            <div class="mb-6 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Advance paid</p>
                    <p class="font-semibold">{{ formatInr(reconciliation.advance_paid) }}</p>
                </div>
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Advance adjusted</p>
                    <p class="font-semibold">{{ formatInr(reconciliation.advance_adjusted) }}</p>
                </div>
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Available advance</p>
                    <p class="font-semibold">{{ formatInr(reconciliation.available_advance) }}</p>
                </div>
                <div class="ui-card p-4 text-sm">
                    <p class="text-slate-500">Refund due</p>
                    <p class="font-semibold">{{ formatInr(reconciliation.refund_due) }}</p>
                </div>
            </div>

            <div class="ui-card overflow-x-auto p-4">
                <h3 class="mb-3 font-semibold">Payable components</h3>
                <table class="ui-table min-w-[480px]">
                    <tbody>
                        <tr><td class="text-slate-600">Opening (payable basis)</td><td class="text-right">{{ formatInr(reconciliation.payable_basis_opening) }}</td></tr>
                        <tr><td class="text-slate-600">+ Invoice credits</td><td class="text-right">{{ formatInr(reconciliation.payable_components.invoice_credits) }}</td></tr>
                        <tr><td class="text-slate-600">− Payment debits</td><td class="text-right">{{ formatInr(reconciliation.payable_components.payment_debits) }}</td></tr>
                        <tr><td class="text-slate-600">− Adjustment debits</td><td class="text-right">{{ formatInr(reconciliation.payable_components.adjustment_debits) }}</td></tr>
                        <tr><td class="text-slate-600">− Notes debits</td><td class="text-right">{{ formatInr(reconciliation.payable_components.notes_debits) }}</td></tr>
                        <tr class="font-semibold"><td>= Expected payable</td><td class="text-right">{{ formatInr(reconciliation.expected_payable) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <TableToolbar v-if="!singleVendor" :search="filters.search" placeholder="Search vendor name, code, GST…" />
        <div v-if="!singleVendor && rows" class="ui-table-wrap">
            <table class="ui-table min-w-[1100px]">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Status</th>
                        <th class="text-right">Outstanding</th>
                        <th class="text-right">Expected</th>
                        <th class="text-right">Payable basis closing</th>
                        <th class="text-right">Difference</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="rows.data.length === 0"><td colspan="7" class="py-8 text-center text-slate-400">{{ filters.search ? 'No matching records found.' : 'No vendors.' }}</td></tr>
                    <tr v-for="row in rows.data" :key="row.id">
                        <td>
                            <Link :href="`/vendors/${row.id}/reconciliation`" class="font-medium hover:underline">{{ row.name }}</Link>
                            <span class="ml-1 text-xs text-slate-400">{{ row.vendor_code }}</span>
                        </td>
                        <td>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass(row.reconciled)">
                                {{ row.reconciled ? 'OK' : 'Check' }}
                            </span>
                        </td>
                        <td class="text-right">{{ formatInr(row.outstanding_payable) }}</td>
                        <td class="text-right">{{ formatInr(row.expected_payable) }}</td>
                        <td class="text-right">{{ formatInr(row.payable_basis_closing) }}</td>
                        <td class="text-right">{{ formatInr(row.difference) }}</td>
                        <td class="text-right">
                            <Link :href="ledgerHref(row.id)" class="text-sm hover:underline">Ledger</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination v-if="!singleVendor && rows" :paginator="rows" label="vendors" />
    </AdminLayout>
</template>
