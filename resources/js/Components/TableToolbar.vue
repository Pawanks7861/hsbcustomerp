<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Search, X } from '@lucide/vue';
import { visitListing } from '../lib/listing';

const props = defineProps({
    search: { type: String, default: '' },
    placeholder: { type: String, default: 'Search records...' },
    param: { type: String, default: 'search' },
    pageName: { type: String, default: 'page' },
    label: { type: String, default: 'Search records' },
    only: { type: Array, default: () => [] },
});

const term = ref(props.search ?? '');
let timer = null;
let lastSent = (props.search ?? '').trim();

watch(() => props.search, (value) => {
    if ((value ?? '') !== term.value.trim()) {
        term.value = value ?? '';
        lastSent = (value ?? '').trim();
    }
});

function send() {
    clearTimeout(timer);
    const value = term.value.trim().slice(0, 100);

    if (value === lastSent) {
        return;
    }

    lastSent = value;
    visitListing({ [props.param]: value }, { pageName: props.pageName, only: props.only });
}

function onInput() {
    clearTimeout(timer);
    timer = setTimeout(send, 350);
}

function clear() {
    term.value = '';
    send();
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="mb-4 flex flex-wrap items-end gap-3 print:hidden">
        <div class="relative w-full sm:w-80">
            <label class="sr-only" :for="`table-search-${param}`">{{ label }}</label>
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input
                :id="`table-search-${param}`"
                v-model="term"
                type="search"
                maxlength="100"
                autocomplete="off"
                class="ui-input pl-9 pr-9"
                :placeholder="placeholder"
                @input="onInput"
                @keydown.enter.prevent="send"
                @keydown.esc.prevent="clear"
            >
            <button
                v-if="term"
                type="button"
                class="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                aria-label="Clear search"
                @click="clear"
            >
                <X class="h-4 w-4" />
            </button>
        </div>
        <slot />
    </div>
</template>
