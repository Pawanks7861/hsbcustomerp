<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({
    title: { type: String, required: true },
    message: { type: String, required: true },
    returnUrl: { type: String, default: null },
    alternatives: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="title" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="title" eyebrow="Data Import">
                <Link v-if="returnUrl" :href="returnUrl" class="ui-btn-secondary">Back</Link>
                <Link href="/imports/history" class="ui-btn-secondary">Import History</Link>
            </PageHeader>
        </template>
        <div class="mx-auto max-w-2xl rounded-2xl border border-amber-200 bg-amber-50 p-6">
            <p class="text-slate-800">{{ message }}</p>
            <ul v-if="alternatives.length" class="mt-4 list-disc space-y-2 pl-5 text-sm text-slate-700">
                <li v-for="(item, index) in alternatives" :key="index">
                    <Link v-if="item.href" :href="item.href" class="font-medium underline">{{ item.label }}</Link>
                    <span v-else>{{ item.label }}</span>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
