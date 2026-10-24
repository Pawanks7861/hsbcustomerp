<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const message = ref('');

function onRateLimited(event) {
    message.value = event.detail?.message
        || 'Too many requests. Please wait a moment and try again.';
}

onMounted(() => {
    window.addEventListener('erp:rate-limited', onRateLimited);
});

onBeforeUnmount(() => {
    window.removeEventListener('erp:rate-limited', onRateLimited);
});
</script>

<template>
    <div
        v-if="message"
        class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 print:hidden"
        role="alert"
    >
        {{ message }}
    </div>
</template>
