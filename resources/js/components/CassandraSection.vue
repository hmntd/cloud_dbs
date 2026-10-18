<script setup>
import { reactive, ref, watch } from 'vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { apiRequest } from '../api';

const props = defineProps({
    users: { type: Array, required: true },
    recentEvents: { type: Array, required: true },
    initialUserEvents: { type: Array, required: true },
    defaultUserId: { type: String, default: '' },
});

const emit = defineEmits(['notify']);

const filterForm = reactive({
    user_id: props.defaultUserId,
    date_from: '',
    date_to: '',
    limit: 20,
});

const displayedEvents = ref(props.initialUserEvents.length ? props.initialUserEvents : props.recentEvents);
const loading = ref(false);

watch(
    () => [props.initialUserEvents, props.recentEvents, props.defaultUserId],
    () => {
        if (!filterForm.user_id) {
            filterForm.user_id = props.defaultUserId;
        }
        displayedEvents.value = filterForm.user_id ? props.initialUserEvents : props.recentEvents;
    },
);

async function queryEvents() {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (filterForm.user_id) params.set('user_id', filterForm.user_id);
        if (filterForm.date_from) params.set('date_from', filterForm.date_from);
        if (filterForm.date_to) params.set('date_to', filterForm.date_to);
        if (filterForm.limit) params.set('limit', String(filterForm.limit));

        const data = await apiRequest(`/api/cassandra/events?${params.toString()}`);
        displayedEvents.value = data.events;
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function showGlobalLast20() {
    Object.assign(filterForm, {
        user_id: '',
        date_from: '',
        date_to: '',
        limit: 20,
    });
    await queryEvents();
}

function eventSeverity(type) {
    switch (type) {
        case 'post':
            return 'success';
        case 'like':
            return 'danger';
        case 'comment':
            return 'info';
        case 'view':
            return 'secondary';
        default:
            return 'contrast';
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Card class="border border-slate-200 shadow-none">
            <template #title>
                <span class="text-base font-semibold">Cassandra Event History (Posts, Likes, Comments & Views)</span>
            </template>
            <template #subtitle>
                <span class="text-xs text-slate-500">
                    Partitioned by <code>user_id</code> and clustered by <code>occurred_at DESC</code> for period range
                    queries and last 20 events.
                </span>
            </template>
            <template #content>
                <form class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-4" @submit.prevent="queryEvents">
                    <Select v-model="filterForm.user_id"
                        :options="[{ id: '', full_name: 'All Users (Global Last 20)' }, ...users]"
                        optionLabel="full_name" optionValue="id" placeholder="Select User" />
                    <InputText v-model="filterForm.date_from" type="date" placeholder="From Date" />
                    <InputText v-model="filterForm.date_to" type="date" placeholder="To Date" />
                    <InputNumber v-model="filterForm.limit" :min="1" :max="100" suffix=" events" />
                    <div class="flex gap-2">
                        <Button type="submit" label="Filter History" icon="pi pi-filter" size="small"
                            :loading="loading" />
                        <Button type="button" label="Last 20 Global" severity="secondary" outlined size="small"
                            @click="showGlobalLast20" />
                    </div>
                </form>

                <DataTable :value="displayedEvents" size="small" stripedRows
                    emptyMessage="No activity events found for the selected filter.">
                    <Column field="occurred_at" header="Timestamp">
                        <template #body="{ data }">
                            <span class="text-xs font-mono">{{ new Date(data.occurred_at).toLocaleString() }}</span>
                        </template>
                    </Column>
                    <Column field="event_type" header="Event Type">
                        <template #body="{ data }">
                            <Tag :value="data.event_type.toUpperCase()" :severity="eventSeverity(data.event_type)" />
                        </template>
                    </Column>
                    <Column field="username" header="User">
                        <template #body="{ data }">@{{ data.username }}</template>
                    </Column>
                    <Column field="description" header="Activity Description" />
                    <Column field="target_id" header="Target ID">
                        <template #body="{ data }">
                            <code class="text-xs bg-slate-100 px-1.5 py-0.5 rounded">{{ data.target_id }}</code>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
