<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DocumentPdfActions from '../../../Components/DocumentPdfActions.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import TableActionIcon from '../../../Components/TableActionIcon.vue';
import { formatInr } from '../../../lib/money';

const props = defineProps({
    transfer: { type: Object, required: true },
    abilities: { type: Object, required: true },
});

const reason = useForm({ reason: '' });
const receipt = useForm({
    remarks: '',
    items: props.transfer.items.map((item) => ({
        item_id: item.id,
        quantity_received: '',
        quantity_damaged: '',
        quantity_short: '',
        remarks: '',
    })),
    images: [],
});

function post(action) {
    reason.post(`/inventory/transfers/${props.transfer.id}/${action}`);
}

function receive() {
    receipt.post(`/inventory/transfers/${props.transfer.id}/receive`, { forceFormData: true });
}
</script>

<template>
    <Head :title="transfer.idn_number" />
    <AdminLayout>
        <template #header>
            <PageHeader :title="transfer.idn_number" eyebrow="Internal Delivery Note">
                <DocumentPdfActions v-if="abilities.print" :view-url="`/inventory/transfers/${transfer.id}/pdf`" :download-url="`/inventory/transfers/${transfer.id}/pdf/download`" />
                <TableActionIcon v-if="abilities.edit" action="edit" label="Edit draft" :href="`/inventory/transfers/${transfer.id}/edit`" />
                <StatusBadge :status="transfer.status" />
            </PageHeader>
        </template>
        <section class="ui-card mb-4 grid gap-2 p-5 text-sm md:grid-cols-2">
            <p>Date: {{ transfer.transfer_date }}</p>
            <p>Source: {{ transfer.source }} <span v-if="transfer.source_project">({{ transfer.source_project }})</span></p>
            <p>Destination: {{ transfer.destination }} <span v-if="transfer.destination_project">({{ transfer.destination_project }})</span></p>
            <p>Requested by: {{ transfer.requested_by || '—' }}</p>
            <p v-if="transfer.approved_by">Approved by {{ transfer.approved_by }} on {{ transfer.approved_at }}</p>
            <p v-if="transfer.dispatched_by">Dispatched by {{ transfer.dispatched_by }} on {{ transfer.dispatched_at }}</p>
            <p v-if="transfer.received_by">Received by {{ transfer.received_by }} on {{ transfer.received_at }}</p>
            <p v-if="transfer.vehicle_number">Vehicle: {{ transfer.vehicle_number }}</p>
            <p v-if="transfer.driver_name">Driver: {{ transfer.driver_name }}</p>
            <p v-if="transfer.remarks" class="md:col-span-2">{{ transfer.remarks }}</p>
        </section>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[800px]">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Material</th>
                        <th>Unit</th>
                        <th>Dispatched</th>
                        <th class="text-right">Unit cost</th>
                        <th class="text-right">Amount</th>
                        <th>Accepted</th>
                        <th>Damaged</th>
                        <th>Short</th>
                        <th>In transit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in transfer.items" :key="item.id">
                        <td>{{ item.code }}</td>
                        <td>{{ item.name }}</td>
                        <td>{{ item.unit }}</td>
                        <td>{{ item.quantity }}</td>
                        <td class="text-right tabular-nums">{{ item.unit_cost != null && item.unit_cost !== '' ? formatInr(item.unit_cost) : '—' }}</td>
                        <td class="text-right tabular-nums">{{ item.amount != null && item.amount !== '' ? formatInr(item.amount) : '—' }}</td>
                        <td>{{ item.accepted }}</td>
                        <td>{{ item.damaged }}</td>
                        <td>{{ item.short }}</td>
                        <td>{{ item.remaining }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="abilities.submit" type="button" class="ui-btn-primary" @click="post('submit')">Submit</button>
            <button v-if="abilities.approve" type="button" class="ui-btn-primary" @click="post('approve')">Approve</button>
            <button v-if="abilities.dispatch" type="button" class="ui-btn-primary" @click="post('dispatch')">Dispatch</button>
        </div>
        <p v-if="reason.errors.items || reason.errors.quantity || reason.errors.stock" class="mt-2 text-sm text-red-700">
            {{ reason.errors.items || reason.errors.quantity || reason.errors.stock }}
        </p>
        <form v-if="abilities.reject || abilities.cancel" class="ui-card mt-4 space-y-3 p-4" @submit.prevent="post(abilities.reject ? 'reject' : 'cancel')">
            <textarea v-model="reason.reason" required placeholder="Reason" class="ui-input w-full"></textarea>
            <button v-if="abilities.reject" class="ui-btn-secondary">Reject</button>
            <button v-if="abilities.cancel" class="ui-btn-secondary" type="button" @click="post('cancel')">Cancel transfer</button>
        </form>
        <form v-if="abilities.receive" class="ui-card mt-4 space-y-3 p-4" @submit.prevent="receive">
            <h2 class="font-semibold">Destination receipt</h2>
            <div v-for="(row, index) in receipt.items" :key="row.item_id" class="grid gap-2 md:grid-cols-4">
                <p class="text-sm md:col-span-4">{{ transfer.items[index].name }} — in transit {{ transfer.items[index].remaining }}</p>
                <input v-model="row.quantity_received" type="number" min="0" step="0.001" placeholder="Received" class="ui-input">
                <input v-model="row.quantity_damaged" type="number" min="0" step="0.001" placeholder="Damaged" class="ui-input">
                <input v-model="row.quantity_short" type="number" min="0" step="0.001" placeholder="Short" class="ui-input">
                <input v-model="row.remarks" placeholder="Remarks" class="ui-input">
            </div>
            <input type="file" accept="image/*" multiple @change="receipt.images = Array.from($event.target.files || [])">
            <button class="ui-btn-primary" :disabled="receipt.processing">Confirm receipt</button>
            <p v-if="receipt.errors.items || receipt.errors.quantity || receipt.errors.stock" class="text-sm text-red-700">
                {{ receipt.errors.items || receipt.errors.quantity || receipt.errors.stock }}
            </p>
        </form>
    </AdminLayout>
</template>
