<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, default: null },
    expense: { type: Object, default: null },
    categories: { type: Array, required: true },
    projects: { type: Array, required: true },
    vendors: { type: Array, required: true },
    paymentModes: { type: Array, required: true },
});

const form = useForm({
    date: props.expense?.date || new Date().toISOString().slice(0, 10),
    project_id: props.project?.id || props.expense?.project_id || '',
    category_id: props.expense?.category_id || '',
    description: props.expense?.description || '',
    payee: props.expense?.payee || '',
    vendor_id: props.expense?.vendor_id || '',
    reference_number: props.expense?.reference_number || '',
    payment_mode: props.expense?.payment_mode || '',
    amount: props.expense?.amount || '',
    tax_amount: props.expense?.tax_amount || '0',
    round_off: props.expense?.round_off || '0',
    payment_status: props.expense?.payment_status || 'unpaid',
    notes: props.expense?.notes || '',
    attachment: null,
});

const total = computed(() => (Number(form.amount || 0) + Number(form.tax_amount || 0) + Number(form.round_off || 0)).toFixed(2));
const base = props.project ? `/projects/${props.project.id}/expenses` : '/expenses';

function submit() {
    const url = props.expense ? `${base}/${props.expense.id}` : base;
    props.expense ? form.post(url, { forceFormData: true, _method: 'put' }) : form.post(url, { forceFormData: true });
}
</script>

<template>
    <Head :title="expense ? expense.number : 'New Expense'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="expense ? expense.number : 'New Expense'" :eyebrow="project?.project_name || 'Expense'" />
        </template>
        <form class="ui-card max-w-3xl space-y-4 p-5" @submit.prevent="submit">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm">Date<input v-model="form.date" type="date" required class="ui-input mt-1 w-full"></label>
                <label v-if="!project" class="text-sm">Project
                    <select v-model="form.project_id" class="ui-input mt-1 w-full">
                        <option value="">Company / overhead</option>
                        <option v-for="row in projects" :key="row.id" :value="row.id">{{ row.project_name }}</option>
                    </select>
                </label>
                <label class="text-sm">Expense Head
                    <select v-model="form.category_id" required class="ui-input mt-1 w-full">
                        <option value="">Select</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}{{ category.is_active === false ? ' (inactive)' : '' }}</option>
                    </select>
                    <span v-if="form.errors.category_id" class="mt-1 block text-red-700">{{ form.errors.category_id }}</span>
                </label>
                <label class="text-sm">Payment status
                    <select v-model="form.payment_status" class="ui-input mt-1 w-full">
                        <option value="unpaid">Unpaid</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                    </select>
                </label>
            </div>
            <label class="block text-sm">Description<input v-model="form.description" required class="ui-input mt-1 w-full"></label>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm">Payee<input v-model="form.payee" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Vendor
                    <select v-model="form.vendor_id" class="ui-input mt-1 w-full">
                        <option value="">Optional</option>
                        <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option>
                    </select>
                </label>
                <label class="text-sm">Reference<input v-model="form.reference_number" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Payment mode
                    <select v-model="form.payment_mode" class="ui-input mt-1 w-full">
                        <option value="">Optional</option>
                        <option v-for="mode in paymentModes" :key="mode" :value="mode">{{ mode }}</option>
                    </select>
                </label>
            </div>
            <div class="grid gap-4 md:grid-cols-4">
                <label class="text-sm">Amount<input v-model="form.amount" type="number" min="0" step="0.01" required class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Tax<input v-model="form.tax_amount" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Round off<input v-model="form.round_off" type="number" step="0.01" class="ui-input mt-1 w-full"></label>
                <label class="text-sm">Total<p class="mt-2 font-semibold">{{ total }}</p></label>
            </div>
            <textarea v-model="form.notes" rows="3" placeholder="Notes" class="ui-input w-full"></textarea>
            <label class="block text-sm">Attachment<input type="file" class="ui-input mt-1 w-full" @change="form.attachment = $event.target.files[0]"></label>
            <button class="ui-btn-primary" type="submit" :disabled="form.processing">Save</button>
        </form>
    </AdminLayout>
</template>
