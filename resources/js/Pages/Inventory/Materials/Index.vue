<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { Plus, X } from '@lucide/vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import MaterialUnitSelect from '../../../Components/MaterialUnitSelect.vue';
import Modal from '../../../Components/Modal.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import TableActionIcon from '../../../Components/TableActionIcon.vue';
import TablePagination from '../../../Components/TablePagination.vue';
import TableToolbar from '../../../Components/TableToolbar.vue';
import { visitListing } from '../../../lib/listing';
import { formatInr } from '../../../lib/money';

const props = defineProps({
    project: { type: Object, default: null },
    central: { type: Boolean, default: false },
    filters: { type: Object, required: true },
    units: { type: Array, required: true },
    materials: { type: Object, required: true },
    canCreateUnit: { type: Boolean, default: false },
    canEditPrice: { type: Boolean, default: true },
});

const open = ref(false);
const editing = ref(null);
const units = ref([...props.units]);
const nameInput = ref(null);

const form = useForm({
    name: '',
    category: '',
    description: '',
    unit: props.units[0] || 'Nos',
    default_unit_price: '',
    classification: 'consumable',
    useful_life_years: '',
    opening_quantity: '',
    opening_unit_cost: '',
    is_active: true,
});

function basePath() {
    return props.project ? `/projects/${props.project.id}/inventory/materials` : '/inventory/materials';
}

function fill(material) {
    form.name = material?.name || '';
    form.category = material?.category || '';
    form.description = material?.description || '';
    form.unit = material?.unit || units.value[0] || 'Nos';
    form.default_unit_price = material?.default_unit_price ?? '';
    form.classification = material?.classification || 'consumable';
    form.useful_life_years = material?.useful_life_years ?? '';
    form.opening_quantity = '';
    form.opening_unit_cost = '';
    form.is_active = material ? material.is_active : true;
    form.clearErrors();
}

function create() {
    editing.value = null;
    fill(null);
    open.value = true;
    nextTick(() => nameInput.value?.focus());
}

function edit(material) {
    editing.value = material.id;
    fill(material);
    open.value = true;
    nextTick(() => nameInput.value?.focus());
}

function close() {
    open.value = false;
    editing.value = null;
    form.reset();
    form.unit = units.value[0] || 'Nos';
    form.classification = 'consumable';
    form.is_active = true;
    form.clearErrors();
}

function save() {
    const url = editing.value ? `${basePath()}/${editing.value}` : basePath();
    const method = editing.value ? form.put : form.post;

    method.call(form, url, { preserveScroll: true, onSuccess: () => close() });
}

function destroy(material) {
    if (material.can_delete === false) {
        window.alert(material.delete_blocked_reason || 'This material cannot be deleted because it has stock transactions. Mark it inactive instead.');
        return;
    }

    if (!confirm(`Delete ${material.material_code}?`)) {
        return;
    }

    router.delete(`${basePath()}/${material.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="central ? 'Central Store materials' : 'Material Master'" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="central ? 'Material Master' : 'Material Master'" :eyebrow="central ? 'Central Store' : project?.project_name">
                <ImportButton
                    :module="central ? 'central_store_stock' : 'materials'"
                    permission="inventory.import"
                    :return-url="basePath()"
                    :project-id="project?.id"
                />
                <button type="button" class="ui-btn-primary inline-flex items-center gap-2" @click="create">
                    <Plus class="h-4 w-4" />
                    Add material
                </button>
            </PageHeader>
        </template>

        <TableToolbar :search="filters.search" placeholder="Search code, name, category, unit, class, price…">
            <label class="text-sm"><span class="sr-only">Status</span>
                <select class="ui-input w-36" :value="filters.status || ''" @change="visitListing({ status: $event.target.value })"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
            </label>
            <label class="text-sm"><span class="sr-only">Classification</span>
                <select class="ui-input w-40" :value="filters.classification || ''" @change="visitListing({ classification: $event.target.value })"><option value="">All classes</option><option value="consumable">Consumable</option><option value="asset">Asset</option></select>
            </label>
        </TableToolbar>

        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table">
                <thead>
                    <tr><th>Code</th><th>Name</th><th>Category</th><th>Class</th><th>Unit</th><th class="text-right">Default unit price</th><th>Useful life</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    <tr v-if="materials.data.length === 0"><td colspan="9" class="py-8 text-center text-slate-400">{{ filters.search || filters.status || filters.classification ? 'No matching records found.' : 'No materials.' }}</td></tr>
                    <tr v-for="material in materials.data" :key="material.id">
                        <td>{{ material.material_code }}</td>
                        <td>{{ material.name }}</td>
                        <td>{{ material.category || '—' }}</td>
                        <td>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="material.classification === 'asset' ? 'bg-indigo-50 text-indigo-800' : 'bg-slate-100 text-slate-700'">
                                {{ material.classification === 'asset' ? 'Asset' : 'Consumable' }}
                            </span>
                        </td>
                        <td>{{ material.unit }}</td>
                        <td class="text-right tabular-nums">{{ material.default_unit_price != null && material.default_unit_price !== '' ? formatInr(material.default_unit_price) : '—' }}</td>
                        <td>{{ material.classification === 'asset' && material.useful_life_years ? `${material.useful_life_years} yr` : '—' }}</td>
                        <td>{{ material.is_active ? 'Active' : 'Inactive' }}</td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                <TableActionIcon action="edit" @click="edit(material)" />
                                <TableActionIcon
                                    action="delete"
                                    :disabled="material.can_delete === false"
                                    :label="material.can_delete === false ? (material.delete_blocked_reason || 'Cannot delete') : 'Delete'"
                                    @click="destroy(material)"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="materials" label="materials" />

        <Modal :show="open" max-width="2xl" @close="close">
            <form class="p-5" @submit.prevent="save">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">{{ editing ? 'Edit material' : 'Add material' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ editing ? 'Update this material master record.' : 'Create a material. Stock is added separately unless you enter an opening quantity.' }}</p>
                    </div>
                    <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="Close" @click="close">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm"><span class="mb-1 block font-medium">Material name</span><input ref="nameInput" v-model="form.name" required class="ui-input w-full"></label>
                    <label class="text-sm"><span class="mb-1 block font-medium">Category</span><input v-model="form.category" class="ui-input w-full"></label>
                    <label class="text-sm"><span class="mb-1 block font-medium">Unit</span>
                        <MaterialUnitSelect v-model="form.unit" :units="units" :can-create="canCreateUnit" :error="form.errors.unit" @units="units = $event" />
                    </label>
                    <label class="text-sm"><span class="mb-1 block font-medium">Default unit price</span>
                        <input v-model="form.default_unit_price" type="number" min="0" step="0.01" class="ui-input w-full" placeholder="0.00" :disabled="!canEditPrice">
                        <span class="mt-1 block text-xs text-slate-500">Reference price. It does not rewrite received or issued stock costs.</span>
                    </label>
                    <label class="text-sm"><span class="mb-1 block font-medium">Classification</span>
                        <select v-model="form.classification" class="ui-input w-full">
                            <option value="consumable">Consumable</option>
                            <option value="asset">Asset</option>
                        </select>
                    </label>
                    <label v-if="form.classification === 'asset'" class="text-sm"><span class="mb-1 block font-medium">Useful life (years)</span>
                        <input v-model="form.useful_life_years" type="number" min="1" max="100" step="1" required class="ui-input w-full" placeholder="e.g. 5">
                    </label>
                    <label v-if="central && !editing" class="text-sm"><span class="mb-1 block font-medium">Opening quantity</span>
                        <input v-model="form.opening_quantity" type="number" min="0.001" step="0.001" class="ui-input w-full" placeholder="Optional">
                        <span class="mt-1 block text-xs text-slate-500">Leave blank to add the material without creating stock.</span>
                    </label>
                    <label v-if="central && !editing" class="text-sm"><span class="mb-1 block font-medium">Opening unit cost</span>
                        <input v-model="form.opening_unit_cost" type="number" min="0" step="0.01" class="ui-input w-full" placeholder="Optional">
                        <span class="mt-1 block text-xs text-slate-500">Actual opening cost. Blank uses the default price as an estimate.</span>
                    </label>
                    <p v-if="central" class="text-sm text-slate-500 sm:col-span-2">Stock is recorded against the Central Store. A project is not required.</p>
                    <label class="text-sm sm:col-span-2"><span class="mb-1 block font-medium">Description</span><textarea v-model="form.description" rows="2" class="ui-input w-full"></textarea></label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox"> Active</label>
                </div>
                <p v-if="form.errors.name" class="mt-3 text-sm text-red-700">{{ form.errors.name }}</p>
                <p v-if="form.errors.opening_quantity" class="mt-3 text-sm text-red-700">{{ form.errors.opening_quantity }}</p>
                <p v-if="form.errors.default_unit_price" class="mt-3 text-sm text-red-700">{{ form.errors.default_unit_price }}</p>
                <p v-if="form.errors.classification" class="mt-3 text-sm text-red-700">{{ form.errors.classification }}</p>
                <p v-if="form.errors.useful_life_years" class="mt-3 text-sm text-red-700">{{ form.errors.useful_life_years }}</p>
                <p v-if="form.errors.opening_unit_cost" class="mt-3 text-sm text-red-700">{{ form.errors.opening_unit_cost }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button class="ui-btn-secondary" type="button" @click="close">Cancel</button>
                    <button class="ui-btn-primary" type="submit" :disabled="form.processing">{{ editing ? 'Update material' : 'Create material' }}</button>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
