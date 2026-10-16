<script setup>
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

const company = computed(() => usePage().props.company ?? null);
const logoBroken = ref(false);

watch(() => company.value?.logo_url, () => {
    logoBroken.value = false;
});
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-10 text-slate-800">
        <Head>
            <link v-if="company?.favicon_url" rel="icon" :href="company.favicon_url" head-key="favicon">
        </Head>
        <div class="mb-6 text-center">
            <img
                v-if="company?.logo_url && !logoBroken"
                :src="company.logo_url"
                alt=""
                class="mx-auto mb-3 h-12 max-h-12 w-auto max-w-[160px] object-contain"
                @error="logoBroken = true"
            >
            <p class="text-base font-semibold text-slate-900">{{ company?.company_name || 'HSB Projects' }}</p>
        </div>
        <div class="w-full max-w-md rounded-[24px] border border-slate-200/80 bg-white px-6 py-6">
            <slot />
        </div>
    </div>
</template>
