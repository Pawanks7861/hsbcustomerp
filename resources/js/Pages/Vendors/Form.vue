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
</script>

<template>
    <Head :title="vendor ? 'Edit Vendor' : 'Add Vendor'" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="vendor ? 'Edit Vendor' : 'Add Vendor'" />
        </template>
        <form class="ui-card grid gap-4 p-6 md:grid-cols-2" @submit.prevent="submit">
            <label class="text-sm md:col-span-2">Vendor Name<input v-model="form.name" required class="ui-input"></label>
            <label class="text-sm">Classification
                <select v-model="form.party_type" class="ui-input">
                    <option value="vendor">Vendor</option>
                    <option value="contractor">Contractor</option>
                    <option value="both">Vendor and contractor</option>
                </select>
            </label>
            <label class="text-sm">Contact Person<input v-model="form.contact_person" class="ui-input"></label>
            <label class="text-sm">Phone<input v-model="form.phone" class="ui-input"></label>
            <label class="text-sm">Email<input v-model="form.email" type="email" class="ui-input"></label>
            <label class="text-sm">GST Number<input v-model="form.gst_number" class="ui-input"></label>
            <label class="text-sm md:col-span-2">Address<textarea v-model="form.address" rows="2" class="ui-textarea"></textarea></label>
            <label class="text-sm">City<input v-model="form.city" class="ui-input"></label>
            <label class="text-sm">State<input v-model="form.state" class="ui-input"></label>
            <label class="text-sm">Pincode<input v-model="form.pincode" class="ui-input"></label>
            <label class="text-sm">PAN<input v-model="form.pan_number" class="ui-input"></label>
            <label class="text-sm">Bank Name<input v-model="form.bank_name" class="ui-input"></label>
            <label class="text-sm">Account Number<input v-model="form.account_number" class="ui-input"></label>
            <label class="text-sm">IFSC<input v-model="form.ifsc" class="ui-input"></label>
            <label class="text-sm">Opening Advance<input v-model="form.opening_advance" type="number" min="0" step="0.01" class="ui-input"></label>
            <label class="text-sm">Opening Outstanding<input v-model="form.opening_outstanding" type="number" min="0" step="0.01" class="ui-input"></label>
            <label class="text-sm md:col-span-2">Notes<textarea v-model="form.notes" rows="2" class="ui-textarea"></textarea></label>
            <div class="flex gap-3 md:col-span-2">
                <button class="ui-btn-primary" :disabled="form.processing">Save</button>
                <Link href="/vendors" class="text-sm text-slate-600 hover:underline">Cancel</Link>
            </div>
        </form>
    </AdminLayout>
</template>
