<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import ImportButton from '../../../Components/ImportButton.vue';
import PageHeader from '../../../Components/PageHeader.vue';
import StatusBadge from '../../../Components/StatusBadge.vue';
import TablePagination from '../../../Components/TablePagination.vue';
import TableToolbar from '../../../Components/TableToolbar.vue';
import { visitListing } from '../../../lib/listing';

const props = defineProps({
    project: { type: Object, default: null },
    transfers: { type: Object, required: true },
    filters: { type: Object, required: true },
    locations: { type: Array, required: true },
    users: { type: Array, required: true },
});

const filterKeys = ['status', 'source_location_id', 'destination_location_id', 'from', 'to', 'requested_by'];
const filtered = () => Boolean(props.filters.search) || filterKeys.some((key) => props.filters[key]);

function apply(event) {
    const data = Object.fromEntries(new FormData(event.target));
    visitListing(Object.fromEntries(filterKeys.map((key) => [key, data[key] || ''])));
}
</script>

<template>
    <Head title="Internal Delivery Notes" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader title="Internal Delivery Notes" :eyebrow="project?.project_name || 'Inventory'">
                <ImportButton module="idn" permission="idn.import" :return-url="project ? `/projects/${project.id}/inventory/transfers` : '/inventory/transfers'" :project-id="project?.id" />
                <Link href="/inventory/transfers/create" class="ui-btn-primary">New transfer</Link>
            </PageHeader>
        </template>
        <TableToolbar :search="filters.search" placeholder="Search IDN, date, location, project, people, status…" />
        <form class="mb-4 grid gap-3 md:grid-cols-4" @submit.prevent="apply">
            <select name="status" class="ui-input">
                <option value="">All statuses</option>
                <option v-for="status in ['draft', 'submitted', 'approved', 'dispatched', 'partially_received', 'received', 'rejected', 'cancelled']" :key="status" :value="status" :selected="filters.status === status">{{ status }}</option>
            </select>
            <select name="source_location_id" class="ui-input">
                <option value="">Source</option>
                <option v-for="location in locations" :key="`s${location.id}`" :value="location.id" :selected="String(filters.source_location_id || '') === String(location.id)">{{ location.name }}</option>
            </select>
            <select name="destination_location_id" class="ui-input">
                <option value="">Destination</option>
                <option v-for="location in locations" :key="`d${location.id}`" :value="location.id" :selected="String(filters.destination_location_id || '') === String(location.id)">{{ location.name }}</option>
            </select>
            <input name="from" type="date" :value="filters.from || ''" class="ui-input">
            <input name="to" type="date" :value="filters.to || ''" class="ui-input">
            <select name="requested_by" class="ui-input">
                <option value="">Created by</option>
                <option v-for="user in users" :key="user.id" :value="user.id" :selected="String(filters.requested_by || '') === String(user.id)">{{ user.name }}</option>
            </select>
            <button class="ui-btn-secondary">Filter</button>
        </form>
        <div class="ui-table-wrap overflow-x-auto">
            <table class="ui-table min-w-[960px]">
                <thead>
                    <tr>
                        <th>IDN</th>
                        <th>Date</th>
                        <th>Source</th>
                        <th>Destination</th>
                        <th>Projects</th>
                        <th>Items</th>
                        <th>Qty</th>
                        <th>Requested</th>
                        <th>Dispatched</th>
                        <th>Received</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="transfers.data.length === 0"><td colspan="11" class="py-8 text-center text-slate-400">{{ filtered() ? 'No matching records found.' : 'No internal delivery notes yet.' }}</td></tr>
                    <tr v-for="row in transfers.data" :key="row.id">
                        <td><Link class="font-medium" :href="`/inventory/transfers/${row.id}`">{{ row.idn_number }}</Link></td>
                        <td>{{ row.transfer_date }}</td>
                        <td>{{ row.source }}</td>
                        <td>{{ row.destination }}</td>
                        <td>{{ row.source_project || '—' }} → {{ row.destination_project || '—' }}</td>
                        <td>{{ row.items }}</td>
                        <td>{{ row.quantity }}</td>
                        <td>{{ row.requested_by || '—' }}</td>
                        <td>{{ row.dispatched_by || '—' }}</td>
                        <td>{{ row.received_by || '—' }}</td>
                        <td><StatusBadge :status="row.status" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <TablePagination :paginator="transfers" label="transfers" />
    </AdminLayout>
</template>
