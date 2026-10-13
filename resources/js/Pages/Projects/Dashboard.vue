<script setup>
import { Head, router } from '@inertiajs/vue3';
import { IndianRupee, Wallet } from '@lucide/vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import EmptyState from '../../Components/EmptyState.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatCard from '../../Components/StatCard.vue';
import { formatInr, formatQty } from '../../lib/money';

const props = defineProps({
    project: { type: Object, required: true },
    boqs: { type: Array, required: true },
});

function openBoq(boqId) {
    router.visit(`/projects/${props.project.id}/boqs/${boqId}`);
}
</script>

<template>
    <Head :title="`${project.project_code} Dashboard`" />
    <AdminLayout :project="project">
        <template #header>
            <PageHeader :title="project.project_name" :eyebrow="project.project_code" />
        </template>

        <div class="grid gap-4 sm:grid-cols-2">
            <StatCard label="BOQ Total" :value="formatInr(project.boq_total)" tone="mint">
                <template #icon><IndianRupee class="h-4 w-4" /></template>
            </StatCard>
            <StatCard label="Budget Value" :value="formatInr(project.budget_total)" tone="beige">
                <template #icon><Wallet class="h-4 w-4" /></template>
            </StatCard>
        </div>

        <div v-if="boqs.length === 0" class="mt-6">
            <EmptyState title="No BOQ items yet." />
        </div>

        <section v-for="boq in boqs" :key="boq.id" class="mt-6">
            <h2 class="mb-3 text-sm font-semibold text-slate-900">{{ boq.boq_number }} · {{ boq.title }}</h2>
            <div class="ui-table-wrap">
                <EmptyState v-if="boq.items.length === 0" title="No items in this BOQ." />
                <table v-else class="ui-table">
                    <thead>
                        <tr>
                            <th>Sr. No.</th>
                            <th>Service Code</th>
                            <th>Item Description</th>
                            <th>Unit</th>
                            <th class="text-right">B.O.Q.</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">BOQ Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in boq.items"
                            :key="item.id"
                            class="cursor-pointer"
                            @click="openBoq(boq.id)"
                        >
                            <td>{{ item.item_number || '—' }}</td>
                            <td>{{ item.service_code || '—' }}</td>
                            <td class="font-medium text-slate-900">{{ item.description }}</td>
                            <td>{{ item.unit || '—' }}</td>
                            <td class="text-right tabular-nums">{{ item.is_section ? '—' : formatQty(item.quantity) }}</td>
                            <td class="text-right tabular-nums">{{ item.is_section ? '—' : formatInr(item.rate) }}</td>
                            <td class="text-right tabular-nums">{{ item.is_section ? '—' : formatInr(item.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
