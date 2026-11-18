<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ActionMenu from '../../Components/ActionMenu.vue';
import BudgetEditor from '../../Components/BudgetEditor.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr, formatQty, lineAmount } from '../../lib/money';
import { Trash2 } from '@lucide/vue';

const props = defineProps({
    project: {
        type: Object,
        required: true,
    },
    boq: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}).flatMap((error) => (
    Array.isArray(error) ? error : [error]
)));

let keySequence = 1;

function nextKey() {
    keySequence += 1;
    return `new-${keySequence}`;
}

function rowsFromBoq(boq) {
    const idToKey = {};
    boq.items.forEach((item) => {
        idToKey[item.id] = `id-${item.id}`;
    });

    return boq.items.map((item) => ({
        id: item.id,
        client_key: idToKey[item.id],
        parent_key: item.parent_id ? idToKey[item.parent_id] : null,
        item_number: item.item_number ?? '',
        service_code: item.service_code ?? '',
        is_section: item.is_section === true,
        description: item.description,
        unit: item.unit ?? '',
        quantity: item.quantity ?? '',
        rate: item.rate ?? '',
        procurable: item.procurable === true,
        quantity_usage: item.quantity_usage ?? null,
        budget_total: item.budget_total ?? '0.00',
    }));
}

const title = ref(props.boq.title);
const gstPercentage = ref(props.boq.gst_percentage);
const items = ref(rowsFromBoq(props.boq));
const budgetItem = ref(null);

function isParent(row) {
    return items.value.some((other) => other.parent_key === row.client_key);
}

function nextTopNumber() {
    const numbers = items.value
        .filter((row) => !row.parent_key)
        .map((row) => Number.parseInt(String(row.item_number).split('.')[0], 10))
        .filter((value) => Number.isFinite(value));

    return numbers.length ? Math.max(...numbers) + 1 : 1;
}

function nextChildNumber(parent) {
    const siblings = items.value.filter((row) => row.parent_key === parent.client_key);
    const suffixes = siblings
        .map((row) => {
            const parts = String(row.item_number).split('.');
            return Number.parseInt(parts[parts.length - 1], 10);
        })
        .filter((value) => Number.isFinite(value));

    const next = suffixes.length ? Math.max(...suffixes) + 1 : 1;

    return `${parent.item_number}.${next}`;
}

function familyEnd(parent) {
    let last = items.value.findIndex((row) => row.client_key === parent.client_key);

    items.value.forEach((row, index) => {
        if (row.parent_key === parent.client_key) {
            last = Math.max(last, index);
        }
    });

    return last;
}

function pushBlankItem() {
    items.value.push({
        id: null,
        client_key: nextKey(),
        parent_key: null,
        item_number: String(nextTopNumber()),
        service_code: '',
        is_section: false,
        description: '',
        unit: '',
        quantity: '',
        rate: '',
        budget_total: '0.00',
    });
}

function addItem() {
    if (!props.boq.editable) {
        if (props.boq.is_current && props.boq.revision_status === 'finalized') {
            router.post(`/projects/${props.project.id}/boqs/${props.boq.id}/revisions`, {
                revision_reason: 'Add BOQ items',
                add_item: 1,
            });
        }

        return;
    }

    pushBlankItem();
}

const canAddItem = computed(() => (
    props.boq.editable
    || (props.boq.is_current && props.boq.revision_status === 'finalized')
));

const needsFinalizeForProcurement = computed(() => (
    props.boq.editable && props.boq.revision_status === 'draft' && !props.boq.is_current
));

onMounted(() => {
    if (!props.boq.editable) {
        return;
    }

    const params = new URLSearchParams(window.location.search);

    if (params.get('add_item') !== '1') {
        return;
    }

    pushBlankItem();
    params.delete('add_item');
    const query = params.toString();
    const nextUrl = `${window.location.pathname}${query ? `?${query}` : ''}`;
    window.history.replaceState(window.history.state, '', nextUrl);
});

function addSubItem(parent) {
    const row = {
        id: null,
        client_key: nextKey(),
        parent_key: parent.client_key,
        item_number: nextChildNumber(parent),
        service_code: '',
        is_section: false,
        description: '',
        unit: '',
        quantity: '',
        rate: '',
        budget_total: '0.00',
    };

    items.value.splice(familyEnd(parent) + 1, 0, row);
}

function removeItem(row) {
    const children = items.value.filter((item) => item.parent_key === row.client_key);
    const message = children.length
        ? `Delete item ${row.item_number} and its ${children.length} sub-item(s)?`
        : `Delete item ${row.item_number || 'this row'}?`;

    if (!confirm(message)) {
        return;
    }

    const removeKeys = new Set([row.client_key, ...children.map((child) => child.client_key)]);
    items.value = items.value.filter((item) => !removeKeys.has(item.client_key));
}

function payloadItems() {
    return items.value
        .filter((row) => row.id || row.description.trim() !== '')
        .map((row) => ({
            id: row.id,
            client_key: row.client_key,
            parent_key: row.parent_key,
            item_number: row.item_number,
            service_code: row.service_code,
            is_section: row.is_section === true,
            description: row.description,
            unit: row.unit,
            quantity: row.quantity === '' ? null : row.quantity,
            rate: row.rate === '' ? null : row.rate,
        }));
}

function save({ finalize = false } = {}) {
    if (!props.boq.editable) {
        return;
    }

    router.put(`/projects/${props.project.id}/boqs/${props.boq.id}`, {
        revision_id: props.boq.revision_id,
        finalize: finalize ? 1 : 0,
        title: title.value,
        gst_percentage: gstPercentage.value,
        items: payloadItems(),
    }, {
        preserveState: (page) => Object.keys(page.props.errors ?? {}).length > 0,
    });
}

function openRevision(event) {
    router.get(`/projects/${props.project.id}/boqs/${props.boq.id}`, {
        revision: event.target.value,
    });
}

function createRevision() {
    router.post(`/projects/${props.project.id}/boqs/${props.boq.id}/revisions`, {
        revision_reason: '',
    });
}

function finalizeRevision() {
    router.post(`/projects/${props.project.id}/boqs/${props.boq.id}/revisions/${props.boq.revision_id}/finalize`);
}

function openBudget(row) {
    if (!row.id) {
        alert('Save the BOQ before setting a budget on a new item.');
        return;
    }

    const source = props.boq.items.find((item) => item.id === row.id);
    budgetItem.value = {
        ...row,
        budgets: source?.budgets ?? [],
    };
}

function applyBudgetTotals(visit) {
    const freshItems = visit?.props?.boq?.items ?? page.props.boq?.items ?? [];

    items.value = items.value.map((row) => {
        const fresh = freshItems.find((item) => item.id === row.id);

        return fresh ? { ...row, budget_total: fresh.budget_total } : row;
    });
}

function onBudgetSaved(visit) {
    applyBudgetTotals(visit);
    budgetItem.value = null;
}

const subtotal = computed(() => items.value.reduce((sum, row) => {
    if (isParent(row) || row.is_section) {
        return sum;
    }

    return sum + (lineAmount(row.quantity, row.rate) ?? 0);
}, 0));

const gstAmount = computed(() => {
    const percent = Number(gstPercentage.value);

    if (!Number.isFinite(percent)) {
        return 0;
    }

    return Math.round((subtotal.value * percent / 100 + Number.EPSILON) * 100) / 100;
});

const grandTotal = computed(() => subtotal.value + gstAmount.value);

const budgetTotal = computed(() => items.value.reduce((sum, row) => sum + (Number(row.budget_total) || 0), 0));
</script>

<template>
    <Head :title="boq.title" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="boq.boq_number || 'BOQ'" :eyebrow="project.project_name">
                <template v-if="boq.editable">
                    <button
                        v-if="needsFinalizeForProcurement"
                        type="button"
                        class="ui-btn-primary"
                        @click="save({ finalize: true })"
                    >Save &amp; Finalize</button>
                    <button
                        type="button"
                        :class="needsFinalizeForProcurement ? 'ui-btn-secondary' : 'ui-btn-primary'"
                        @click="save()"
                    >Save BOQ</button>
                </template>
            </PageHeader>
        </template>

        <div v-if="errors.length" class="mb-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p v-for="(error, index) in errors" :key="index">{{ error }}</p>
        </div>

        <div
            v-if="needsFinalizeForProcurement"
            class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            This draft is not current yet. Use <span class="font-medium">Save &amp; Finalize</span> so new items appear in Purchase Requests and procurement.
        </div>

        <div class="ui-card mb-4 flex flex-wrap items-end justify-between gap-4 p-5">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ boq.boq_number }} · Revision {{ boq.revision_number }}</p>
                <p class="mt-1 text-sm text-slate-600">Revision date {{ boq.revision_date || '—' }}</p>
                <p class="mt-2 text-sm font-medium" :class="boq.is_current ? 'text-emerald-700' : 'text-amber-800'">
                    {{ boq.is_current ? 'CURRENT' : 'HISTORICAL REVISION' }}
                    <span class="font-normal text-slate-500">· {{ boq.revision_status }}</span>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select class="ui-input w-auto" :value="boq.revision_id" @change="openRevision">
                    <option v-for="revision in boq.revisions" :key="revision.id" :value="revision.id">
                        Revision {{ revision.revision_number }}{{ revision.is_current ? ' · Current' : '' }}
                    </option>
                </select>
                <button v-if="boq.revision_status === 'draft'" type="button" class="ui-btn-primary" @click="finalizeRevision">Finalize</button>
                <button v-if="boq.is_current && boq.revision_status === 'finalized'" type="button" class="ui-btn-secondary" @click="createRevision">Create Revision</button>
            </div>
        </div>

        <div class="ui-card mb-4 grid gap-4 p-5 md:grid-cols-[1fr_12rem_auto] md:items-end">
            <FormField label="BOQ title">
                <input v-model="title" type="text" class="ui-input" :disabled="!boq.editable">
            </FormField>
            <FormField label="GST %">
                <input v-model="gstPercentage" type="number" min="0" max="100" step="0.01" class="ui-input" :disabled="!boq.editable">
            </FormField>
            <div v-if="canAddItem || boq.editable" class="flex flex-wrap gap-2">
                <a v-if="boq.editable" :href="`/projects/${project.id}/boqs/${boq.id}/template`" class="ui-btn-secondary">Download Template</a>
                <Link v-if="boq.editable" :href="`/projects/${project.id}/boqs/${boq.id}/import`" class="ui-btn-secondary">Import Excel</Link>
                <button v-if="canAddItem" type="button" class="ui-btn-secondary" @click="addItem">+ Add Item</button>
            </div>
        </div>

        <div class="ui-table-wrap">
            <table class="ui-table min-w-[1100px]">
                <thead class="sticky top-0">
                    <tr>
                        <th class="w-24">Sr. No.</th>
                        <th class="min-w-[8rem]">Service Code</th>
                        <th class="min-w-[20rem]">Item Description</th>
                        <th class="w-28">Unit</th>
                        <th class="text-right">B.O.Q.</th>
                        <th class="text-right">Rate</th>
                        <th class="min-w-[10rem] text-right">BOQ Amount</th>
                        <th class="min-w-[9rem] text-right">Budget</th>
                        <th class="w-14">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="items.length === 0">
                        <td colspan="9" class="py-10 text-center text-slate-400">Add the first BOQ item.</td>
                    </tr>
                    <tr v-for="row in items" :key="row.client_key" :class="row.is_section ? 'bg-amber-50/70 font-medium' : (isParent(row) ? 'bg-slate-50/80 font-medium' : '')">
                        <td>
                            <input v-model="row.item_number" type="text" class="cell-input" :disabled="!boq.editable">
                        </td>
                        <td>
                            <input v-model="row.service_code" type="text" class="cell-input min-w-[7rem]" :disabled="!boq.editable">
                        </td>
                        <td>
                            <textarea
                                v-model="row.description"
                                rows="2"
                                class="cell-input"
                                :class="row.parent_key ? 'ml-6 w-[calc(100%-1.5rem)]' : ''"
                                :disabled="!boq.editable"
                            ></textarea>
                            <label class="mt-1 flex items-center gap-2 text-xs text-slate-500">
                                <input v-model="row.is_section" type="checkbox" :disabled="!boq.editable">
                                Section header
                            </label>
                        </td>
                        <td><input v-model="row.unit" type="text" class="cell-input unit-input" :disabled="!boq.editable"></td>
                        <td><input v-model="row.quantity" type="number" min="0" step="0.001" class="cell-input num-input" :disabled="!boq.editable"></td>
                        <td><input v-model="row.rate" type="number" min="0" step="0.01" class="cell-input num-input" :disabled="!boq.editable"></td>
                        <td class="num-cell font-medium">
                            {{ row.is_section || lineAmount(row.quantity, row.rate) === null ? '—' : formatInr(lineAmount(row.quantity, row.rate)) }}
                        </td>
                        <td class="num-cell">
                            <button type="button" class="font-medium text-slate-800 hover:underline" @click="openBudget(row)">
                                {{ formatInr(row.budget_total) }}
                            </button>
                        </td>
                        <td class="text-right">
                            <p
                                v-if="row.quantity_usage"
                                class="mb-1 text-[11px] leading-tight"
                                :class="row.quantity_usage.exceeded ? 'font-medium text-orange-700' : 'text-slate-500'"
                                :title="`Recorded on posted GRNs: ${formatQty(row.quantity_usage.received_quantity || row.quantity_usage.used_quantity, row.quantity_usage.unit)}`"
                            >
                                {{ formatQty(row.quantity_usage.received_quantity || row.quantity_usage.used_quantity) }} / {{ formatQty(row.quantity_usage.boq_quantity) }}
                                <span v-if="row.quantity_usage.exceeded" class="block">Exceeded</span>
                            </p>
                            <ActionMenu>
                                <button type="button" class="menu-item" @click="openBudget(row)">Set Budget</button>
                                <Link
                                    v-if="row.id && row.procurable"
                                    :href="`/projects/${project.id}/boqs/${boq.id}/items/${row.id}/procurement`"
                                    class="menu-item"
                                >Procurement</Link>
                                <button v-if="boq.editable && !row.parent_key" type="button" class="menu-item" @click="addSubItem(row)">Add Sub Item</button>
                                <button v-if="boq.editable" type="button" class="menu-item-danger" @click="removeItem(row)"><Trash2 class="h-4 w-4" /> Delete</button>
                            </ActionMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex justify-end">
            <div class="ui-card w-full max-w-sm space-y-2 p-5 text-sm">
                <div class="flex justify-between text-slate-500"><span>Subtotal</span><span class="tabular-nums text-slate-800">{{ formatInr(subtotal) }}</span></div>
                <div class="flex justify-between text-slate-500"><span>GST @ {{ gstPercentage || 0 }}%</span><span class="tabular-nums text-slate-800">{{ formatInr(gstAmount) }}</span></div>
                <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-semibold text-slate-900">
                    <span>BOQ Total</span><span class="tabular-nums">{{ formatInr(grandTotal) }}</span>
                </div>
                <div class="flex justify-between text-slate-500"><span>Budget Total</span><span class="tabular-nums text-slate-800">{{ formatInr(budgetTotal) }}</span></div>
            </div>
        </div>

        <div v-if="budgetItem" class="fixed inset-0 z-40 flex justify-end bg-slate-900/20">
            <div class="flex h-full w-full max-w-md flex-col bg-white shadow-xl">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="text-lg font-semibold text-slate-900">Set Budget</h2>
                    <p class="mt-1 text-sm text-slate-500">BOQ Item {{ budgetItem.item_number }}</p>
                    <p class="text-sm text-slate-700">{{ budgetItem.description }}</p>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <BudgetEditor
                        :project-id="project.id"
                        :boq-id="boq.id"
                        :item-id="budgetItem.id"
                        :initial-entries="budgetItem.budgets"
                        preserve-state
                        show-cancel
                        @saved="onBudgetSaved"
                        @synced="applyBudgetTotals"
                        @cancel="budgetItem = null"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
