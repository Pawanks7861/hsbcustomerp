<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import FormField from '../../Components/FormField.vue';
import ImageUploadField from '../../Components/ImageUploadField.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
});

const form = useForm({
    company_name: props.company?.company_name ?? '',
    address: props.company?.address ?? '',
    city: props.company?.city ?? '',
    state: props.company?.state ?? '',
    country: props.company?.country ?? '',
    pincode: props.company?.pincode ?? '',
    phone: props.company?.phone ?? '',
    email: props.company?.email ?? '',
    gst_number: props.company?.gst_number ?? '',
    pan_number: props.company?.pan_number ?? '',
    website: props.company?.website ?? '',
    logo: null,
    favicon: null,
});

function submit() {
    form.post('/settings/company', {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="Company Settings" />
    <AdminLayout>
        <template #header>
            <PageHeader title="Company Settings" eyebrow="Settings" />
        </template>

        <form class="max-w-3xl space-y-8" @submit.prevent="submit">
            <section class="ui-card space-y-5 p-6">
                <h2 class="text-sm font-semibold text-slate-900">Company Information</h2>
                <FormField label="Company name" :error="form.errors.company_name">
                    <input v-model="form.company_name" type="text" required class="ui-input">
                </FormField>
                <FormField label="Address">
                    <textarea v-model="form.address" rows="3" class="ui-textarea"></textarea>
                </FormField>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField label="City"><input v-model="form.city" type="text" class="ui-input"></FormField>
                    <FormField label="State"><input v-model="form.state" type="text" class="ui-input"></FormField>
                    <FormField label="Country"><input v-model="form.country" type="text" class="ui-input"></FormField>
                    <FormField label="Pincode"><input v-model="form.pincode" type="text" class="ui-input"></FormField>
                    <FormField label="Phone"><input v-model="form.phone" type="text" class="ui-input"></FormField>
                    <FormField label="Email" :error="form.errors.email"><input v-model="form.email" type="email" class="ui-input"></FormField>
                    <FormField label="GST number"><input v-model="form.gst_number" type="text" class="ui-input"></FormField>
                    <FormField label="PAN number"><input v-model="form.pan_number" type="text" class="ui-input"></FormField>
                </div>
                <FormField label="Website"><input v-model="form.website" type="text" class="ui-input"></FormField>
            </section>

            <section class="ui-card space-y-5 p-6">
                <h2 class="text-sm font-semibold text-slate-900">Branding</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <ImageUploadField
                        v-model="form.logo"
                        label="Company logo"
                        button="Change Logo"
                        hint="PNG, JPG, JPEG or WEBP"
                        accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                        :current-url="company?.logo_url || ''"
                        :error="form.errors.logo || ''"
                    />
                    <ImageUploadField
                        v-model="form.favicon"
                        label="Favicon"
                        button="Change Favicon"
                        hint="PNG, ICO or supported format"
                        accept=".ico,.png,.svg,image/png,image/svg+xml,image/x-icon"
                        :current-url="company?.favicon_url || ''"
                        :error="form.errors.favicon || ''"
                    />
                </div>
            </section>

            <button type="submit" class="ui-btn-primary" :disabled="form.processing">Save</button>
        </form>
    </AdminLayout>
</template>
