<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PER_PAGE_OPTIONS, visitListing } from '../lib/listing';

const props = defineProps({
    paginator: { type: Object, required: true },
    pageName: { type: String, default: 'page' },
    perPageParam: { type: String, default: 'per_page' },
    label: { type: String, default: 'records' },
    only: { type: Array, default: () => [] },
});

const pages = computed(() => (props.paginator.links ?? []).slice(1, -1));
const perPage = computed(() => Number(props.paginator.per_page) || 30);

function changePerPage(event) {
    visitListing({ [props.perPageParam]: event.target.value }, { pageName: props.pageName, only: props.only });
}
</script>

<template>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500 print:hidden">
        <p>
            <template v-if="paginator.total > 0">
                Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }} {{ label }}
            </template>
            <template v-else>0 {{ label }}</template>
        </p>
        <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2">
                <span>Rows per page</span>
                <select class="ui-input h-9 w-20" :value="perPage" @change="changePerPage">
                    <option v-for="option in PER_PAGE_OPTIONS" :key="option" :value="option">{{ option }}</option>
                </select>
            </label>
            <nav v-if="paginator.last_page > 1" class="flex flex-wrap items-center gap-1" aria-label="Pagination">
                <Link
                    v-if="paginator.prev_page_url"
                    :href="paginator.prev_page_url"
                    class="ui-btn-secondary h-9"
                    preserve-scroll
                    preserve-state
                    :only="only"
                >Previous</Link>
                <span v-else class="ui-btn-secondary h-9 cursor-not-allowed opacity-50">Previous</span>
                <template v-for="(link, index) in pages" :key="`${index}-${link.label}`">
                    <span v-if="!link.url" class="px-2 text-slate-400">{{ link.label }}</span>
                    <Link
                        v-else
                        :href="link.url"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm font-medium"
                        :class="link.active ? 'bg-slate-800 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                        :aria-current="link.active ? 'page' : undefined"
                        preserve-scroll
                        preserve-state
                        :only="only"
                    >{{ link.label }}</Link>
                </template>
                <Link
                    v-if="paginator.next_page_url"
                    :href="paginator.next_page_url"
                    class="ui-btn-secondary h-9"
                    preserve-scroll
                    preserve-state
                    :only="only"
                >Next</Link>
                <span v-else class="ui-btn-secondary h-9 cursor-not-allowed opacity-50">Next</span>
                <span class="ml-2 whitespace-nowrap">Page {{ paginator.current_page }} of {{ paginator.last_page }}</span>
            </nav>
        </div>
    </div>
</template>
