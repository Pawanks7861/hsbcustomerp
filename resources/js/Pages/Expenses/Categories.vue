<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImportButton from '../../Components/ImportButton.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({ categories: { type: Array, required: true } });

const createForm = useForm({ name: '' });

function update(category) {
    useForm({ name: category.name, is_active: category.is_active }).put(`/expense-categories/${category.id}`);
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
        <form class="ui-card mb-4 flex gap-2 p-4" @submit.prevent="createForm.post('/expense-categories')">
            <input v-model="createForm.name" required placeholder="New expense head" class="ui-input flex-1">
            <button class="ui-btn-primary">Add</button>
        </form>
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr><th>Name</th><th>Active</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="category in categories" :key="category.id">
                        <td><input v-model="category.name" class="ui-input w-full"></td>
                        <td><input v-model="category.is_active" type="checkbox"></td>
                        <td><button type="button" class="ui-btn-secondary" @click="update(category)">Save</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
