<script setup>
import { reactive, ref, watch } from 'vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { apiRequest } from '../api';

const props = defineProps({
    users: { type: Array, required: true },
    subscriptions: { type: Array, required: true },
    initialMutualFriends: { type: Array, required: true },
    initialRecommendations: { type: Array, required: true },
    defaultUserAId: { type: String, default: '' },
    defaultUserBId: { type: String, default: '' },
    defaultRecommendUserId: { type: String, default: '' },
});

const emit = defineEmits(['refresh', 'notify']);

const followForm = reactive({
    follower_id: '',
    followee_id: '',
});

const mutualForm = reactive({
    user_a_id: props.defaultUserAId,
    user_b_id: props.defaultUserBId,
});

const recommendForm = reactive({
    user_id: props.defaultRecommendUserId,
});

const mutualFriends = ref(props.initialMutualFriends);
const recommendations = ref(props.initialRecommendations);
const loading = ref(false);

watch(
    () => [props.initialMutualFriends, props.initialRecommendations, props.defaultUserAId, props.defaultUserBId, props.defaultRecommendUserId],
    () => {
        mutualFriends.value = props.initialMutualFriends;
        recommendations.value = props.initialRecommendations;
        if (!mutualForm.user_a_id) mutualForm.user_a_id = props.defaultUserAId;
        if (!mutualForm.user_b_id) mutualForm.user_b_id = props.defaultUserBId;
        if (!recommendForm.user_id) recommendForm.user_id = props.defaultRecommendUserId;
    },
);

async function submitFollow() {
    if (!followForm.follower_id || !followForm.followee_id) return;
    loading.value = true;
    try {
        await apiRequest('/api/neo4j/subscriptions', {
            method: 'POST',
            body: JSON.stringify(followForm),
        });
        emit('notify', { severity: 'success', text: 'FOLLOWS relationship created in Neo4j graph.' });
        emit('refresh');
        if (mutualForm.user_a_id && mutualForm.user_b_id) {
            await fetchMutualFriends();
        }
        if (recommendForm.user_id) {
            await fetchRecommendations();
        }
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function quickFollow(followerId, followeeId) {
    followForm.follower_id = followerId;
    followForm.followee_id = followeeId;
    await submitFollow();
}

async function fetchMutualFriends() {
    if (!mutualForm.user_a_id || !mutualForm.user_b_id) return;
    try {
        const params = new URLSearchParams(mutualForm);
        const data = await apiRequest(`/api/neo4j/mutual-friends?${params.toString()}`);
        mutualFriends.value = data.mutual_friends;
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    }
}

async function fetchRecommendations() {
    if (!recommendForm.user_id) return;
    try {
        const params = new URLSearchParams(recommendForm);
        const data = await apiRequest(`/api/neo4j/recommendations?${params.toString()}`);
        recommendations.value = data.recommendations;
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card class="border border-slate-200 shadow-none">
                <template #title>
                    <span class="text-base font-semibold">Add Subscription Edge (FOLLOWS)</span>
                </template>
                <template #content>
                    <form class="flex flex-col gap-3" @submit.prevent="submitFollow">
                        <Select v-model="followForm.follower_id" :options="users" optionLabel="full_name"
                            optionValue="id" placeholder="Follower User" />
                        <Select v-model="followForm.followee_id" :options="users" optionLabel="full_name"
                            optionValue="id" placeholder="Followee User (Target)" />
                        <div>
                            <Button type="submit" label="Create FOLLOWS Edge" icon="pi pi-share-alt" size="small"
                                :loading="loading" />
                        </div>
                    </form>
                </template>
            </Card>

            <Card class="border border-slate-200 shadow-none">
                <template #title>
                    <span class="text-base font-semibold">Mutual Friends of Two Users</span>
                </template>
                <template #content>
                    <form class="flex flex-col gap-3 mb-4" @submit.prevent="fetchMutualFriends">
                        <div class="grid grid-cols-2 gap-2">
                            <Select v-model="mutualForm.user_a_id" :options="users" optionLabel="full_name"
                                optionValue="id" placeholder="User A" />
                            <Select v-model="mutualForm.user_b_id" :options="users" optionLabel="full_name"
                                optionValue="id" placeholder="User B" />
                        </div>
                        <div>
                            <Button type="submit" label="Find Mutual Friends" icon="pi pi-users" size="small"
                                severity="secondary" />
                        </div>
                    </form>

                    <DataTable :value="mutualFriends" size="small" stripedRows
                        emptyMessage="No mutual subscriptions found.">
                        <Column field="username" header="Username">
                            <template #body="{ data }">@{{ data.username }}</template>
                        </Column>
                        <Column field="full_name" header="Full Name" />
                    </DataTable>
                </template>
            </Card>

            <Card class="border border-slate-200 shadow-none">
                <template #title>
                    <span class="text-base font-semibold">Subscription Recommendations</span>
                </template>
                <template #content>
                    <form class="flex gap-2 mb-4" @submit.prevent="fetchRecommendations">
                        <Select v-model="recommendForm.user_id" :options="users" optionLabel="full_name"
                            optionValue="id" placeholder="Select User" class="flex-1" />
                        <Button type="submit" label="Recommend" icon="pi pi-compass" size="small"
                            severity="secondary" />
                    </form>

                    <DataTable :value="recommendations" size="small" stripedRows
                        emptyMessage="No new recommendations for this user.">
                        <Column field="full_name" header="Recommended User">
                            <template #body="{ data }">
                                <div class="font-medium">{{ data.full_name }} (@{{ data.username }})</div>
                                <div class="text-xs text-slate-500">
                                    Followed by: {{(data.followed_by || []).map((u) => `@${u}`).join(', ')}}
                                </div>
                            </template>
                        </Column>
                        <Column field="mutual_count" header="Shared">
                            <template #body="{ data }">
                                <Tag :value="`${data.mutual_count} mutual`" severity="info" />
                            </template>
                        </Column>
                        <Column header="Action">
                            <template #body="{ data }">
                                <Button label="Follow" size="small" outlined
                                    @click="quickFollow(recommendForm.user_id, data.id)" />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>

        <Card class="border border-slate-200 shadow-none">
            <template #title>
                <span class="text-base font-semibold">Neo4j Graph Edges: (:User)-[:FOLLOWS]->(:User)</span>
            </template>
            <template #content>
                <DataTable :value="subscriptions" size="small" stripedRows>
                    <Column header="Follower">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.follower_name }}</span> (@{{ data.follower_username }})
                        </template>
                    </Column>
                    <Column header="Relationship">
                        <template #body>
                            <Tag value="[:FOLLOWS] →" severity="secondary" />
                        </template>
                    </Column>
                    <Column header="Followee">
                        <template #body="{ data }">
                            <span class="font-medium">{{ data.followee_name }}</span> (@{{ data.followee_username }})
                        </template>
                    </Column>
                    <Column field="created_at" header="Created At">
                        <template #body="{ data }">
                            {{ data.created_at ? new Date(data.created_at).toLocaleString() : '—' }}
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
