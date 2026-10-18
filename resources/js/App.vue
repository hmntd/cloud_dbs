<script setup>
import { onMounted, ref } from 'vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import { apiRequest } from './api';
import CassandraSection from './components/CassandraSection.vue';
import MongoSection from './components/MongoSection.vue';
import Neo4jSection from './components/Neo4jSection.vue';
import RedisSection from './components/RedisSection.vue';

const activeTab = ref('mongodb');
const loading = ref(false);
const notification = ref(null);
const actingUserId = ref('');

const state = ref({
    mongodb: {
        users: [],
        posts: [],
        most_liked_users: [],
    },
    redis: {
        top_posts: [],
        active_users: [],
    },
    neo4j: {
        subscriptions: [],
        mutual_friends: [],
        recommendations: [],
        default_user_a_id: '',
        default_user_b_id: '',
        default_recommend_user_id: '',
    },
    cassandra: {
        recent_events: [],
        user_events: [],
        default_user_id: '',
    },
});

function showNotice(notice) {
    notification.value = notice;
    setTimeout(() => {
        if (notification.value === notice) {
            notification.value = null;
        }
    }, 4000);
}

async function fetchOverview() {
    loading.value = true;
    try {
        const data = await apiRequest('/api/overview');
        state.value = data;
        if (!actingUserId.value && data.mongodb.users.length > 0) {
            actingUserId.value = data.mongodb.users[0].id;
        }
    } catch (e) {
        showNotice({ severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function resetAndSeed() {
    loading.value = true;
    try {
        const data = await apiRequest('/api/seed', { method: 'POST' });
        state.value = data;
        if (data.mongodb.users.length > 0) {
            actingUserId.value = data.mongodb.users[0].id;
        }
        showNotice({ severity: 'success', text: 'Demo data reset and seeded across MongoDB, Redis, Neo4j & Cassandra.' });
    } catch (e) {
        showNotice({ severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function resetDataOnly() {
    loading.value = true;
    try {
        const data = await apiRequest('/api/reset', { method: 'POST' });
        state.value = data;
        actingUserId.value = '';
        showNotice({ severity: 'info', text: 'All data has been reset across MongoDB, Redis, Neo4j & Cassandra.' });
    } catch (e) {
        showNotice({ severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

function updateMongoPosts(posts) {
    state.value.mongodb.posts = posts;
}

onMounted(fetchOverview);
</script>

<template>
    <div class="max-w-7xl mx-auto px-4 py-6 flex flex-col gap-6">
        <header class="bg-white border border-slate-200 rounded-lg p-4 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-lg font-semibold text-slate-900">Social Network Polyglot Data Model</h1>
                <p class="text-xs text-slate-500">
                    Laravel 13 · PHP 8.5 · MongoDB · Redis · Neo4j · Cassandra
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2 text-xs text-slate-600">
                    <span class="font-medium">Acting User:</span>
                    <Select
                        v-model="actingUserId"
                        :options="state.mongodb.users"
                        optionLabel="full_name"
                        optionValue="id"
                        placeholder="Select User"
                        size="small"
                    />
                </div>
                <Button
                    label="Refresh"
                    icon="pi pi-refresh"
                    size="small"
                    severity="secondary"
                    outlined
                    :loading="loading"
                    @click="fetchOverview"
                />
                <Button
                    label="Reset Data"
                    icon="pi pi-trash"
                    size="small"
                    severity="danger"
                    outlined
                    :loading="loading"
                    @click="resetDataOnly"
                />
                <Button
                    label="Reset & Seed Demo Data"
                    icon="pi pi-database"
                    size="small"
                    severity="secondary"
                    :loading="loading"
                    @click="resetAndSeed"
                />
            </div>
        </header>

        <Message v-if="notification" :severity="notification.severity" :closable="false">
            {{ notification.text }}
        </Message>

        <Tabs v-model:value="activeTab">
            <TabList>
                <Tab value="mongodb">1. MongoDB (Profiles, Posts, Comments & Aggregation)</Tab>
                <Tab value="redis">2. Redis (Top 10 Popularity, Counters & Active Users)</Tab>
                <Tab value="neo4j">3. Neo4j (Graph Subscriptions, Mutuals & Recommendations)</Tab>
                <Tab value="cassandra">4. Cassandra (Activity Event History & Last 20)</Tab>
            </TabList>

            <TabPanels class="px-0 pt-4 bg-transparent">
                <TabPanel value="mongodb">
                    <MongoSection
                        :users="state.mongodb.users"
                        :posts="state.mongodb.posts"
                        :most-liked-users="state.mongodb.most_liked_users"
                        :acting-user-id="actingUserId"
                        @refresh="fetchOverview"
                        @posts-updated="updateMongoPosts"
                        @notify="showNotice"
                    />
                </TabPanel>

                <TabPanel value="redis">
                    <RedisSection
                        :users="state.mongodb.users"
                        :top-posts="state.redis.top_posts"
                        :active-users="state.redis.active_users"
                        :acting-user-id="actingUserId"
                        @refresh="fetchOverview"
                        @notify="showNotice"
                    />
                </TabPanel>

                <TabPanel value="neo4j">
                    <Neo4jSection
                        :users="state.mongodb.users"
                        :subscriptions="state.neo4j.subscriptions"
                        :initial-mutual-friends="state.neo4j.mutual_friends"
                        :initial-recommendations="state.neo4j.recommendations"
                        :default-user-a-id="state.neo4j.default_user_a_id"
                        :default-user-b-id="state.neo4j.default_user_b_id"
                        :default-recommend-user-id="state.neo4j.default_recommend_user_id"
                        @refresh="fetchOverview"
                        @notify="showNotice"
                    />
                </TabPanel>

                <TabPanel value="cassandra">
                    <CassandraSection
                        :users="state.mongodb.users"
                        :recent-events="state.cassandra.recent_events"
                        :initial-user-events="state.cassandra.user_events"
                        :default-user-id="state.cassandra.default_user_id"
                        @notify="showNotice"
                    />
                </TabPanel>
            </TabPanels>
        </Tabs>
    </div>
</template>

