<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Building2,
    ListChecks,
    ClipboardList,
    FileSpreadsheet,
    FileText,
    FolderKanban,
    LayoutDashboard,
    Package,
    Receipt,
    Settings,
    ShoppingCart,
    Hammer,
    Users,
    Warehouse,
    X,
} from '@lucide/vue';

const props = defineProps({
    project: { type: Object, default: null },
    company: { type: Object, default: null },
    user: { type: Object, default: null },
    path: { type: String, required: true },
    open: { type: Boolean, default: false },
});

const logoBroken = ref(false);

watch(() => props.company?.logo_url, () => {
    logoBroken.value = false;
});

const initials = computed(() => {
    const name = props.company?.company_name || 'BOQ';
    const letters = name.split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]);

    return letters.join('').toUpperCase() || 'B';
});

const emit = defineEmits(['close']);

const page = usePage();
const approvals = computed(() => page.props.approvals ?? null);

function can(permission) {
    return props.user?.is_admin || (props.user?.permissions || []).includes(permission);
}

function itemClass(active) {
    return [
        'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition',
        active ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
    ];
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-30 bg-slate-900/20 lg:hidden" @click="emit('close')"></div>
    <aside
        class="fixed bottom-3 left-3 top-3 z-40 w-60 flex-col rounded-[24px] border border-slate-200/80 bg-white print:hidden"
        :class="open ? 'flex' : 'hidden lg:flex'"
    >
        <div class="flex items-start justify-between gap-3 px-5 pb-2 pt-5">
            <div class="flex min-w-0 items-center gap-3">
                <img
                    v-if="company?.logo_url && !logoBroken"
                    :src="company.logo_url"
                    alt=""
                    class="h-10 max-h-11 w-auto max-w-[140px] shrink-0 object-contain"
                    @error="logoBroken = true"
                >
                <span v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-xs font-semibold text-slate-600">{{ initials }}</span>
                <p class="truncate text-sm font-semibold text-slate-900">{{ company?.company_name || 'HSB Projects' }}</p>
            </div>
            <button type="button" class="rounded-lg p-1 text-slate-400 hover:bg-slate-50 lg:hidden" @click="emit('close')">
                <X class="h-4 w-4" />
            </button>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
            <Link href="/" :class="itemClass(path === '/')" @click="emit('close')">
                <LayoutDashboard class="h-4 w-4 shrink-0" />
                Dashboard
            </Link>
            <Link v-if="approvals?.can_approve" href="/approvals" :class="itemClass(path === '/approvals')" @click="emit('close')">
                <BadgeCheck class="h-4 w-4 shrink-0" />
                <span class="flex-1">Approvals</span>
                <span
                    v-if="approvals.pending > 0"
                    class="min-w-[1.5rem] rounded-full bg-amber-500 px-1.5 py-0.5 text-center text-xs font-semibold text-white"
                >{{ approvals.pending > 99 ? '99+' : approvals.pending }}</span>
            </Link>
            <Link href="/projects" :class="itemClass(path.startsWith('/projects') && !project)" @click="emit('close')">
                <FolderKanban class="h-4 w-4 shrink-0" />
                Projects
            </Link>
            <Link v-if="can('vendors.view')" href="/vendors" :class="itemClass(path.startsWith('/vendors'))" @click="emit('close')">
                <Building2 class="h-4 w-4 shrink-0" />
                Vendors
            </Link>
            <Link v-if="can('expenses.view')" href="/expenses" :class="itemClass(path.startsWith('/expenses') || path.startsWith('/expense-categories'))" @click="emit('close')">
                <FileText class="h-4 w-4 shrink-0" />
                Expenses
            </Link>
            <Link v-if="can('reports.view')" href="/reports" :class="itemClass(path.startsWith('/reports'))" @click="emit('close')">
                <FileSpreadsheet class="h-4 w-4 shrink-0" />
                Reports
            </Link>

            <div v-if="project" class="pt-5">
                <p class="px-3 pb-2 text-xs font-medium text-slate-400">{{ project.project_code }}</p>
                <div class="space-y-1">
                    <Link :href="`/projects/${project.id}`" :class="itemClass(path === `/projects/${project.id}`)" @click="emit('close')">
                        <LayoutDashboard class="h-4 w-4 shrink-0" />
                        Project Dashboard
                    </Link>
                    <Link v-if="can('boq.view')" :href="`/projects/${project.id}/boqs`" :class="itemClass(path.startsWith(`/projects/${project.id}/boqs`))" @click="emit('close')">
                        <FileSpreadsheet class="h-4 w-4 shrink-0" />
                        BOQ
                    </Link>
                    <Link v-if="can('purchase_requests.view')" :href="`/projects/${project.id}/purchase-requests`" :class="itemClass(path.startsWith(`/projects/${project.id}/purchase-requests`))" @click="emit('close')">
                        <ClipboardList class="h-4 w-4 shrink-0" />
                        Purchase Requests
                    </Link>
                    <Link v-if="can('purchase_orders.view')" :href="`/projects/${project.id}/purchase-orders`" :class="itemClass(path.startsWith(`/projects/${project.id}/purchase-orders`))" @click="emit('close')">
                        <ShoppingCart class="h-4 w-4 shrink-0" />
                        Purchase Orders
                    </Link>
                    <Link v-if="can('work_orders.view')" :href="`/projects/${project.id}/work-orders`" :class="itemClass(path.startsWith(`/projects/${project.id}/work-orders`))" @click="emit('close')">
                        <Hammer class="h-4 w-4 shrink-0" />
                        Work Orders
                    </Link>
                    <Link v-if="can('store.view')" :href="`/projects/${project.id}/store`" :class="itemClass(path.startsWith(`/projects/${project.id}/store`))" @click="emit('close')">
                        <Package class="h-4 w-4 shrink-0" />
                        Store
                    </Link>
                    <Link v-if="can('invoices.view')" :href="`/projects/${project.id}/purchase-invoices`" :class="itemClass(path.startsWith(`/projects/${project.id}/purchase-invoices`))" @click="emit('close')">
                        <Receipt class="h-4 w-4 shrink-0" />
                        Purchase Invoices
                    </Link>
                    <Link v-if="can('debit_notes.view')" :href="`/projects/${project.id}/debit-notes`" :class="itemClass(path.startsWith(`/projects/${project.id}/debit-notes`))" @click="emit('close')">
                        <FileText class="h-4 w-4 shrink-0" />
                        Debit Notes
                    </Link>
                    <Link v-if="can('credit_notes.view')" :href="`/projects/${project.id}/credit-notes`" :class="itemClass(path.startsWith(`/projects/${project.id}/credit-notes`))" @click="emit('close')">
                        <FileText class="h-4 w-4 shrink-0" />
                        Credit Notes
                    </Link>
                    <Link v-if="can('expenses.view')" :href="`/projects/${project.id}/expenses`" :class="itemClass(path.startsWith(`/projects/${project.id}/expenses`))" @click="emit('close')">
                        <FileText class="h-4 w-4 shrink-0" />
                        Project Expenses
                    </Link>
                    <template v-if="can('inventory.view')">
                        <p class="px-3 pb-2 pt-4 text-xs font-medium text-slate-400">Inventory</p>
                        <Link :href="`/projects/${project.id}/inventory`" :class="itemClass(path === `/projects/${project.id}/inventory`)" @click="emit('close')">
                            <Warehouse class="h-4 w-4 shrink-0" />
                            Inventory Dashboard
                        </Link>
                        <Link :href="`/projects/${project.id}/inventory/materials`" :class="itemClass(path.startsWith(`/projects/${project.id}/inventory/materials`))" @click="emit('close')">
                            <Package class="h-4 w-4 shrink-0" />
                            Material Master
                        </Link>
                        <Link :href="`/projects/${project.id}/inventory/stock`" :class="itemClass(path.startsWith(`/projects/${project.id}/inventory/stock`))" @click="emit('close')">
                            <Package class="h-4 w-4 shrink-0" />
                            Stock Overview
                        </Link>
                        <Link :href="`/projects/${project.id}/inventory/ledger`" :class="itemClass(path.startsWith(`/projects/${project.id}/inventory/ledger`))" @click="emit('close')">
                            <ClipboardList class="h-4 w-4 shrink-0" />
                            Stock Ledger
                        </Link>
                        <Link :href="`/projects/${project.id}/inventory/issues`" :class="itemClass(path.startsWith(`/projects/${project.id}/inventory/issues`))" @click="emit('close')">
                            <ClipboardList class="h-4 w-4 shrink-0" />
                            Material Issues
                        </Link>
                    </template>
                    <Link v-if="can('grn.view')" :href="`/projects/${project.id}/grns`" :class="itemClass(path.startsWith(`/projects/${project.id}/grns`))" @click="emit('close')">
                        <Package class="h-4 w-4 shrink-0" />
                        Goods Receipt Notes
                    </Link>
                    <Link v-if="can('inventory.view')" :href="`/projects/${project.id}/inventory/adjustments`" :class="itemClass(path.startsWith(`/projects/${project.id}/inventory/adjustments`))" @click="emit('close')">
                        <ClipboardList class="h-4 w-4 shrink-0" />
                        Stock Adjustments
                    </Link>
                    <Link v-if="can('idn.view')" :href="`/projects/${project.id}/inventory/transfers`" :class="itemClass(path.includes('/inventory/transfers') || path.startsWith('/inventory/transfers'))" @click="emit('close')">
                        <ClipboardList class="h-4 w-4 shrink-0" />
                        Internal Delivery Notes
                    </Link>
                    <Link v-if="can('inventory.view')" href="/inventory/locations" :class="itemClass(path.startsWith('/inventory/locations'))" @click="emit('close')">
                        <Warehouse class="h-4 w-4 shrink-0" />
                        Locations
                    </Link>
                </div>
            </div>

            <div v-if="can('users.view') || can('roles.view') || can('settings.view') || approvals?.can_configure" class="space-y-1 pt-5">
                <p class="px-3 pb-2 text-xs font-medium text-slate-400">Admin</p>
                <Link v-if="can('users.view')" href="/users" :class="itemClass(path.startsWith('/users'))" @click="emit('close')">
                    <Users class="h-4 w-4 shrink-0" />
                    Users
                </Link>
                <Link v-if="can('roles.view')" href="/roles" :class="itemClass(path.startsWith('/roles'))" @click="emit('close')">
                    <Users class="h-4 w-4 shrink-0" />
                    Roles & Permissions
                </Link>
                <Link v-if="approvals?.can_configure" href="/approvals/settings" :class="itemClass(path.startsWith('/approvals/settings'))" @click="emit('close')">
                    <ListChecks class="h-4 w-4 shrink-0" />
                    Approval Settings
                </Link>
                <Link v-if="can('settings.view')" href="/settings/company" :class="itemClass(path.startsWith('/settings'))" @click="emit('close')">
                    <Settings class="h-4 w-4 shrink-0" />
                    Settings
                </Link>
            </div>
        </nav>
    </aside>
</template>
