<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { formatInr } from '../lib/money';

const props = defineProps({
    projectId: {
        type: Number,
        required: true,
    },
    boqId: {
        type: Number,
        required: true,
    },
    itemId: {
        type: Number,
        required: true,
    },
    initialEntries: {
        type: Array,
        required: true,
    },
    preserveState: {
        type: Boolean,
        default: false,
    },
    showCancel: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['saved', 'synced', 'cancel']);

const DEFAULTS = ['Material', 'Labour', 'Equipment', 'Miscellaneous'];
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}).flatMap((error) => (
    Array.isArray(error) ? error : [error]
)));

function buildRows(initial) {
    const source = Array.isArray(initial) ? initial.map((entry) => ({ ...entry })) : [];
    const used = new Set();
    const locked = DEFAULTS.map((name) => {
        const index = source.findIndex((row, rowIndex) => row.name === name && !used.has(rowIndex));

        if (index === -1) {
            return { id: null, name, amount: '0.00', locked: true };
        }

        used.add(index);

        return {
            id: source[index].id,
            name,
            amount: source[index].amount,
            locked: true,
        };
    });

    const custom = source
        .filter((_, index) => !used.has(index))
        .map((row) => ({
            id: row.id,
            name: row.name,
            amount: row.amount,
            locked: false,
        }));

    return [...locked, ...custom];
}

const entries = ref(buildRows(props.initialEntries));

const total = computed(() => entries.value.reduce((sum, entry) => sum + (Number(entry.amount) || 0), 0));

function addMore() {
    entries.value.push({
        id: null,
        name: '',
        amount: '0.00',
        locked: false,
    });
}

function removeCustom(index) {
    const row = entries.value[index];

    if (row.locked) {
        return;
    }

    if (!confirm(`Delete budget entry "${row.name || 'this entry'}"?`)) {
        return;
    }

    if (!row.id) {
        entries.value.splice(index, 1);
        return;
    }

    router.delete(`/projects/${props.projectId}/boqs/${props.boqId}/items/${props.itemId}/budgets/${row.id}`, {
        preserveScroll: true,
        preserveState: props.preserveState,
        onSuccess: (visit) => {
            entries.value.splice(index, 1);
            emit('synced', visit);
        },
    });
}

function save() {
    router.put(`/projects/${props.projectId}/boqs/${props.boqId}/items/${props.itemId}/budget`, {
        entries: entries.value.map((entry) => ({
            name: entry.name,
            amount: entry.amount === '' ? 0 : entry.amount,
        })),
    }, {
        preserveScroll: true,
        preserveState: props.preserveState,
        onSuccess: (visit) => emit('saved', visit),
    });
}
</script>

<template>
    <div>
        <div v-if="errors.length" class="mb-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p v-for="(error, index) in errors" :key="index">{{ error }}</p>
        </div>

        <div class="space-y-3">
            <div v-for="(entry, index) in entries" :key="`${entry.name}-${index}`" class="grid grid-cols-[1fr_8.5rem_auto] items-center gap-3">
                <input
                    v-model="entry.name"
                    type="text"
                    class="ui-input"
                    :readonly="entry.locked"
                    :class="entry.locked ? 'bg-slate-50' : ''"
                    placeholder="Budget name"
                >
                <input v-model="entry.amount" type="number" min="0" step="0.01" class="ui-input text-right tabular-nums">
                <button v-if="!entry.locked" type="button" class="text-sm text-red-600" @click="removeCustom(index)">Remove</button>
                <span v-else class="w-14"></span>
            </div>
        </div>

        <button type="button" class="mt-4 text-sm font-medium text-slate-700 hover:text-slate-900" @click="addMore">+ Add More</button>

        <div class="mt-6 rounded-xl bg-slate-50 px-4 py-3">
            <p class="text-xs text-slate-500">Total Budget</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ formatInr(total) }}</p>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <button v-if="showCancel" type="button" class="ui-btn-secondary" @click="emit('cancel')">Cancel</button>
            <button type="button" class="ui-btn-primary" @click="save">Save Budget</button>
        </div>
    </div>
</template>
