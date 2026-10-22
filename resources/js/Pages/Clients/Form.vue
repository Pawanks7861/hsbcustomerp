<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    client: { type: Object, default: null },
});

const form = useForm({
    name: props.client?.name ?? '',
    contact_person: props.client?.contact_person ?? '',
    phone: props.client?.phone ?? '',
    email: props.client?.email ?? '',
    address: props.client?.address ?? '',
    city: props.client?.city ?? '',
    state: props.client?.state ?? '',
    pincode: props.client?.pincode ?? '',
    gst_number: props.client?.gst_number ?? '',
    pan_number: props.client?.pan_number ?? '',
    payment_terms: props.client?.payment_terms ?? 'Net 30',
    notes: props.client?.notes ?? '',
    status: props.client?.status ?? 'active',
});

function submit() {
    props.client?.id ? form.put(`/clients/${props.client.id}`) : form.post('/clients');
}

function error(field) {
    return form.errors[field] || '';
}
</script>

<template>
    <Head :title="client ? 'Edit Client' : 'Add Client'" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="client ? 'Edit Client' : 'Add Client'" />
        </template>
        <form class="ui-card grid gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
            <label class="text-sm">Client name<input v-model="form.name" required class="ui-input mt-1 w-full"><span v-if="error('name')" class="mt-1 block text-red-700">{{ error('name') }}</span></label>
            <label class="text-sm">Contact person<input v-model="form.contact_person" required class="ui-input mt-1 w-full"><span v-if="error('contact_person')" class="mt-1 block text-red-700">{{ error('contact_person') }}</span></label>
            <label class="text-sm">Phone<input v-model="form.phone" required class="ui-input mt-1 w-full"><span v-if="error('phone')" class="mt-1 block text-red-700">{{ error('phone') }}</span></label>
            <label class="text-sm">Email<input v-model="form.email" type="email" required class="ui-input mt-1 w-full"><span v-if="error('email')" class="mt-1 block text-red-700">{{ error('email') }}</span></label>
            <label class="text-sm md:col-span-2">Address<textarea v-model="form.address" required rows="2" class="ui-textarea mt-1 w-full"></textarea><span v-if="error('address')" class="mt-1 block text-red-700">{{ error('address') }}</span></label>
            <label class="text-sm">City<input v-model="form.city" required class="ui-input mt-1 w-full"><span v-if="error('city')" class="mt-1 block text-red-700">{{ error('city') }}</span></label>
            <label class="text-sm">State<input v-model="form.state" required class="ui-input mt-1 w-full"><span v-if="error('state')" class="mt-1 block text-red-700">{{ error('state') }}</span></label>
            <label class="text-sm">Pincode<input v-model="form.pincode" required class="ui-input mt-1 w-full"><span v-if="error('pincode')" class="mt-1 block text-red-700">{{ error('pincode') }}</span></label>
            <label class="text-sm">GSTIN<input v-model="form.gst_number" class="ui-input mt-1 w-full"><span v-if="error('gst_number')" class="mt-1 block text-red-700">{{ error('gst_number') }}</span></label>
            <label class="text-sm">PAN<input v-model="form.pan_number" class="ui-input mt-1 w-full"><span v-if="error('pan_number')" class="mt-1 block text-red-700">{{ error('pan_number') }}</span></label>
            <label class="text-sm">Payment terms<input v-model="form.payment_terms" class="ui-input mt-1 w-full"></label>
            <label v-if="client" class="text-sm">Status
                <select v-model="form.status" class="ui-input mt-1 w-full">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>
            <label class="text-sm md:col-span-2">Notes<textarea v-model="form.notes" rows="3" class="ui-textarea mt-1 w-full"></textarea></label>
            <div class="md:col-span-2">
                <button class="ui-btn-primary">{{ client ? 'Save client' : 'Create client' }}</button>
            </div>
        </form>
    </AdminLayout>
</template>
