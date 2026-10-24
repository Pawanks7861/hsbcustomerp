<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    module: { type: String, required: true },
    label: { type: String, required: true },
    instructions: { type: String, required: true },
    modes: { type: Array, required: true },
    defaultMode: { type: String, required: true },
    columns: { type: Array, required: true },
    project: { type: Object, default: null },
    returnUrl: { type: String, default: null },
    preview: { type: Object, default: null },
    result: { type: Object, default: null },
});

const form = useForm({
    file: null,
    duplicate_mode: props.defaultMode,
    project_id: props.project?.id ?? null,
    return: props.returnUrl,
});

const confirming = ref(false);

const modeLabels = {
    create_only: 'Create new only (fail on duplicates)',
    skip_duplicates: 'Skip existing duplicates',
    update_matched: 'Update matched records only',
    upsert: 'Create new and update matched',
    opening_stock: 'Add Opening Stock (do not add again if already recorded)',
    stock_adjustment: 'Stock Adjustment (controlled increase or decrease)',
    item_details_only: 'Update Item Details Only (no stock change)',
};

const templateQuery = computed(() => {
    const params = new URLSearchParams();
    if (props.project?.id) params.set('project_id', String(props.project.id));
    const query = params.toString();
    return query ? `?${query}` : '';
});

const csvTemplateQuery = computed(() => {
    const params = new URLSearchParams();
    params.set('format', 'csv');
    if (props.project?.id) params.set('project_id', String(props.project.id));
    return `?${params.toString()}`;
});

function rowDetails(row) {
    if (row.errors?.length) return row.errors.join(' ');
    const data = row.data || {};
    const parts = [];
    if (data.material_code || data.name) parts.push(data.material_code ? `${data.material_code} ${data.name || ''}`.trim() : data.name);
    if (data.location || data._location_name) parts.push(data._location_name || data.location);
    if (data.opening_quantity !== undefined && data.opening_quantity !== null && data.opening_quantity !== '') {
        parts.push(`qty ${data.opening_quantity}${data.unit ? ' ' + data.unit : ''}`);
    }
    return parts.length ? parts.join(' · ') : JSON.stringify(data);
}

const previewRows = computed(() => props.preview?.rows ?? []);
const counts = computed(() => props.preview?.counts ?? null);

function onFileChange(event) {
    form.file = event.target.files?.[0] ?? null;
}

function submitPreview() {
    form.post(`/imports/${props.module}/preview`, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function confirmImport() {
    if (!props.preview?.batch?.id || !props.preview?.can_confirm) {
        return;
    }
    confirming.value = true;
    router.post(`/imports/batches/${props.preview.batch.id}/confirm`, {
        return: props.returnUrl,
    }, {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`Import ${label}`" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="`Import ${label}`" eyebrow="Data Import">
                <Link v-if="returnUrl" :href="returnUrl" class="ui-btn-secondary">Back</Link>
                <Link href="/imports/history" class="ui-btn-secondary">Import History</Link>
                <a :href="`/imports/${module}/template${templateQuery}`" class="ui-btn-secondary">Download Excel Template</a>
                <a :href="`/imports/${module}/template${csvTemplateQuery}`" class="ui-btn-secondary">Download CSV Template</a>
            </PageHeader>
        </template>

        <div class="mx-auto max-w-5xl space-y-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-600">{{ instructions }}</p>
                <p v-if="project" class="mt-2 text-sm text-slate-500">
                    Project context: <span class="font-medium text-slate-800">{{ project.project_code }} — {{ project.project_name }}</span>
                </p>
                <div class="mt-4 overflow-x-auto">
                    <table class="ui-table text-sm">
                        <thead>
                            <tr>
                                <th>Column</th>
                                <th>Required</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="column in columns" :key="column.key">
                                <td class="font-medium">{{ column.heading }}</td>
                                <td>{{ column.required ? 'Yes' : 'No' }}</td>
                                <td class="text-slate-500">{{ column.help || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="result" class="rounded-2xl border p-5" :class="result.ok ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'">
                <p class="font-medium" :class="result.ok ? 'text-emerald-800' : 'text-rose-800'">{{ result.message }}</p>
                <p v-if="result.batch" class="mt-1 text-sm text-slate-600">
                    Batch {{ result.batch.batch_number }} —
                    created {{ result.batch.created_count }},
                    updated {{ result.batch.updated_count }},
                    skipped {{ result.batch.skipped_count }}
                </p>
                <Link v-if="returnUrl" :href="returnUrl" class="ui-btn-primary mt-4 inline-flex">Return to list</Link>
            </div>

            <form class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4" @submit.prevent="submitPreview">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Duplicate handling</label>
                    <select v-model="form.duplicate_mode" class="ui-input w-full max-w-md">
                        <option v-for="mode in modes" :key="mode" :value="mode">{{ modeLabels[mode] || mode }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">Upload .xlsx or .csv</label>
                    <input type="file" accept=".xlsx,.xls,.csv,text/csv" class="block w-full text-sm" @change="onFileChange">
                    <p v-if="form.errors.file" class="mt-1 text-sm text-rose-600">{{ form.errors.file }}</p>
                </div>
                <button type="submit" class="ui-btn-primary" :disabled="form.processing || !form.file">
                    {{ form.processing ? 'Validating…' : 'Validate & Preview' }}
                </button>
            </form>

            <div v-if="preview" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Preview</h2>
                        <p class="text-sm text-slate-500">{{ preview.message || 'Review the rows below before confirming.' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a
                            v-if="preview.batch?.id && (preview.counts?.invalid || 0) > 0"
                            :href="`/imports/batches/${preview.batch.id}/errors`"
                            class="ui-btn-secondary"
                        >Download errors</a>
                        <button
                            type="button"
                            class="ui-btn-primary"
                            :disabled="!preview.can_confirm || confirming"
                            @click="confirmImport"
                        >
                            {{ confirming ? 'Importing…' : 'Confirm Import' }}
                        </button>
                    </div>
                </div>

                <div v-if="counts" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div class="rounded-xl bg-slate-50 p-3 text-center">
                        <p class="text-xs uppercase text-slate-500">Total</p>
                        <p class="text-xl font-semibold">{{ counts.total }}</p>
                    </div>
                    <div class="rounded-xl bg-emerald-50 p-3 text-center">
                        <p class="text-xs uppercase text-emerald-700">Valid</p>
                        <p class="text-xl font-semibold text-emerald-800">{{ counts.valid }}</p>
                    </div>
                    <div class="rounded-xl bg-rose-50 p-3 text-center">
                        <p class="text-xs uppercase text-rose-700">Invalid</p>
                        <p class="text-xl font-semibold text-rose-800">{{ counts.invalid }}</p>
                    </div>
                    <div class="rounded-xl bg-amber-50 p-3 text-center">
                        <p class="text-xs uppercase text-amber-700">Duplicates</p>
                        <p class="text-xl font-semibold text-amber-800">{{ counts.duplicate }}</p>
                    </div>
                    <div class="rounded-xl bg-sky-50 p-3 text-center">
                        <p class="text-xs uppercase text-sky-700">Importable</p>
                        <p class="text-xl font-semibold text-sky-800">{{ counts.importable }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3 text-center">
                        <p class="text-xs uppercase text-slate-500">Will skip</p>
                        <p class="text-xl font-semibold">{{ counts.skippable }}</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="ui-table text-sm">
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Status</th>
                                <th>Action</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in previewRows" :key="row.row">
                                <td>{{ row.row }}</td>
                                <td class="capitalize">{{ row.status }}</td>
                                <td>{{ row.action || '—' }}</td>
                                <td>
                                    <span v-if="row.errors?.length" class="text-rose-600">{{ row.errors.join(' ') }}</span>
                                    <span v-else class="text-slate-500">{{ rowDetails(row) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
