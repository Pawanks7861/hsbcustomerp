<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    units: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'units']);

const open = ref(false);
const query = ref('');
const creating = ref(false);
const newName = ref('');
const creatingError = ref('');
const saving = ref(false);
const options = ref([...props.units]);

watch(() => props.units, (value) => {
    options.value = [...value];
});

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) {
        return options.value;
    }

    return options.value.filter((unit) => String(unit).toLowerCase().includes(needle));
});

function select(unit) {
    emit('update:modelValue', unit);
    open.value = false;
    creating.value = false;
    query.value = '';
}

function startCreate() {
    creating.value = true;
    newName.value = query.value.trim();
    creatingError.value = '';
}

async function saveUnit() {
    saving.value = true;
    creatingError.value = '';
    try {
        const { data } = await window.axios.post('/inventory/units', { name: newName.value });
        options.value = data.units || [...options.value, data.name];
        emit('units', options.value);
        select(data.name);
        newName.value = '';
    } catch (error) {
        creatingError.value = error.response?.data?.errors?.name?.[0]
            || error.response?.data?.message
            || 'Could not save this unit.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="relative">
        <button type="button" class="ui-input flex w-full items-center justify-between text-left" @click="open = !open">
            <span>{{ modelValue || 'Select unit' }}</span>
            <span class="text-slate-400">▾</span>
        </button>
        <div v-if="open" class="absolute z-20 mt-1 w-full rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
            <input v-model="query" type="search" class="ui-input mb-2 w-full" placeholder="Search units…" @keydown.esc="open = false">
            <ul class="max-h-48 overflow-y-auto text-sm">
                <li v-for="unit in filtered" :key="unit">
                    <button type="button" class="w-full rounded-lg px-2 py-1.5 text-left hover:bg-slate-50" :class="unit === modelValue ? 'font-medium text-amber-800' : ''" @click="select(unit)">{{ unit }}</button>
                </li>
                <li v-if="filtered.length === 0" class="px-2 py-2 text-slate-400">No matching units.</li>
            </ul>
            <button v-if="canCreate && !creating" type="button" class="mt-2 w-full rounded-lg px-2 py-1.5 text-left text-sm font-medium text-amber-800 hover:bg-amber-50" @click="startCreate">Add new unit</button>
            <form v-if="creating" class="mt-2 space-y-2" @submit.prevent="saveUnit">
                <input v-model="newName" required maxlength="50" class="ui-input w-full" placeholder="Unit name, e.g. Packet">
                <p v-if="creatingError" class="text-sm text-red-700">{{ creatingError }}</p>
                <div class="flex gap-2">
                    <button class="ui-btn-primary" type="submit" :disabled="saving">Save unit</button>
                    <button class="ui-btn-secondary" type="button" @click="creating = false">Cancel</button>
                </div>
            </form>
        </div>
        <p v-if="error" class="mt-1 text-sm text-red-700">{{ error }}</p>
    </div>
</template>
