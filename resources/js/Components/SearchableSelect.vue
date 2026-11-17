<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronDown, LoaderCircle, Search, X } from '@lucide/vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    fetchUrl: { type: String, required: true },
    placeholder: { type: String, default: 'Search…' },
    selectedLabel: { type: String, default: '' },
    minLength: { type: Number, default: 1 },
    disabled: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'select', 'clear']);

const root = ref(null);
const input = ref(null);
const query = ref('');
const open = ref(false);
const loading = ref(false);
const error = ref('');
const items = ref([]);
const activeIndex = ref(-1);
let timer = null;
let controller = null;

const display = computed(() => {
    if (open.value || query.value) {
        return query.value;
    }

    return props.selectedLabel || '';
});

function resetResults() {
    items.value = [];
    activeIndex.value = -1;
    error.value = '';
}

async function run(term) {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.get(props.fetchUrl, {
            params: { q: term },
            signal: controller.signal,
        });
        if (term !== query.value.trim()) {
            return;
        }
        items.value = data.items || [];
        activeIndex.value = items.value.length ? 0 : -1;
    } catch (exception) {
        if (exception?.code === 'ERR_CANCELED' || exception?.name === 'CanceledError') {
            return;
        }
        items.value = [];
        error.value = 'Search failed. Please try again.';
    } finally {
        if (term === query.value.trim()) {
            loading.value = false;
        }
    }
}

function schedule() {
    clearTimeout(timer);
    const term = query.value.trim().slice(0, 100);
    if (term.length < props.minLength) {
        resetResults();
        loading.value = false;

        return;
    }

    timer = setTimeout(() => run(term), 250);
}

function choose(item) {
    emit('update:modelValue', item.id);
    emit('select', item);
    query.value = '';
    open.value = false;
    resetResults();
}

function clearSelection() {
    emit('update:modelValue', '');
    emit('clear');
    query.value = '';
    open.value = true;
    resetResults();
    nextTick(() => input.value?.focus());
}

function onFocus() {
    if (props.disabled) {
        return;
    }
    open.value = true;
    if (query.value.trim().length >= props.minLength) {
        schedule();
    }
}

function onKeydown(event) {
    if (!open.value) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, items.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && activeIndex.value >= 0 && items.value[activeIndex.value]) {
        event.preventDefault();
        choose(items.value[activeIndex.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onDocumentClick(event) {
    if (!root.value?.contains(event.target)) {
        open.value = false;
    }
}

watch(() => props.modelValue, (value) => {
    if (!value) {
        query.value = '';
    }
});

onMounted(() => document.addEventListener('mousedown', onDocumentClick));
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentClick);
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div ref="root" class="relative">
        <div class="relative">
            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
                ref="input"
                :value="display"
                type="search"
                class="ui-input w-full pl-9 pr-16"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required && !modelValue"
                autocomplete="off"
                @focus="onFocus"
                @keydown="onKeydown"
                @input="query = $event.target.value; open = true; schedule()"
            >
            <div class="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
                <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin text-slate-400" />
                <button
                    v-if="modelValue && !disabled"
                    type="button"
                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                    aria-label="Clear selection"
                    @click="clearSelection"
                >
                    <X class="h-4 w-4" />
                </button>
                <ChevronDown class="h-4 w-4 text-slate-400" />
            </div>
        </div>

        <div
            v-if="open && !disabled"
            class="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg"
            role="listbox"
        >
            <p v-if="error" class="px-3 py-2 text-sm text-red-700">{{ error }}</p>
            <p v-else-if="query.trim().length < minLength" class="px-3 py-2 text-sm text-slate-500">Type at least {{ minLength }} character(s) to search.</p>
            <p v-else-if="!loading && items.length === 0" class="px-3 py-2 text-sm text-slate-500">No matching results.</p>
            <button
                v-for="(item, index) in items"
                :key="item.id"
                type="button"
                role="option"
                class="block w-full px-3 py-2 text-left text-sm hover:bg-amber-50"
                :class="index === activeIndex ? 'bg-amber-50' : ''"
                @mousedown.prevent="choose(item)"
                @mouseenter="activeIndex = index"
            >
                <span class="font-medium text-slate-900">{{ item.label }}</span>
                <span v-if="item.meta" class="mt-0.5 block text-xs text-slate-500">{{ item.meta }}</span>
            </button>
        </div>
        <p v-if="error || props.error" class="mt-1 text-sm text-red-700">{{ props.error || error }}</p>
    </div>
</template>
