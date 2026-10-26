<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';
import TablePagination from '../../Components/TablePagination.vue';
import TableToolbar from '../../Components/TableToolbar.vue';
import { visitListing } from '../../lib/listing';

defineProps({
    categories: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const createForm = useForm({ name: '' });

function update(category) {
    useForm({ name: category.name, is_active: category.is_active }).put(`/expense-categories/${category.id}`, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Expense Heads" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Expense Heads" eyebrow="Admin">
                <ImportButton module="expense_categories" permission="expenses.import" return-url="/expense-categories" />
            </PageHeader>
        </template>
        <form class="ui-card mb-4 flex gap-2 p-4" @submit.prevent="createForm.post('/expense-categories', { preserveScroll: true })">
            <input v-model="createForm.name" required placeholder="New expense head" class="ui-input flex-1">
            <button class="ui-btn-primary">Add</button>
        </form>
        <TableToolbar :search="filters.search" placeholder="Search expense heads…">
            <label class="text-sm"><span class="sr-only">Status</span>
                <select class="ui-input w-36" :value="filters.status || ''" @change="visitListing({ status: $event.target.value })">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>
        </TableToolbar>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Name</th><th>Active</th><th></th></tr></thead>
                <tbody>
                    <tr v-if="categories.data.length === 0"><td colspan="3" class="py-8 text-center text-slate-400">{{ filters.search || filters.status ? 'No matching records found.' : 'No expense heads yet.' }}</td></tr>
                    <tr v-for="category in categories.data" :key="category.id">
                        <td><input v-model="category.name" class="ui-input w-full"></td>
                        <td><input v-model="category.is_active" type="checkbox"></td>
                        <td><button type="button" class="ui-btn-secondary" @click="update(category)">Save</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="categories" label="expense heads" />
    </AdminLayout>
</template>
