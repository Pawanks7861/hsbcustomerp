<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({
    batches: { type: Array, required: true },
    catalog: { type: Array, required: true },
});
</script>

<template>
    <Head title="Import History" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Import History" eyebrow="Data Import" />
        </template>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="item in catalog"
                :key="item.key"
                :href="item.key === 'boq' ? '/projects' : `/imports/${item.key}`"
                class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-slate-300"
            >
                <p class="font-medium text-slate-900">{{ item.label }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.permission }}</p>
            </Link>
        </div>

        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Module</th>
                        <th>File</th>
                        <th>By</th>
                        <th>Status</th>
                        <th>Rows</th>
                        <th>Created</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                        <th>Failed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="batches.length === 0">
                        <td colspan="11" class="py-8 text-center text-slate-400">No imports yet.</td>
                    </tr>
                    <tr v-for="batch in batches" :key="batch.id">
                        <td class="font-medium">{{ batch.batch_number }}</td>
                        <td>{{ batch.module }}</td>
                        <td>{{ batch.original_filename }}</td>
                        <td>{{ batch.uploaded_by || '—' }}</td>
                        <td class="capitalize">{{ batch.status }}</td>
                        <td>{{ batch.total_rows }}</td>
                        <td>{{ batch.created_count }}</td>
                        <td>{{ batch.updated_count }}</td>
                        <td>{{ batch.skipped_count }}</td>
                        <td>{{ batch.failed_count }}</td>
                        <td class="text-right">
                            <a
                                v-if="batch.invalid_rows > 0 || batch.failed_count > 0"
                                :href="`/imports/batches/${batch.id}/errors`"
                                class="text-sm font-medium hover:underline"
                            >Errors</a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
