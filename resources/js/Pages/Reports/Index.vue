<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    catalog: { type: Array, default: () => [] },
    vendors: { type: Array, required: true },
    projects: { type: Array, required: true },
});

const catalogGroups = computed(() => {
    const groups = new Map();
    for (const entry of props.catalog) {
        const list = groups.get(entry.group) ?? [];
        list.push(entry);
        groups.set(entry.group, list);
    }

    return [...groups.entries()];
});

const primaryProject = computed(() => props.projects[0] ?? null);

function projectPath(suffix) {
    const project = primaryProject.value;
    if (!project) {
        return null;
    }

    return `/projects/${project.id}${suffix}`;
}
</script>

<template>
    <Head title="Reports" />
    <AdminLayout>
        <template #header><PageHeader title="Reports" eyebrow="Analytics" /></template>

        <div class="space-y-8">
            <section v-if="catalog.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Analytical reports</h2>
                <div v-for="[group, entries] in catalogGroups" :key="group" class="mb-6">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ group }}</h3>
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="entry in entries"
                            :key="entry.id"
                            :href="entry.route"
                            class="ui-card block p-5 hover:border-slate-300"
                        >
                            <h3 class="font-semibold">{{ entry.title }}</h3>
                            <p class="mt-1 text-sm text-slate-500">Filtered tables with Excel and PDF export.</p>
                        </Link>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Projects &amp; BOQ</h2>
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Link href="/projects" class="ui-card block p-5 hover:border-slate-300">
                        <h3 class="font-semibold">Projects</h3>
                        <p class="mt-1 text-sm text-slate-500">Project register and detail pages.</p>
                    </Link>
                    <Link
                        v-if="projectPath('/boqs')"
                        :href="projectPath('/boqs')"
                        class="ui-card block p-5 hover:border-slate-300"
                    >
                        <h3 class="font-semibold">BOQ lists</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            Bill of quantities for {{ primaryProject.project_code }}.
                        </p>
                    </Link>
                    <div v-else class="ui-card p-5">
                        <h3 class="font-semibold">BOQ lists</h3>
                        <p class="mt-1 text-sm text-slate-500">Create a project to open BOQ registers.</p>
                        <Link href="/projects" class="mt-3 inline-block text-sm font-medium hover:underline">Go to projects</Link>
                    </div>
                </div>
                <div v-if="projects.length > 1" class="mt-3 flex flex-wrap gap-2">
                    <span class="text-xs text-slate-500">Other projects — BOQ:</span>
                    <Link
                        v-for="project in projects.slice(1, 12)"
                        :key="project.id"
                        :href="`/projects/${project.id}/boqs`"
                        class="ui-btn-secondary text-xs"
                    >
                        {{ project.project_code }}
                    </Link>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Procurement</h2>
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <template v-if="primaryProject">
                        <Link :href="projectPath('/purchase-requests')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Purchase requests</h3>
                            <p class="mt-1 text-sm text-slate-500">PR list for {{ primaryProject.project_code }}.</p>
                        </Link>
                        <Link :href="projectPath('/purchase-orders')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Purchase orders</h3>
                            <p class="mt-1 text-sm text-slate-500">PO register for {{ primaryProject.project_code }}.</p>
                        </Link>
                        <Link :href="projectPath('/work-orders')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Work orders</h3>
                            <p class="mt-1 text-sm text-slate-500">WO list for {{ primaryProject.project_code }}.</p>
                        </Link>
                    </template>
                    <div v-else class="ui-card p-5 md:col-span-2 lg:col-span-3">
                        <p class="text-sm text-slate-500">Procurement lists are project-scoped. Add a project to open PR, PO, and WO registers.</p>
                    </div>
                </div>
                <div v-if="projects.length > 1" class="mt-3 flex flex-wrap gap-2">
                    <span class="text-xs text-slate-500">Quick open — PR:</span>
                    <Link
                        v-for="project in projects.slice(0, 12)"
                        :key="'pr-' + project.id"
                        :href="`/projects/${project.id}/purchase-requests`"
                        class="ui-btn-secondary text-xs"
                    >
                        {{ project.project_code }}
                    </Link>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Store, GRN &amp; inventory</h2>
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <template v-if="primaryProject">
                        <Link :href="projectPath('/store')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Store receipts</h3>
                            <p class="mt-1 text-sm text-slate-500">Material receipt notes at site store.</p>
                        </Link>
                        <Link :href="projectPath('/grns')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">GRN register</h3>
                            <p class="mt-1 text-sm text-slate-500">Goods receipt notes against POs.</p>
                        </Link>
                        <Link :href="projectPath('/inventory')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Inventory dashboard</h3>
                            <p class="mt-1 text-sm text-slate-500">Stock overview and movement summary.</p>
                        </Link>
                        <Link :href="projectPath('/inventory/stock')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Stock on hand</h3>
                            <p class="mt-1 text-sm text-slate-500">Current quantities by material.</p>
                        </Link>
                        <Link :href="projectPath('/inventory/ledger')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Stock ledger</h3>
                            <p class="mt-1 text-sm text-slate-500">Chronological inventory movements.</p>
                        </Link>
                    </template>
                    <div v-else class="ui-card p-5 md:col-span-2 lg:col-span-3">
                        <p class="text-sm text-slate-500">Inventory reports open from a project context.</p>
                    </div>
                </div>
                <div v-if="projects.length" class="mt-3 flex flex-wrap gap-2">
                    <span class="text-xs text-slate-500">Open stock ledger by project:</span>
                    <Link
                        v-for="project in projects.slice(0, 12)"
                        :key="'inv-' + project.id"
                        :href="`/projects/${project.id}/inventory/ledger`"
                        class="ui-btn-secondary text-xs"
                    >
                        {{ project.project_code }}
                    </Link>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Purchase invoices</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <Link
                        v-if="projectPath('/purchase-invoices')"
                        :href="projectPath('/purchase-invoices')"
                        class="ui-card block p-5 hover:border-slate-300"
                    >
                        <h3 class="font-semibold">Purchase invoice list</h3>
                        <p class="mt-1 text-sm text-slate-500">Vendor invoices recorded against {{ primaryProject.project_code }}.</p>
                    </Link>
                    <div v-else class="ui-card p-5">
                        <h3 class="font-semibold">Purchase invoice list</h3>
                        <p class="mt-1 text-sm text-slate-500">Project-scoped invoice register.</p>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Vendors</h2>
                <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <Link href="/vendors/report" class="ui-card block p-5 hover:border-slate-300">
                        <h3 class="font-semibold">Vendor balances</h3>
                        <p class="mt-1 text-sm text-slate-500">Outstanding payable, advances, and refunds by vendor.</p>
                    </Link>
                    <Link href="/vendors/reconciliation" class="ui-card block p-5 hover:border-slate-300">
                        <h3 class="font-semibold">Vendor reconciliation</h3>
                        <p class="mt-1 text-sm text-slate-500">Compare ledger payable basis to balance outstanding.</p>
                    </Link>
                    <Link href="/vendors" class="ui-card block p-5 hover:border-slate-300">
                        <h3 class="font-semibold">Vendor directory</h3>
                        <p class="mt-1 text-sm text-slate-500">Master list with balances and payments.</p>
                    </Link>
                    <div class="ui-card p-5">
                        <h3 class="font-semibold">Vendor ledger</h3>
                        <p class="mt-1 mb-3 text-sm text-slate-500">Chronological payable statement for a vendor.</p>
                        <ul class="space-y-2 text-sm">
                            <li v-for="vendor in vendors.slice(0, 8)" :key="vendor.id">
                                <Link :href="`/vendors/${vendor.id}/ledger`" class="hover:underline">{{ vendor.name }}</Link>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Debit &amp; credit notes</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <template v-if="primaryProject">
                        <Link :href="projectPath('/debit-notes')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Debit notes</h3>
                            <p class="mt-1 text-sm text-slate-500">Vendor debit note register.</p>
                        </Link>
                        <Link :href="projectPath('/credit-notes')" class="ui-card block p-5 hover:border-slate-300">
                            <h3 class="font-semibold">Credit notes</h3>
                            <p class="mt-1 text-sm text-slate-500">Vendor credit note register.</p>
                        </Link>
                    </template>
                    <div v-else class="ui-card p-5 md:col-span-2">
                        <p class="text-sm text-slate-500">Debit and credit note lists are available per project.</p>
                    </div>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Expenses</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <Link href="/expenses" class="ui-card block p-5 hover:border-slate-300">
                        <h3 class="font-semibold">All expenses</h3>
                        <p class="mt-1 text-sm text-slate-500">Cross-project expense claims and status.</p>
                    </Link>
                    <Link
                        v-if="projectPath('/expenses')"
                        :href="projectPath('/expenses')"
                        class="ui-card block p-5 hover:border-slate-300"
                    >
                        <h3 class="font-semibold">Project expenses</h3>
                        <p class="mt-1 text-sm text-slate-500">Expense list for {{ primaryProject.project_code }}.</p>
                    </Link>
                </div>
            </section>

            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Administration</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <Link href="/audit-log" class="ui-card block p-5 hover:border-slate-300">
                        <h3 class="font-semibold">Audit log</h3>
                        <p class="mt-1 text-sm text-slate-500">User activity, document changes, and filters.</p>
                    </Link>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
