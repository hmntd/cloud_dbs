<script setup>
import { reactive, ref } from 'vue';
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
    topPosts: { type: Array, required: true },
    activeUsers: { type: Array, required: true },
    actingUserId: { type: String, required: true },
});

const emit = defineEmits(['refresh', 'notify']);

const sessionForm = reactive({
    user_id: '',
    action: 'browsing feed',
    ttl_seconds: 900,
});

const loading = ref(false);

async function touchUserSession() {
    loading.value = true;
    try {
        const userId = sessionForm.user_id || props.actingUserId;
        await apiRequest('/api/redis/active-users', {
            method: 'POST',
            body: JSON.stringify({
                user_id: userId,
                action: sessionForm.action,
                ttl_seconds: sessionForm.ttl_seconds,
            }),
        });
        emit('notify', { severity: 'success', text: 'Temporary active user state stored in Redis with TTL.' });
        emit('refresh');
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function incrementLike(postId) {
    try {
        await apiRequest(`/api/posts/${postId}/like`, {
            method: 'POST',
            body: JSON.stringify({ user_id: props.actingUserId }),
        });
        emit('refresh');
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    }
}

async function incrementView(postId) {
    try {
        await apiRequest(`/api/posts/${postId}/view`, {
            method: 'POST',
            body: JSON.stringify({ user_id: props.actingUserId }),
        });
        emit('refresh');
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Card class="border border-slate-200 shadow-none">
            <template #title>
                <span class="text-base font-semibold">Redis Top 10 Posts by Popularity (ZSET Leaderboard & Atomic
                    Counters)</span>
            </template>
            <template #subtitle>
                <span class="text-xs text-slate-500">
                    Popularity score = (Likes × 3) + (Comments × 2) + (Views × 1) stored in Redis Sorted Set
                    <code>social:posts:popularity</code>
                </span>
            </template>
            <template #content>
                <DataTable :value="topPosts" size="small" stripedRows>
                    <Column field="rank" header="Rank">
                        <template #body="{ data }">
                            <Tag :value="`#${data.rank}`" severity="contrast" />
                        </template>
                    </Column>
                    <Column field="title" header="Post Title" />
                    <Column header="Author">
                        <template #body="{ data }">@{{ data.author.username }}</template>
                    </Column>
                    <Column field="likes_count" header="Likes Counter" />
                    <Column field="views_count" header="Views Counter" />
                    <Column field="comments_count" header="Comments" />
                    <Column field="popularity_score" header="Popularity Score">
                        <template #body="{ data }">
                            <Tag :value="String(data.popularity_score)" severity="success" />
                        </template>
                    </Column>
                    <Column header="Quick Actions">
                        <template #body="{ data }">
                            <div class="flex gap-2">
                                <Button label="+1 Like" icon="pi pi-heart" size="small" outlined
                                    @click="incrementLike(data.id)" />
                                <Button label="+1 View" icon="pi pi-eye" size="small" severity="secondary" outlined
                                    @click="incrementView(data.id)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card class="border border-slate-200 shadow-none">
                <template #title>
                    <span class="text-base font-semibold">Set Temporary Active User (TTL)</span>
                </template>
                <template #content>
                    <form class="flex flex-col gap-3" @submit.prevent="touchUserSession">
                        <Select v-model="sessionForm.user_id" :options="users" optionLabel="full_name" optionValue="id"
                            placeholder="Select User (defaults to Active User)" />
                        <InputText v-model="sessionForm.action" placeholder="Current activity (e.g. reading feed)"
                            required />
                        <InputNumber v-model="sessionForm.ttl_seconds" :min="30" :max="86400" suffix=" sec TTL" />
                        <div>
                            <Button type="submit" label="Update Redis Presence" icon="pi pi-bolt" size="small"
                                :loading="loading" />
                        </div>
                    </form>
                </template>
            </Card>

            <Card class="border border-slate-200 shadow-none lg:col-span-2">
                <template #title>
                    <span class="text-base font-semibold">Active Users in Redis (Expiring Keys with Live TTL)</span>
                </template>
                <template #content>
                    <DataTable :value="activeUsers" size="small" stripedRows>
                        <Column field="username" header="User">
                            <template #body="{ data }">
                                <span class="font-medium">{{ data.full_name }}</span> (@{{ data.username }})
                            </template>
                        </Column>
                        <Column field="last_action" header="Last Activity" />
                        <Column field="ttl_seconds" header="Remaining TTL">
                            <template #body="{ data }">
                                <Tag :value="`${data.ttl_seconds}s`" severity="info" />
                            </template>
                        </Column>
                        <Column field="updated_at" header="Updated At">
                            <template #body="{ data }">
                                {{ new Date(data.updated_at).toLocaleTimeString() }}
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>
    </div>
</template>
