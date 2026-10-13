<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import BoqExcelDropzone from '../../Components/BoqExcelDropzone.vue';
import PageHeader from '../../Components/PageHeader.vue';
import { formatInr, formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    boq: { type: Object, required: true },
    existingCount: { type: Number, required: true },
    preview: { type: Object, default: null },
});

const page = usePage();
const form = useForm({ file: null });
const choosing = ref(false);
const importError = computed(() => page.props.errors?.import ?? null);
const canConfirm = computed(() => props.preview?.can_confirm === true);
const showDropzone = computed(() => !props.preview || choosing.value);

const counts = computed(() => {
    const rows = props.preview?.rows || [];

    return {
        total: rows.length,
        valid: rows.filter((row) => row.status === 'valid').length,
        warnings: rows.filter((row) => row.status !== 'valid' && row.status !== 'invalid' && row.status !== 'header').length,
        invalid: rows.filter((row) => row.status === 'invalid').length,
    };
});

function blockFileOpen(event) {
    event.preventDefault();
}

onMounted(() => {
    window.addEventListener('dragover', blockFileOpen);
    window.addEventListener('drop', blockFileOpen);
});

onBeforeUnmount(() => {
    window.removeEventListener('dragover', blockFileOpen);
    window.removeEventListener('drop', blockFileOpen);
});

function previewImport() {
    if (!form.file || form.processing) {
        return;
    }

    form.post(`/projects/${props.project.id}/boqs/${props.boq.id}/import`, {
        forceFormData: true,
    });
}

function confirmImport() {
    if (!canConfirm.value || form.processing) {
        return;
    }

    router.post(`/projects/${props.project.id}/boqs/${props.boq.id}/import/confirm`);
}

function statusLabel(status) {
    if (status === 'header') {
        return 'Header';
    }

    if (status === 'invalid') {
        return 'Invalid';
    }

    return 'Valid';
}
</script>

<template>
    <Head title="Import BOQ" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Import BOQ from Excel" :eyebrow="`${project.project_name} / BOQ / Import Excel`">
                <a :href="`/projects/${project.id}/boqs/${boq.id}/template`" class="ui-btn-secondary">Download Template</a>
            </PageHeader>
        </template>

        <p class="mb-4 max-w-2xl text-sm text-slate-500">Upload a BOQ Excel workbook. Review the data before confirming the import.</p>

        <div v-if="showDropzone" class="mb-4 max-w-3xl">
            <BoqExcelDropzone v-model="form.file" :error="form.errors.file || ''" :processing="form.processing" />
            <div class="mt-4 flex flex-wrap gap-3">
                <button v-if="form.file" type="button" class="ui-btn-secondary" :disabled="form.processing" @click="form.file = null">Change File</button>
                <button type="button" class="ui-btn-primary" :disabled="!form.file || form.processing" @click="previewImport">Preview Import</button>
                <button v-if="preview && choosing" type="button" class="text-sm text-slate-600 hover:underline" @click="choosing = false">Back to preview</button>
            </div>
            <p class="mt-3 text-sm text-slate-500">Nothing is saved until you confirm the preview.</p>
        </div>

        <div v-if="preview && !choosing" class="space-y-4">
            <div v-if="existingCount > 0" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                This BOQ already contains {{ existingCount }} items. Confirming will append the imported rows.
            </div>
            <p v-if="preview.message" class="text-sm text-red-700" role="alert">{{ preview.message }}</p>
            <p v-if="importError" class="text-sm text-red-700" role="alert">{{ importError }}</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-[#E8F1FB] p-4">
                    <p class="text-xs text-slate-500">Total Rows</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ counts.total }}</p>
                </div>
                <div class="rounded-2xl bg-[#E7F6EF] p-4">
                    <p class="text-xs text-slate-500">Valid</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ counts.valid }}</p>
                </div>
                <div class="rounded-2xl bg-[#F8F1E7] p-4">
                    <p class="text-xs text-slate-500">Warnings</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ counts.warnings }}</p>
                </div>
                <div class="rounded-2xl bg-[#FDECEC] p-4">
                    <p class="text-xs text-slate-500">Invalid</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ counts.invalid }}</p>
                </div>
            </div>

            <div class="ui-table-wrap">
                <table class="ui-table min-w-[1100px]">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Sr. No.</th>
                            <th>Service Code</th>
                            <th class="min-w-[20rem]">Item Description</th>
                            <th>Unit</th>
                            <th class="text-right">B.O.Q.</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">BOQ Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="preview.rows.length === 0">
                            <td colspan="8" class="py-8 text-center text-slate-400">No importable rows were found.</td>
                        </tr>
                        <tr v-for="row in preview.rows" :key="row.row_number" :class="row.status === 'header' ? 'bg-amber-50/70' : ''">
                            <td>
                                <span :class="row.status === 'invalid' ? 'font-medium text-red-700' : ''">{{ statusLabel(row.status) }}</span>
                                <p v-for="(error, index) in row.errors" :key="index" class="text-xs text-red-700">
                                    Row {{ row.row_number }}: {{ error }}
                                </p>
                            </td>
                            <td>{{ row.sr_no || '—' }}</td>
                            <td>{{ row.service_code || '—' }}</td>
                            <td class="whitespace-pre-line">{{ row.description || '—' }}</td>
                            <td>{{ row.unit || '—' }}</td>
                            <td class="text-right tabular-nums">{{ row.quantity === null ? '—' : formatQty(row.quantity) }}</td>
                            <td class="text-right tabular-nums">{{ row.rate === null ? '—' : formatInr(row.rate) }}</td>
                            <td class="text-right tabular-nums">{{ row.amount === null ? '—' : formatInr(row.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="ui-btn-primary" :disabled="!canConfirm" @click="confirmImport">Confirm Import</button>
                <button type="button" class="ui-btn-secondary" @click="choosing = true">Choose Another File</button>
                <Link :href="`/projects/${project.id}/boqs/${boq.id}`" class="text-sm text-slate-600 hover:underline">Back</Link>
            </div>
        </div>
    </AdminLayout>
</template>
