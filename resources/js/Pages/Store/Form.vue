<script setup>
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import ImageUploadField from '../../Components/ImageUploadField.vue';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    project: { type: Object, required: true },
    receipt: { type: Object, default: null },
    purchaseOrders: { type: Array, required: true },
    vendors: { type: Array, required: true },
    selectedOrder: { type: Object, default: null },
});

function blankLine() {
    return { purchase_order_item_id: '', description: '', unit: '', quantity_received: '', remarks: '' };
}

const form = useForm({
    receipt_date: props.receipt?.receipt_date || new Date().toISOString().slice(0, 10),
    purchase_order_id: props.receipt?.purchase_order_id || props.selectedOrder?.id || '',
    vendor_id: props.receipt?.vendor_id || props.selectedOrder?.vendor_id || '',
    received_by: props.receipt?.received_by || '',
    remarks: props.receipt?.remarks || '',
    items: props.receipt?.items?.length
        ? props.receipt.items
        : (props.selectedOrder?.items?.map((item) => ({
            purchase_order_item_id: item.id,
            description: item.description,
            unit: item.unit || '',
            quantity_received: '',
            remarks: '',
        })) || [blankLine()]),
    images: [],
});

function chooseOrder(event) {
    const id = event.target.value;
    router.get(`/projects/${props.project.id}/store/create`, id ? { purchase_order_id: id } : {}, { preserveState: false });
}

const imageError = computed(() => form.errors.images || form.errors['images.0'] || '');

function submit() {
    if (props.receipt?.id) {
        form.put(`/projects/${props.project.id}/store/${props.receipt.id}`, { forceFormData: true });
        return;
    }

    form.post(`/projects/${props.project.id}/store`, { forceFormData: true });
}
</script>

<template>
    <Head title="Receive Material" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Receive Material" :eyebrow="project.project_name" />
        </template>
        <form class="space-y-4" @submit.prevent="submit">
            <div class="ui-card grid gap-4 p-5 md:grid-cols-2">
                <p class="text-sm font-medium md:col-span-2">Receipt Type: {{ form.purchase_order_id ? 'PO Linked' : 'Manual' }}</p>
                <label class="text-sm">Receipt Date<input v-model="form.receipt_date" type="date" required class="ui-input"></label>
                <label class="text-sm">Received By<input v-model="form.received_by" required class="ui-input"></label>
                <label class="text-sm">Purchase Order
                    <select :value="form.purchase_order_id" class="ui-input" @change="chooseOrder"><option value="">Manual receipt</option><option v-for="order in purchaseOrders" :key="order.id" :value="order.id">{{ order.po_number }} · {{ order.vendor }}</option></select>
                </label>
                <label class="text-sm">Vendor
                    <select v-model="form.vendor_id" class="ui-input" :disabled="!!form.purchase_order_id"><option value="">Optional</option><option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option></select>
                </label>
                <label class="text-sm md:col-span-2">Remarks<textarea v-model="form.remarks" rows="2" class="ui-textarea"></textarea></label>
                <div class="md:col-span-2">
                    <ImageUploadField
                        v-model="form.images"
                        multiple
                        label="Material / Delivery Photos"
                        button="+ Add Photos"
                        hint="JPG, PNG, WEBP"
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        :current-images="receipt?.images || []"
                        :error="imageError"
                    />
                </div>
            </div>
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead><tr><th>Description</th><th>Unit</th><th>Quantity Received</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <tr v-for="(line, index) in form.items" :key="index">
                            <td><input v-model="line.description" class="cell-input min-w-[14rem]" :readonly="!!line.purchase_order_item_id"></td>
                            <td><input v-model="line.unit" class="cell-input unit-input" :readonly="!!line.purchase_order_item_id"></td>
                            <td><input v-model="line.quantity_received" type="number" min="0" step="0.001" class="cell-input num-input"></td>
                            <td><input v-model="line.remarks" class="cell-input"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="form.errors.items" class="text-sm text-red-700">{{ form.errors.items }}</p>
            <div class="flex gap-3">
                <button v-if="!form.purchase_order_id" type="button" class="ui-btn-secondary" @click="form.items.push(blankLine())">Add line</button>
                <button class="ui-btn-primary" :disabled="form.processing">Save Receipt</button>
            </div>
        </form>
    </AdminLayout>
</template>
