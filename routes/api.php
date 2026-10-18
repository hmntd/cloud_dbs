<?php

use App\Http\Controllers\Api\DashboardOverviewController;
use App\Http\Controllers\Api\LikePostController;
use App\Http\Controllers\Api\MutualFriendsController;
use App\Http\Controllers\Api\ResetDataController;
use App\Http\Controllers\Api\SearchPostsController;
use App\Http\Controllers\Api\SeedDemoDataController;
use App\Http\Controllers\Api\StoreCommentController;
use App\Http\Controllers\Api\StorePostController;
use App\Http\Controllers\Api\StoreSubscriptionController;
use App\Http\Controllers\Api\StoreUserController;
use App\Http\Controllers\Api\SubscriptionRecommendationsController;
use App\Http\Controllers\Api\TopLikedUsersController;
use App\Http\Controllers\Api\TopPopularPostsController;
use App\Http\Controllers\Api\TouchActiveUserController;
use App\Http\Controllers\Api\UserEventHistoryController;
use App\Http\Controllers\Api\ViewPostController;
use Illuminate\Support\Facades\Route;

Route::get('/overview', DashboardOverviewController::class)->name('api.overview');
Route::post('/seed', SeedDemoDataController::class)->name('api.seed');
Route::post('/reset', ResetDataController::class)->name('api.reset');

// 1. MongoDB endpoints: User profiles, Posts, Embedded Comments, Search by author & date, Most-liked aggregation
Route::post('/users', StoreUserController::class)->name('api.users.store');
Route::get('/users/most-liked', TopLikedUsersController::class)->name('api.users.most_liked');
Route::post('/posts', StorePostController::class)->name('api.posts.store');
Route::get('/posts/search', SearchPostsController::class)->name('api.posts.search');
Route::post('/posts/{postId}/comments', StoreCommentController::class)->name('api.posts.comments.store');
Route::post('/posts/{postId}/like', LikePostController::class)->name('api.posts.like');
Route::post('/posts/{postId}/view', ViewPostController::class)->name('api.posts.view');

// 2. Redis endpoints: Top 10 popular posts & Temporary active users
Route::get('/redis/top-posts', TopPopularPostsController::class)->name('api.redis.top_posts');
Route::post('/redis/active-users', TouchActiveUserController::class)->name('api.redis.active_users');

// 3. Neo4j endpoints: Subscriptions graph, Mutual friends, Subscription recommendations
Route::post('/neo4j/subscriptions', StoreSubscriptionController::class)->name('api.neo4j.subscriptions.store');
Route::get('/neo4j/mutual-friends', MutualFriendsController::class)->name('api.neo4j.mutual_friends');
Route::get('/neo4j/recommendations', SubscriptionRecommendationsController::class)->name('api.neo4j.recommendations');

// 4. Cassandra endpoints: User activity history for a given period & Last 20 events
Route::get('/cassandra/events', UserEventHistoryController::class)->name('api.cassandra.events');
