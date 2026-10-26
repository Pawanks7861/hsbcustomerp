<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Search, X } from '@lucide/vue';

const MIN_LENGTH = 2;
const DEBOUNCE_MS = 300;

const root = ref(null);
const input = ref(null);
const query = ref('');
const groups = ref([]);
const loading = ref(false);
const error = ref('');
const open = ref(false);
const mobileOpen = ref(false);
const activeIndex = ref(-1);
const searchedFor = ref('');

let timer = null;
let controller = null;
const cache = new Map();

const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform || navigator.userAgent || '');
const shortcutLabel = isMac ? '⌘K' : 'Ctrl K';

const flatItems = computed(() => groups.value.flatMap((group) => group.items.map((item) => ({ ...item, group: group.label }))));
const trimmed = computed(() => query.value.trim());
const showPanel = computed(() => open.value && trimmed.value.length >= MIN_LENGTH);

function indexOf(groupIndex, itemIndex) {
    let index = 0;
    for (let g = 0; g < groupIndex; g++) {
        index += groups.value[g].items.length;
    }

    return index + itemIndex;
}

function reset() {
    clearTimeout(timer);
    controller?.abort();
    controller = null;
    groups.value = [];
    error.value = '';
    loading.value = false;
    activeIndex.value = -1;
    searchedFor.value = '';
}

async function run(term) {
    if (cache.has(term)) {
        groups.value = cache.get(term);
        searchedFor.value = term;
        loading.value = false;
        error.value = '';
        activeIndex.value = groups.value.length ? 0 : -1;

        return;
    }

    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.get('/search', { params: { q: term }, signal: controller.signal });
        if (term !== trimmed.value) {
            return;
        }
        groups.value = data.groups || [];
        searchedFor.value = term;
        activeIndex.value = groups.value.length ? 0 : -1;
        if (cache.size > 20) {
            cache.delete(cache.keys().next().value);
        }
        cache.set(term, groups.value);
    } catch (exception) {
        if (exception?.code === 'ERR_CANCELED' || exception?.name === 'CanceledError') {
            return;
        }
        groups.value = [];
        searchedFor.value = term;
        error.value = exception?.response?.status === 429
            ? 'Too many searches. Please wait a moment and try again.'
            : 'Search failed. Please try again.';
    } finally {
        if (term === trimmed.value) {
            loading.value = false;
        }
    }
}

watch(query, () => {
    clearTimeout(timer);
    const term = trimmed.value.slice(0, 100);

    if (term.length < MIN_LENGTH) {
        reset();

        return;
    }

    open.value = true;
    if (term === searchedFor.value) {
        return;
    }
    loading.value = true;
    error.value = '';
    timer = setTimeout(() => run(term), DEBOUNCE_MS);
});

function clear() {
    query.value = '';
    reset();
    input.value?.focus();
}

function close() {
    open.value = false;
    mobileOpen.value = false;
    activeIndex.value = -1;
}

function choose(item) {
    if (!item?.url) {
        return;
    }
    close();
    query.value = '';
    reset();
    router.visit(item.url);
}

function onKeydown(event) {
    const count = flatItems.value.length;

    if (event.key === 'Escape') {
        event.preventDefault();
        if (open.value) {
            close();
        } else {
            input.value?.blur();
        }

        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (!count) {
            return;
        }
        event.preventDefault();
        open.value = true;
        const step = event.key === 'ArrowDown' ? 1 : -1;
        activeIndex.value = (activeIndex.value + step + count) % count;
        nextTick(() => document.getElementById(`global-search-option-${activeIndex.value}`)?.scrollIntoView({ block: 'nearest' }));

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        if (activeIndex.value >= 0 && flatItems.value[activeIndex.value]) {
            choose(flatItems.value[activeIndex.value]);
        } else if (trimmed.value.length >= MIN_LENGTH) {
            clearTimeout(timer);
            run(trimmed.value.slice(0, 100));
        }
    }
}

function focusSearch() {
    mobileOpen.value = true;
    nextTick(() => {
        input.value?.focus();
        input.value?.select();
        if (trimmed.value.length >= MIN_LENGTH) {
            open.value = true;
        }
    });
}

function onGlobalKeydown(event) {
    if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || String(event.key).toLowerCase() !== 'k') {
        return;
    }

    const active = document.activeElement;
    if (active?.isContentEditable || active?.closest?.('[role="dialog"]')) {
        return;
    }

    event.preventDefault();
    focusSearch();
}

function onDocumentPointer(event) {
    if (root.value && !root.value.contains(event.target)) {
        close();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onGlobalKeydown);
    document.addEventListener('mousedown', onDocumentPointer);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
    window.removeEventListener('keydown', onGlobalKeydown);
    document.removeEventListener('mousedown', onDocumentPointer);
});
</script>

<template>
    <div ref="root" class="relative shrink-0">
        <button
            type="button"
            class="rounded-lg p-2 text-slate-500 hover:bg-slate-50 md:hidden"
            aria-label="Search anything in ERP"
            @click="mobileOpen ? close() : focusSearch()"
        >
            <Search class="h-5 w-5" />
        </button>

        <div
            class="md:block"
            :class="mobileOpen ? 'fixed inset-x-3 top-3 z-50 block rounded-xl bg-white p-2 shadow-xl md:static md:inset-auto md:z-auto md:p-0 md:shadow-none' : 'hidden'"
        >
            <div class="relative w-full md:w-72 lg:w-80">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                <input
                    ref="input"
                    v-model="query"
                    type="search"
                    maxlength="100"
                    autocomplete="off"
                    spellcheck="false"
                    role="combobox"
                    aria-label="Search anything in ERP"
                    aria-autocomplete="list"
                    aria-controls="global-search-results"
                    :aria-expanded="showPanel ? 'true' : 'false'"
                    :aria-activedescendant="activeIndex >= 0 ? `global-search-option-${activeIndex}` : undefined"
                    class="ui-input h-9 pl-9 pr-16"
                    placeholder="Search anything in ERP..."
                    @focus="open = trimmed.length >= MIN_LENGTH"
                    @keydown="onKeydown"
                >
                <div class="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
                    <LoaderCircle v-if="loading" class="h-4 w-4 animate-spin text-slate-400" aria-hidden="true" />
                    <button
                        v-if="query"
                        type="button"
                        class="inline-flex h-6 w-6 items-center justify-center rounded text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Clear search"
                        @click="clear"
                    >
                        <X class="h-4 w-4" />
                    </button>
                    <kbd v-else class="hidden rounded border border-slate-200 px-1.5 text-[10px] font-medium text-slate-400 lg:inline">{{ shortcutLabel }}</kbd>
                </div>
            </div>

            <div
                v-if="showPanel"
                id="global-search-results"
                role="listbox"
                aria-label="Search results"
                class="absolute right-0 z-50 mt-2 max-h-[70vh] w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-xl md:w-[28rem]"
            >
                <p v-if="error" class="px-3 py-4 text-sm text-red-700" role="alert">{{ error }}</p>
                <p v-else-if="!groups.length && (loading || searchedFor !== trimmed.slice(0, 100))" class="px-3 py-4 text-sm text-slate-500">Searching…</p>
                <p v-else-if="!groups.length" class="px-3 py-4 text-sm text-slate-500">No matching records found</p>
                <template v-else>
                    <section v-for="(group, groupIndex) in groups" :key="group.key" class="py-1">
                        <div class="flex items-center justify-between px-3 py-1">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ group.label }}</h3>
                            <button
                                v-if="group.more_url"
                                type="button"
                                class="text-[11px] font-medium text-amber-700 hover:underline"
                                @click="choose({ url: group.more_url })"
                            >View all</button>
                        </div>
                        <button
                            v-for="(item, itemIndex) in group.items"
                            :id="`global-search-option-${indexOf(groupIndex, itemIndex)}`"
                            :key="`${group.key}-${itemIndex}`"
                            type="button"
                            role="option"
                            :aria-selected="activeIndex === indexOf(groupIndex, itemIndex) ? 'true' : 'false'"
                            class="flex w-full flex-col items-start rounded-lg px-3 py-2 text-left"
                            :class="activeIndex === indexOf(groupIndex, itemIndex) ? 'bg-slate-100' : 'hover:bg-slate-50'"
                            @mouseenter="activeIndex = indexOf(groupIndex, itemIndex)"
                            @click="choose(item)"
                        >
                            <span class="w-full truncate text-sm font-medium text-slate-800">{{ item.title }}</span>
                            <span v-if="item.subtitle" class="w-full truncate text-xs text-slate-500">{{ item.subtitle }}</span>
                        </button>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
