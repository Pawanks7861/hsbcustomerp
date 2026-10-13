<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    company: {
        type: Object,
        default: null,
    },
});

const logoBroken = ref(false);

watch(() => props.company?.logo_url, () => {
    logoBroken.value = false;
});
</script>

<template>
    <div v-if="company" class="flex items-start gap-4">
        <img
            v-if="company.logo_url && !logoBroken"
            :src="company.logo_url"
            alt=""
            class="h-16 max-h-16 w-auto max-w-[160px] object-contain"
            @error="logoBroken = true"
        >
        <div class="text-sm text-slate-700">
            <p class="text-lg font-semibold text-slate-900">{{ company.company_name }}</p>
            <p v-if="company.address" class="mt-1 whitespace-pre-line">{{ company.address }}</p>
            <p v-if="company.city || company.state || company.pincode">
                {{ [company.city, company.state, company.pincode].filter(Boolean).join(', ') }}
            </p>
            <p v-if="company.country">{{ company.country }}</p>
            <p v-if="company.phone">Phone: {{ company.phone }}</p>
            <p v-if="company.email">{{ company.email }}</p>
            <p v-if="company.gst_number">GST: {{ company.gst_number }}</p>
            <p v-if="company.pan_number">PAN: {{ company.pan_number }}</p>
            <p v-if="company.website">{{ company.website }}</p>
        </div>
    </div>
</template>
