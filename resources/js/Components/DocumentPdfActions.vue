<script setup>
const props = defineProps({
    viewUrl: { type: String, required: true },
    downloadUrl: { type: String, required: true },
});

function printDocument() {
    window.open(props.viewUrl, '_blank', 'noopener');
}
</script>

<template>
    <a :href="viewUrl" target="_blank" rel="noopener" class="ui-btn-secondary">View PDF</a>
    <a :href="downloadUrl" class="ui-btn-secondary">Download PDF</a>
    <button type="button" class="ui-btn-secondary" @click="printDocument">Print</button>
</template>
