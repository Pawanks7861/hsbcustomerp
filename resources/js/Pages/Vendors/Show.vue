<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import TableActionIcon from '../../Components/TableActionIcon.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { formatInr } from '../../lib/money';

const props = defineProps({
    vendor: { type: Object, required: true },
    summary: { type: Object, required: true },
    tab: { type: String, required: true },
    filters: { type: Object, default: () => ({ search: '' }) },
    projects: { type: Array, default: () => [] },
    purchaseOrders: { type: Object, default: null },
    invoices: { type: Object, default: null },
    payments: { type: Object, default: null },
    advances: { type: Object, default: null },
    refunds: { type: Object, default: null },
    transactions: { type: Object, default: null },
});

const tabs = ['overview', 'purchase-orders', 'invoices', 'payments', 'advances', 'refunds', 'transactions'];
const listTabs = ['purchase-orders', 'invoices', 'payments', 'advances', 'transactions'];
const tabProps = {
    'purchase-orders': 'purchaseOrders',
    invoices: 'invoices',
    payments: 'payments',
    advances: 'advances',
    refunds: 'refunds',
    transactions: 'transactions',
};
const refund = useForm({ project_id: '', amount_due: '', remarks: '' });
const received = useForm({ recovery_id: '', refund_date: new Date().toISOString().slice(0, 10), amount: '', refund_mode: 'bank_transfer', reference_number: '', remarks: '' });
const loadingTab = ref(null);

const tabProp = computed(() => tabProps[props.tab] ?? null);
const tabData = computed(() => (tabProp.value ? props[tabProp.value] : null));
const tabOnly = computed(() => (tabProp.value ? [tabProp.value, 'filters', 'tab'] : []));
const activeList = computed(() => (listTabs.includes(props.tab) ? tabData.value : null));
const tabMissing = computed(() => tabProp.value !== null && tabData.value === null);

const emptyText = computed(() => (props.filters.search ? 'No matching records found.' : 'No records yet.'));

function openTab(name) {
    if (loadingTab.value === name || (name === props.tab && !tabMissing.value)) {
        return;
    }

    const params = { tab: name };
    const projectId = new URL(window.location.href).searchParams.get('project_id');
    if (projectId) {
        params.project_id = projectId;
    }
    const only = tabProps[name] ? [tabProps[name], 'filters', 'tab'] : ['filters', 'tab'];
    router.get(`/vendors/${props.vendor.id}`, params, {
        only,
        preserveState: true,
        preserveScroll: true,
        onStart: () => { loadingTab.value = name; },
        onFinish: () => { loadingTab.value = null; },
    });
}

function confirmDelete() {
    return confirm(`Delete vendor ${props.vendor.name}? This cannot be undone.`);
}

function markRefund() {
    refund.post(`/vendors/${props.vendor.id}/recoveries`, { preserveScroll: true });
}

function recordRefund() {
    if (!received.recovery_id) {
        return;
    }

    received.post(`/vendors/${props.vendor.id}/recoveries/${received.recovery_id}/refunds`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="vendor.name" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="vendor.name" :eyebrow="vendor.vendor_code">
                <TableActionIcon action="edit" :href="`/vendors/${vendor.id}/edit`" />
                <Link :href="`/vendors/${vendor.id}/payments/create?payment_type=advance`" class="ui-btn-primary">Record Advance</Link>
            </PageHeader>
        </template>

        <p class="mb-4 text-sm capitalize text-slate-500">Status: {{ vendor.status }}</p>
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Total PO" :value="formatInr(summary.total_po_value)" tone="blue" />
            <StatCard label="Total Invoice" :value="formatInr(summary.total_invoice_value)" tone="beige" />
            <StatCard label="Total Paid" :value="formatInr(summary.total_paid)" tone="mint" />
            <StatCard label="Advance Paid" :value="formatInr(summary.advance_paid)" tone="mint" />
            <StatCard label="Available Advance" :value="formatInr(summary.available_advance)" tone="mint" />
            <StatCard label="Outstanding Payable" :value="formatInr(summary.outstanding_payable)" tone="pink" />
            <StatCard label="Refund Due" :value="formatInr(summary.refund_due)" tone="pink" />
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            <button
                v-for="name in tabs"
                :key="name"
                type="button"
                class="rounded-full px-3 py-1 text-sm capitalize"
                :class="[tab === name ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600', loadingTab === name ? 'opacity-60' : '']"
                :aria-busy="loadingTab === name ? 'true' : undefined"
                @click="openTab(name)"
            >
                {{ name.replace('-', ' ') }}
            </button>
        </div>

        <TableToolbar v-if="listTabs.includes(tab)" :search="filters.search" :only="tabOnly" placeholder="Search records in this tab…" />

        <section v-if="tab === 'overview'" class="ui-card space-y-2 p-5 text-sm">
            <p>{{ vendor.address || 'No address' }}</p>
            <p>{{ vendor.phone || '—' }} · {{ vendor.email || '—' }}</p>
            <p>GST {{ vendor.gst_number || '—' }} · PAN {{ vendor.pan_number || '—' }}</p>
            <p>Advance adjusted {{ formatInr(summary.advance_adjusted) }} · Invoice payments {{ formatInr(summary.invoice_payments) }}</p>
            <p>Marked for refund {{ formatInr(summary.marked_for_refund) }} · Refund received {{ formatInr(summary.refund_received) }}</p>
            <div class="flex gap-3 pt-3">
                <button type="button" class="ui-btn-secondary" @click="router.patch(`/vendors/${vendor.id}/status`)">{{ vendor.status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                <TableActionIcon action="delete" @click="confirmDelete() && router.delete(`/vendors/${vendor.id}`)" />
            </div>
        </section>

        <section v-else-if="tabMissing" class="ui-card space-y-3 p-5" aria-busy="true">
            <div class="h-4 w-1/3 animate-pulse rounded bg-slate-100"></div>
            <div v-for="n in 5" :key="n" class="h-9 animate-pulse rounded bg-slate-50"></div>
            <p class="text-sm text-slate-500">
                Loading…
                <button type="button" class="ml-2 text-amber-700 hover:underline" @click="openTab(tab)">Retry</button>
            </p>
        </section>

        <section v-else-if="tab === 'purchase-orders'" class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>PO</th><th>Project</th><th>Date</th><th>Status</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    <tr v-if="purchaseOrders.data.length === 0"><td colspan="5" class="py-8 text-center text-slate-400">{{ emptyText }}</td></tr>
                    <tr v-for="order in purchaseOrders.data" :key="order.id">
                        <td><Link :href="`/projects/${order.project_id}/purchase-orders/${order.id}`" class="hover:underline">{{ order.po_number }}</Link></td>
                        <td>{{ order.project_name }}</td>
                        <td>{{ order.po_date }}</td>
                        <td class="capitalize">{{ order.status }}</td>
                        <td class="text-right">{{ formatInr(order.grand_total) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-else-if="tab === 'invoices'" class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Invoice</th><th>Project</th><th>PO</th><th class="text-right">Total</th><th class="text-right">Outstanding</th></tr></thead>
                <tbody>
                    <tr v-if="invoices.data.length === 0"><td colspan="5" class="py-8 text-center text-slate-400">{{ emptyText }}</td></tr>
                    <tr v-for="invoice in invoices.data" :key="invoice.id">
                        <td><Link :href="`/projects/${invoice.project_id}/purchase-invoices/${invoice.id}`" class="hover:underline">{{ invoice.invoice_number }}</Link></td>
                        <td>{{ invoice.project_name }}</td>
                        <td>{{ invoice.po_number }}</td>
                        <td class="text-right">{{ formatInr(invoice.total_amount) }}</td>
                        <td class="text-right">{{ formatInr(invoice.outstanding) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-else-if="tab === 'payments' || tab === 'advances'" class="space-y-3">
            <Link :href="`/vendors/${vendor.id}/payments/create?payment_type=${tab === 'advances' ? 'advance' : 'invoice_payment'}`" class="ui-btn-secondary">Record {{ tab === 'advances' ? 'Advance' : 'Payment' }}</Link>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Mode</th><th class="text-right">Amount</th><th>Reference</th></tr></thead>
                    <tbody>
                        <tr v-if="(tab === 'advances' ? advances : payments).data.length === 0"><td colspan="5" class="py-8 text-center text-slate-400">{{ emptyText }}</td></tr>
                        <tr v-for="payment in (tab === 'advances' ? advances : payments).data" :key="payment.id">
                            <td>{{ payment.payment_date }}</td>
                            <td>{{ payment.payment_type }}</td>
                            <td>{{ payment.payment_mode }}</td>
                            <td class="text-right">{{ formatInr(payment.amount) }}</td>
                            <td>{{ payment.reference_number || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-else-if="tab === 'refunds'" class="grid gap-4 lg:grid-cols-2">
            <form class="ui-card space-y-3 p-5" @submit.prevent="markRefund">
                <h2 class="font-semibold">Mark for Refund</h2>
                <select v-model="refund.project_id" class="ui-input"><option value="">Project optional</option><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.project_name }}</option></select>
                <input v-model="refund.amount_due" type="number" min="0" step="0.01" required placeholder="Amount" class="ui-input">
                <textarea v-model="refund.remarks" rows="2" class="ui-textarea" placeholder="Remarks"></textarea>
                <p v-if="refund.errors.amount_due" class="text-sm text-red-700">{{ refund.errors.amount_due }}</p>
                <button class="ui-btn-primary">Mark for Refund</button>
            </form>
            <form class="ui-card space-y-3 p-5" @submit.prevent="recordRefund">
                <h2 class="font-semibold">Record Refund Received</h2>
                <select v-model="received.recovery_id" required class="ui-input">
                    <option value="">Select refund due</option>
                    <option v-for="recovery in refunds.recoveries" :key="recovery.id" :value="recovery.id">{{ formatInr(recovery.amount_due) }} due · received {{ formatInr(recovery.amount_received) }}</option>
                </select>
                <input v-model="received.refund_date" type="date" required class="ui-input">
                <input v-model="received.amount" type="number" min="0" step="0.01" required class="ui-input" placeholder="Amount received">
                <select v-model="received.refund_mode" class="ui-input">
                    <option value="cash">Cash</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cheque">Cheque</option>
                    <option value="upi">UPI</option>
                    <option value="other">Other</option>
                </select>
                <input v-model="received.reference_number" class="ui-input" placeholder="Reference">
                <p v-if="received.errors.amount" class="text-sm text-red-700">{{ received.errors.amount }}</p>
                <button class="ui-btn-primary">Record Refund Received</button>
            </form>
        </section>

        <section v-else class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Date</th><th>Type</th><th>Project</th><th>Reference</th><th class="text-right">Debit/Payment</th><th class="text-right">Credit/Refund</th><th>Remarks</th></tr></thead>
                <tbody>
                    <tr v-if="transactions.data.length === 0"><td colspan="7" class="py-8 text-center text-slate-400">{{ emptyText }}</td></tr>
                    <tr v-for="(row, index) in transactions.data" :key="`${transactions.current_page}-${index}`">
                        <td>{{ row.date }}</td>
                        <td>{{ row.type }}</td>
                        <td>{{ row.project || '—' }}</td>
                        <td>{{ row.reference || '—' }}</td>
                        <td class="text-right">{{ row.payment ? formatInr(row.payment) : '—' }}</td>
                        <td class="text-right">{{ row.refund ? formatInr(row.refund) : '—' }}</td>
                        <td>{{ row.remarks || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <TablePagination v-if="activeList" :paginator="activeList" :only="tabOnly" label="records" />
    </AdminLayout>
</template>
