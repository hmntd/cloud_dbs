<script setup>
import { reactive, ref } from 'vue';
import Button from 'primevue/button';
import Card from 'primevue/card';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { apiRequest } from '../api';

const props = defineProps({
    users: { type: Array, required: true },
    posts: { type: Array, required: true },
    mostLikedUsers: { type: Array, required: true },
    actingUserId: { type: String, required: true },
});

const emit = defineEmits(['refresh', 'posts-updated', 'notify']);

const userForm = reactive({
    username: '',
    full_name: '',
    email: '',
    bio: '',
    location: '',
    website: '',
    interests: '',
});

const postForm = reactive({
    author_id: '',
    title: '',
    content: '',
    tags: '',
});

const searchForm = reactive({
    author_id: '',
    date_from: '',
    date_to: '',
    tag: '',
});

const commentDrafts = reactive({});
const loading = ref(false);

async function submitUser() {
    loading.value = true;
    try {
        await apiRequest('/api/users', {
            method: 'POST',
            body: JSON.stringify(userForm),
        });
        Object.assign(userForm, {
            username: '',
            full_name: '',
            email: '',
            bio: '',
            location: '',
            website: '',
            interests: '',
        });
        emit('notify', { severity: 'success', text: 'User profile saved in MongoDB, Neo4j & Redis.' });
        emit('refresh');
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function submitPost() {
    loading.value = true;
    try {
        const authorId = postForm.author_id || props.actingUserId;
        await apiRequest('/api/posts', {
            method: 'POST',
            body: JSON.stringify({
                ...postForm,
                author_id: authorId,
            }),
        });
        Object.assign(postForm, { author_id: '', title: '', content: '', tags: '' });
        emit('notify', { severity: 'success', text: 'Post created in MongoDB, ranked in Redis & logged in Cassandra.' });
        emit('refresh');
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function runSearch() {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (searchForm.author_id) params.set('author_id', searchForm.author_id);
        if (searchForm.date_from) params.set('date_from', searchForm.date_from);
        if (searchForm.date_to) params.set('date_to', searchForm.date_to);
        if (searchForm.tag) params.set('tag', searchForm.tag);

        const data = await apiRequest(`/api/posts/search?${params.toString()}`);
        emit('posts-updated', data.posts);
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    } finally {
        loading.value = false;
    }
}

async function resetSearch() {
    Object.assign(searchForm, { author_id: '', date_from: '', date_to: '', tag: '' });
    await runSearch();
}

async function likePost(postId) {
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

async function viewPost(postId) {
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

async function addComment(postId) {
    const content = (commentDrafts[postId] || '').trim();
    if (!content) return;

    try {
        await apiRequest(`/api/posts/${postId}/comments`, {
            method: 'POST',
            body: JSON.stringify({
                user_id: props.actingUserId,
                content,
            }),
        });
        commentDrafts[postId] = '';
        emit('notify', { severity: 'success', text: 'Embedded comment added to MongoDB post.' });
        emit('refresh');
    } catch (e) {
        emit('notify', { severity: 'error', text: e.message });
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card class="border border-slate-200 shadow-none">
                <template #title>
                    <span class="text-base font-semibold">Create User Profile (Embedded Document & Tags)</span>
                </template>
                <template #content>
                    <form class="flex flex-col gap-3" @submit.prevent="submitUser">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <InputText v-model="userForm.username" placeholder="Username (e.g. frank)" required />
                            <InputText v-model="userForm.full_name" placeholder="Full Name" required />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <InputText v-model="userForm.email" type="email" placeholder="Email" required />
                            <InputText v-model="userForm.location" placeholder="Location (embedded profile.location)" />
                        </div>
                        <InputText v-model="userForm.website" placeholder="Website URL (embedded profile.website)" />
                        <InputText v-model="userForm.bio" placeholder="Bio (embedded profile.bio)" />
                        <InputText v-model="userForm.interests"
                            placeholder="Interests array (comma-separated: mongodb, vue, redis)" />
                        <div>
                            <Button type="submit" label="Create User Profile" icon="pi pi-user-plus" size="small"
                                :loading="loading" />
                        </div>
                    </form>
                </template>
            </Card>

            <Card class="border border-slate-200 shadow-none">
                <template #title>
                    <span class="text-base font-semibold">Publish Post (Embedded Author, Tags Array & Comments)</span>
                </template>
                <template #content>
                    <form class="flex flex-col gap-3" @submit.prevent="submitPost">
                        <Select v-model="postForm.author_id" :options="users" optionLabel="full_name" optionValue="id"
                            placeholder="Select Author (defaults to Active User)" class="w-full" />
                        <InputText v-model="postForm.title" placeholder="Post Title" required />
                        <Textarea v-model="postForm.content" rows="3" placeholder="Post Content" required />
                        <InputText v-model="postForm.tags"
                            placeholder="Tags array (comma-separated: laravel, nosql, graph)" />
                        <div>
                            <Button type="submit" label="Publish Post" icon="pi pi-send" size="small"
                                :loading="loading" />
                        </div>
                    </form>
                </template>
            </Card>
        </div>

        <Card class="border border-slate-200 shadow-none">
            <template #title>
                <span class="text-base font-semibold">MongoDB Aggregation: Users with the Most Likes</span>
            </template>
            <template #content>
                <DataTable :value="mostLikedUsers" size="small" stripedRows>
                    <Column field="username" header="Username">
                        <template #body="{ data }">@{{ data.username }}</template>
                    </Column>
                    <Column field="full_name" header="Full Name" />
                    <Column field="total_likes" header="Total Likes">
                        <template #body="{ data }">
                            <Tag :value="String(data.total_likes)" severity="success" />
                        </template>
                    </Column>
                    <Column field="posts_count" header="Posts" />
                    <Column field="comments_count" header="Comments Received" />
                    <Column field="total_views" header="Total Views" />
                </DataTable>
            </template>
        </Card>

        <Card class="border border-slate-200 shadow-none">
            <template #title>
                <span class="text-base font-semibold">Search Posts by Author, Date Range & Tag</span>
            </template>
            <template #content>
                <form class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-4" @submit.prevent="runSearch">
                    <Select v-model="searchForm.author_id" :options="[{ id: '', full_name: 'All Authors' }, ...users]"
                        optionLabel="full_name" optionValue="id" placeholder="All Authors" />
                    <InputText v-model="searchForm.date_from" type="date" placeholder="From Date" />
                    <InputText v-model="searchForm.date_to" type="date" placeholder="To Date" />
                    <InputText v-model="searchForm.tag" placeholder="Tag (e.g. redis)" />
                    <div class="flex gap-2">
                        <Button type="submit" label="Search" icon="pi pi-search" size="small" />
                        <Button type="button" label="Reset" severity="secondary" outlined size="small"
                            @click="resetSearch" />
                    </div>
                </form>

                <div class="flex flex-col gap-4">
                    <div v-for="post in posts" :key="post.id"
                        class="border border-slate-200 rounded-md p-4 bg-white flex flex-col gap-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ post.title }}</h3>
                                <p class="text-xs text-slate-500">
                                    By <span class="font-medium text-slate-700">{{ post.author.full_name }} (@{{
                                        post.author.username }})</span>
                                    <span v-if="post.author.location"> · {{ post.author.location }}</span>
                                    · {{ new Date(post.created_at).toLocaleString() }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button :label="`Like (${post.likes_count})`" icon="pi pi-heart" size="small" outlined
                                    @click="likePost(post.id)" />
                                <Button :label="`View (${post.views_count})`" icon="pi pi-eye" size="small"
                                    severity="secondary" outlined @click="viewPost(post.id)" />
                            </div>
                        </div>

                        <p class="text-sm text-slate-700">{{ post.content }}</p>

                        <div class="flex flex-wrap gap-1.5">
                            <Tag v-for="tag in post.tags" :key="tag" :value="`#${tag}`" severity="secondary" />
                        </div>

                        <div class="border-t border-slate-100 pt-3 flex flex-col gap-2">
                            <div class="text-xs font-semibold text-slate-600">
                                Embedded Comments ({{ post.comments.length }})
                            </div>
                            <div v-for="comment in post.comments" :key="comment.id"
                                class="text-xs bg-slate-50 px-3 py-2 rounded border border-slate-100 flex justify-between gap-2">
                                <div>
                                    <span class="font-semibold text-slate-800">@{{ comment.author.username }}:</span>
                                    <span class="text-slate-700 ml-1">{{ comment.content }}</span>
                                </div>
                                <span class="text-slate-400 shrink-0">{{ new
                                    Date(comment.created_at).toLocaleTimeString() }}</span>
                            </div>

                            <form class="flex gap-2 mt-1" @submit.prevent="addComment(post.id)">
                                <InputText v-model="commentDrafts[post.id]" placeholder="Write an embedded comment..."
                                    class="flex-1 text-sm" size="small" />
                                <Button type="submit" label="Comment" icon="pi pi-comment" size="small"
                                    severity="secondary" />
                            </form>
                        </div>
                    </div>
                </div>
            </template>
        </Card>

        <Card class="border border-slate-200 shadow-none">
            <template #title>
                <span class="text-base font-semibold">MongoDB User Profiles Collection (with Embedded Profile &
                    Interests)</span>
            </template>
            <template #content>
                <DataTable :value="users" size="small" stripedRows>
                    <Column field="id" header="ID" />
                    <Column field="username" header="Username">
                        <template #body="{ data }">@{{ data.username }}</template>
                    </Column>
                    <Column field="full_name" header="Full Name" />
                    <Column header="Embedded Profile">
                        <template #body="{ data }">
                            <div class="text-xs">
                                <div><span class="font-medium">Bio:</span> {{ data.profile?.bio || '—' }}</div>
                                <div><span class="font-medium">Location:</span> {{ data.profile?.location || '—' }}
                                </div>
                            </div>
                        </template>
                    </Column>
                    <Column header="Interests Array">
                        <template #body="{ data }">
                            <div class="flex flex-wrap gap-1">
                                <Tag v-for="item in data.interests" :key="item" :value="item" severity="info" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </template>
        </Card>
    </div>
</template>
