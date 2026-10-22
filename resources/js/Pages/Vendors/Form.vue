<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    vendor: { type: Object, default: null },
});

const form = useForm({
    name: props.vendor?.name ?? '',
    party_type: props.vendor?.party_type ?? 'vendor',
    contact_person: props.vendor?.contact_person ?? '',
    phone: props.vendor?.phone ?? '',
    email: props.vendor?.email ?? '',
    address: props.vendor?.address ?? '',
    city: props.vendor?.city ?? '',
    state: props.vendor?.state ?? '',
    pincode: props.vendor?.pincode ?? '',
    gst_number: props.vendor?.gst_number ?? '',
    pan_number: props.vendor?.pan_number ?? '',
    bank_name: props.vendor?.bank_name ?? '',
    account_number: props.vendor?.account_number ?? '',
    ifsc: props.vendor?.ifsc ?? '',
    opening_advance: props.vendor?.opening_advance ?? '0',
    opening_outstanding: props.vendor?.opening_outstanding ?? '0',
    notes: props.vendor?.notes ?? '',
});

function submit() {
    if (props.vendor?.id) {
        form.put(`/vendors/${props.vendor.id}`);
        return;
    }

    form.post('/vendors');
}

function error(field) {
    return form.errors[field] || '';
}
</script>

<template>
    <Head :title="vendor ? 'Edit Vendor' : 'Add Vendor'" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="vendor ? 'Edit Vendor' : 'Add Vendor'" />
        </template>
        <form class="ui-card grid gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
            <p class="text-sm text-slate-500 md:col-span-2">All business, contact, address, tax, and bank details are required. Opening balances and notes are optional.</p>
            <label class="text-sm md:col-span-2">Vendor Name <span class="text-red-600">*</span>
                <input v-model="form.name" required class="ui-input">
                <span v-if="error('name')" class="mt-1 block text-red-700">{{ error('name') }}</span>
            </label>
            <label class="text-sm">Classification <span class="text-red-600">*</span>
                <select v-model="form.party_type" required class="ui-input">
                    <option value="vendor">Vendor</option>
                    <option value="contractor">Contractor</option>
                    <option value="both">Vendor and contractor</option>
                </select>
                <span v-if="error('party_type')" class="mt-1 block text-red-700">{{ error('party_type') }}</span>
            </label>
            <label class="text-sm">Contact Person <span class="text-red-600">*</span>
                <input v-model="form.contact_person" required class="ui-input">
                <span v-if="error('contact_person')" class="mt-1 block text-red-700">{{ error('contact_person') }}</span>
            </label>
            <label class="text-sm">Phone <span class="text-red-600">*</span>
                <input v-model="form.phone" required class="ui-input" inputmode="numeric">
                <span v-if="error('phone')" class="mt-1 block text-red-700">{{ error('phone') }}</span>
            </label>
            <label class="text-sm">Email <span class="text-red-600">*</span>
                <input v-model="form.email" type="email" required class="ui-input">
                <span v-if="error('email')" class="mt-1 block text-red-700">{{ error('email') }}</span>
            </label>
            <label class="text-sm">GST Number <span class="text-red-600">*</span>
                <input v-model="form.gst_number" required class="ui-input" maxlength="15">
                <span v-if="error('gst_number')" class="mt-1 block text-red-700">{{ error('gst_number') }}</span>
            </label>
            <label class="text-sm md:col-span-2">Address <span class="text-red-600">*</span>
                <textarea v-model="form.address" rows="2" required class="ui-textarea"></textarea>
                <span v-if="error('address')" class="mt-1 block text-red-700">{{ error('address') }}</span>
            </label>
            <label class="text-sm">City <span class="text-red-600">*</span>
                <input v-model="form.city" required class="ui-input">
                <span v-if="error('city')" class="mt-1 block text-red-700">{{ error('city') }}</span>
            </label>
            <label class="text-sm">State <span class="text-red-600">*</span>
                <input v-model="form.state" required class="ui-input">
                <span v-if="error('state')" class="mt-1 block text-red-700">{{ error('state') }}</span>
            </label>
            <label class="text-sm">Pincode <span class="text-red-600">*</span>
                <input v-model="form.pincode" required class="ui-input" maxlength="6">
                <span v-if="error('pincode')" class="mt-1 block text-red-700">{{ error('pincode') }}</span>
            </label>
            <label class="text-sm">PAN <span class="text-red-600">*</span>
                <input v-model="form.pan_number" required class="ui-input" maxlength="10">
                <span v-if="error('pan_number')" class="mt-1 block text-red-700">{{ error('pan_number') }}</span>
            </label>
            <label class="text-sm">Bank Name <span class="text-red-600">*</span>
                <input v-model="form.bank_name" required class="ui-input">
                <span v-if="error('bank_name')" class="mt-1 block text-red-700">{{ error('bank_name') }}</span>
            </label>
            <label class="text-sm">Account Number <span class="text-red-600">*</span>
                <input v-model="form.account_number" required class="ui-input">
                <span v-if="error('account_number')" class="mt-1 block text-red-700">{{ error('account_number') }}</span>
            </label>
            <label class="text-sm">IFSC <span class="text-red-600">*</span>
                <input v-model="form.ifsc" required class="ui-input" maxlength="11">
                <span v-if="error('ifsc')" class="mt-1 block text-red-700">{{ error('ifsc') }}</span>
            </label>
            <label class="text-sm">Opening Advance
                <input v-model="form.opening_advance" type="number" min="0" step="0.01" class="ui-input">
                <span v-if="error('opening_advance')" class="mt-1 block text-red-700">{{ error('opening_advance') }}</span>
            </label>
            <label class="text-sm">Opening Outstanding
                <input v-model="form.opening_outstanding" type="number" min="0" step="0.01" class="ui-input">
                <span v-if="error('opening_outstanding')" class="mt-1 block text-red-700">{{ error('opening_outstanding') }}</span>
            </label>
            <label class="text-sm md:col-span-2">Notes
                <textarea v-model="form.notes" rows="2" class="ui-textarea"></textarea>
                <span v-if="error('notes')" class="mt-1 block text-red-700">{{ error('notes') }}</span>
            </label>
            <div class="flex gap-3 md:col-span-2">
                <button class="ui-btn-primary" :disabled="form.processing">Save</button>
                <Link href="/vendors" class="text-sm text-slate-600 hover:underline">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
