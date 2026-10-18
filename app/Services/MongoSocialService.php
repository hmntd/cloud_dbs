<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class MongoSocialService
{
    public function __construct(
        private readonly RedisActivityService $redisService,
        private readonly Neo4jGraphService $neo4jService,
        private readonly CassandraEventService $cassandraService,
    ) {}

    /**
     * Retrieve all user profiles from MongoDB.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUsers(): array
    {
        $cursor = $this->usersCollection()->find([], ['sort' => ['username' => 1, 'id' => 1]]);

        $users = [];
        foreach ($cursor as $doc) {
            $users[] = $this->formatUserDocument($doc);
        }

        return $users;
    }

    /**
     * Find a single user profile by ID in MongoDB.
     *
     * @return array<string, mixed>|null
     */
    public function findUser(string $userId): ?array
    {
        $doc = $this->usersCollection()->findOne(['id' => $userId]);

        if ($doc === null) {
            return null;
        }

        return $this->formatUserDocument($doc);
    }

    /**
     * Create a user profile in MongoDB (with embedded profile & interests array), sync to Neo4j and Redis.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createUser(array $data, ?string $customId = null): array
    {
        $userId = $customId ?? 'u_'.Str::lower(Str::random(8));
        $now = CarbonImmutable::now();

        $interests = $this->normalizeTags($data['interests'] ?? []);

        $userDoc = [
            'id' => $userId,
            'username' => (string) $data['username'],
            'full_name' => (string) $data['full_name'],
            'email' => (string) $data['email'],
            'profile' => [
                'bio' => (string) ($data['bio'] ?? ''),
                'location' => (string) ($data['location'] ?? ''),
                'website' => (string) ($data['website'] ?? ''),
            ],
            'interests' => $interests,
            'created_at' => $now->toIso8601String(),
        ];

        $this->usersCollection()->insertOne($userDoc);

        $this->neo4jService->upsertUserNode($userId, $userDoc['username'], $userDoc['full_name']);
        $this->redisService->touchActiveUser($userId, $userDoc['username'], $userDoc['full_name'], 'registered profile');

        return $userDoc;
    }

    /**
     * Create a post in MongoDB (with embedded author, tags array, and embedded comments array),
     * initialize Redis metrics, and record the post event in Cassandra.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPost(
        array $data,
        ?string $customId = null,
        ?CarbonImmutable $createdAt = null,
        int $initialLikes = 0,
        int $initialViews = 1,
    ): array {
        $author = $this->findUser((string) $data['author_id']);
        if ($author === null) {
            throw new \InvalidArgumentException('Selected author does not exist.');
        }

        $postId = $customId ?? 'p_'.Str::lower(Str::random(8));
        $timestamp = $createdAt ?? CarbonImmutable::now();
        $tags = $this->normalizeTags($data['tags'] ?? []);

        $postDoc = [
            'id' => $postId,
            'title' => (string) $data['title'],
            'content' => (string) $data['content'],
            'author' => [
                'id' => $author['id'],
                'username' => $author['username'],
                'full_name' => $author['full_name'],
                'location' => $author['profile']['location'] ?? '',
            ],
            'tags' => $tags,
            'comments' => [],
            'likes_count' => $initialLikes,
            'views_count' => $initialViews,
            'comments_count' => 0,
            'created_at' => $timestamp->toIso8601String(),
            'created_at_dt' => new UTCDateTime($timestamp->getTimestampMs()),
        ];

        $this->postsCollection()->insertOne($postDoc);

        $metrics = $this->redisService->initializePostMetrics($postId, $initialLikes, $initialViews, 0);
        $this->redisService->touchActiveUser($author['id'], $author['username'], $author['full_name'], "published post \"{$postDoc['title']}\"");

        $this->cassandraService->recordEvent(
            userId: $author['id'],
            username: $author['username'],
            eventType: 'post',
            targetId: $postId,
            description: "Published post \"{$postDoc['title']}\"",
            metadata: ['title' => $postDoc['title'], 'tags' => $tags],
            occurredAtMs: $timestamp->getTimestampMs(),
        );

        $formatted = $this->formatPostDocument($postDoc);
        $formatted['popularity_score'] = $metrics['popularity_score'];

        return $formatted;
    }

    /**
     * Add an embedded comment document to a post in MongoDB, update Redis counters, and log to Cassandra.
     *
     * @return array<string, mixed>
     */
    public function addComment(
        string $postId,
        string $userId,
        string $content,
        ?CarbonImmutable $createdAt = null,
    ): array {
        $user = $this->findUser($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $post = $this->findPost($postId);
        if ($post === null) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $timestamp = $createdAt ?? CarbonImmutable::now();

        $comment = [
            'id' => 'c_'.Str::lower(Str::random(8)),
            'author' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
            ],
            'content' => $content,
            'created_at' => $timestamp->toIso8601String(),
        ];

        $this->postsCollection()->updateOne(
            ['id' => $postId],
            [
                '$push' => ['comments' => $comment],
                '$inc' => ['comments_count' => 1],
            ]
        );

        $this->redisService->incrementComments($postId);
        $this->redisService->touchActiveUser($user['id'], $user['username'], $user['full_name'], "commented on \"{$post['title']}\"");

        $this->cassandraService->recordEvent(
            userId: $user['id'],
            username: $user['username'],
            eventType: 'comment',
            targetId: $postId,
            description: "Commented on \"{$post['title']}\": ".Str::limit($content, 60),
            metadata: ['post_title' => $post['title'], 'comment_id' => $comment['id']],
            occurredAtMs: $timestamp->getTimestampMs(),
        );

        return $this->findPost($postId) ?? $post;
    }

    /**
     * Like a post: increment Redis & MongoDB counters, update active user in Redis, and log event in Cassandra.
     *
     * @return array<string, mixed>
     */
    public function likePost(string $postId, string $userId, ?CarbonImmutable $occurredAt = null): array
    {
        $user = $this->findUser($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $post = $this->findPost($postId);
        if ($post === null) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $metrics = $this->redisService->incrementLikes($postId, $userId);

        $this->postsCollection()->updateOne(
            ['id' => $postId],
            ['$set' => ['likes_count' => $metrics['likes']]]
        );

        $this->redisService->touchActiveUser($user['id'], $user['username'], $user['full_name'], "liked \"{$post['title']}\"");

        $timestamp = $occurredAt ?? CarbonImmutable::now();
        $this->cassandraService->recordEvent(
            userId: $user['id'],
            username: $user['username'],
            eventType: 'like',
            targetId: $postId,
            description: "Liked post \"{$post['title']}\" by @{$post['author']['username']}",
            metadata: ['post_title' => $post['title'], 'author_id' => $post['author']['id']],
            occurredAtMs: $timestamp->getTimestampMs(),
        );

        return $this->findPost($postId) ?? $post;
    }

    /**
     * Record a post view: increment Redis & MongoDB view counters, touch active user in Redis, and log event in Cassandra.
     *
     * @return array<string, mixed>
     */
    public function viewPost(string $postId, string $userId, ?CarbonImmutable $occurredAt = null): array
    {
        $user = $this->findUser($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $post = $this->findPost($postId);
        if ($post === null) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $metrics = $this->redisService->incrementViews($postId);

        $this->postsCollection()->updateOne(
            ['id' => $postId],
            ['$set' => ['views_count' => $metrics['views']]]
        );

        $this->redisService->touchActiveUser($user['id'], $user['username'], $user['full_name'], "viewed \"{$post['title']}\"");

        $timestamp = $occurredAt ?? CarbonImmutable::now();
        $this->cassandraService->recordEvent(
            userId: $user['id'],
            username: $user['username'],
            eventType: 'view',
            targetId: $postId,
            description: "Viewed post \"{$post['title']}\"",
            metadata: ['post_title' => $post['title']],
            occurredAtMs: $timestamp->getTimestampMs(),
        );

        return $this->findPost($postId) ?? $post;
    }

    /**
     * Create a subscription between two users in Neo4j and log activity in Redis & Cassandra.
     *
     * @return array<string, mixed>
     */
    public function subscribeUser(string $followerId, string $followeeId): array
    {
        $follower = $this->findUser($followerId);
        $followee = $this->findUser($followeeId);

        if ($follower === null || $followee === null) {
            throw new \InvalidArgumentException('Follower or followee user not found.');
        }

        $subscription = $this->neo4jService->followUser($followerId, $followeeId);
        $this->redisService->touchActiveUser($follower['id'], $follower['username'], $follower['full_name'], "followed @{$followee['username']}");

        return $subscription;
    }

    /**
     * Search posts in MongoDB by author, date range (date_from / date_to), and tag.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchPosts(
        ?string $authorId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $tag = null,
    ): array {
        $filter = [];

        if ($authorId !== null && $authorId !== '') {
            $filter['author.id'] = $authorId;
        }

        $dateFilter = [];
        if ($dateFrom !== null && $dateFrom !== '') {
            $fromMs = CarbonImmutable::parse($dateFrom)->startOfDay()->getTimestampMs();
            $dateFilter['$gte'] = new UTCDateTime($fromMs);
        }

        if ($dateTo !== null && $dateTo !== '') {
            $toMs = CarbonImmutable::parse($dateTo)->endOfDay()->getTimestampMs();
            $dateFilter['$lte'] = new UTCDateTime($toMs);
        }

        if ($dateFilter !== []) {
            $filter['created_at_dt'] = $dateFilter;
        }

        if ($tag !== null && trim($tag) !== '') {
            $filter['tags'] = Str::lower(trim($tag));
        }

        $cursor = $this->postsCollection()->find($filter, ['sort' => ['created_at_dt' => -1]]);

        $posts = [];
        foreach ($cursor as $doc) {
            $posts[] = $this->formatPostDocument($doc);
        }

        return $posts;
    }

    /**
     * MongoDB Aggregation Pipeline: determine users with the most likes across all their posts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopUsersByLikes(int $limit = 10): array
    {
        $pipeline = [
            [
                '$group' => [
                    '_id' => '$author.id',
                    'username' => ['$first' => '$author.username'],
                    'full_name' => ['$first' => '$author.full_name'],
                    'total_likes' => ['$sum' => '$likes_count'],
                    'total_views' => ['$sum' => '$views_count'],
                    'posts_count' => ['$sum' => 1],
                    'comments_count' => ['$sum' => '$comments_count'],
                ],
            ],
            [
                '$sort' => [
                    'total_likes' => -1,
                    'total_views' => -1,
                ],
            ],
            [
                '$limit' => $limit,
            ],
        ];

        $cursor = $this->postsCollection()->aggregate($pipeline);

        $results = [];
        foreach ($cursor as $doc) {
            $arr = $this->toArray($doc);
            $results[] = [
                'user_id' => (string) ($arr['_id'] ?? ''),
                'username' => (string) ($arr['username'] ?? ''),
                'full_name' => (string) ($arr['full_name'] ?? ''),
                'total_likes' => (int) ($arr['total_likes'] ?? 0),
                'total_views' => (int) ($arr['total_views'] ?? 0),
                'posts_count' => (int) ($arr['posts_count'] ?? 0),
                'comments_count' => (int) ($arr['comments_count'] ?? 0),
            ];
        }

        return $results;
    }

    /**
     * Get the top 10 most popular posts from Redis Sorted Set hydrated with MongoDB post data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopPopularPosts(int $limit = 10): array
    {
        $scoresByPostId = $this->redisService->getTopPopularPostScores($limit);
        if ($scoresByPostId === []) {
            return [];
        }

        $postIds = array_keys($scoresByPostId);
        $cursor = $this->postsCollection()->find(['id' => ['$in' => $postIds]]);

        $postsById = [];
        foreach ($cursor as $doc) {
            $formatted = $this->formatPostDocument($doc);
            $postsById[$formatted['id']] = $formatted;
        }

        $ranked = [];
        $rank = 1;
        foreach ($scoresByPostId as $postId => $score) {
            if (! isset($postsById[$postId])) {
                continue;
            }

            $post = $postsById[$postId];
            $post['rank'] = $rank++;
            $post['popularity_score'] = $score;
            $ranked[] = $post;
        }

        return $ranked;
    }

    /**
     * Find a single post by ID in MongoDB.
     *
     * @return array<string, mixed>|null
     */
    public function findPost(string $postId): ?array
    {
        $doc = $this->postsCollection()->findOne(['id' => $postId]);

        if ($doc === null) {
            return null;
        }

        return $this->formatPostDocument($doc);
    }

    /**
     * Drop MongoDB social collections for clean re-seeding.
     */
    public function clearAll(): void
    {
        $this->usersCollection()->deleteMany([]);
        $this->postsCollection()->deleteMany([]);
    }

    private function usersCollection(): Collection
    {
        /** @var Connection $connection */
        $connection = DB::connection('mongodb');

        return $connection->getMongoDB()->selectCollection('social_users');
    }

    private function postsCollection(): Collection
    {
        /** @var Connection $connection */
        $connection = DB::connection('mongodb');

        return $connection->getMongoDB()->selectCollection('social_posts');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatUserDocument(mixed $doc): array
    {
        $arr = $this->toArray($doc);
        $profile = $this->toArray($arr['profile'] ?? []);
        $interests = $this->toArray($arr['interests'] ?? []);

        return [
            'id' => (string) ($arr['id'] ?? ''),
            'username' => (string) ($arr['username'] ?? ''),
            'full_name' => (string) ($arr['full_name'] ?? ''),
            'email' => (string) ($arr['email'] ?? ''),
            'profile' => [
                'bio' => (string) ($profile['bio'] ?? ''),
                'location' => (string) ($profile['location'] ?? ''),
                'website' => (string) ($profile['website'] ?? ''),
            ],
            'interests' => array_values(array_map('strval', $interests)),
            'created_at' => (string) ($arr['created_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPostDocument(mixed $doc): array
    {
        $arr = $this->toArray($doc);
        $author = $this->toArray($arr['author'] ?? []);
        $tags = $this->toArray($arr['tags'] ?? []);
        $rawComments = $this->toArray($arr['comments'] ?? []);

        $comments = [];
        foreach ($rawComments as $rawComment) {
            $c = $this->toArray($rawComment);
            $cAuthor = $this->toArray($c['author'] ?? []);
            $comments[] = [
                'id' => (string) ($c['id'] ?? ''),
                'author' => [
                    'id' => (string) ($cAuthor['id'] ?? ''),
                    'username' => (string) ($cAuthor['username'] ?? ''),
                    'full_name' => (string) ($cAuthor['full_name'] ?? ''),
                ],
                'content' => (string) ($c['content'] ?? ''),
                'created_at' => (string) ($c['created_at'] ?? ''),
            ];
        }

        $postId = (string) ($arr['id'] ?? '');
        $redisMetrics = $this->redisService->getPostMetrics($postId);

        $likesCount = max((int) ($arr['likes_count'] ?? 0), $redisMetrics['likes']);
        $viewsCount = max((int) ($arr['views_count'] ?? 0), $redisMetrics['views']);
        $commentsCount = count($comments);

        return [
            'id' => $postId,
            'title' => (string) ($arr['title'] ?? ''),
            'content' => (string) ($arr['content'] ?? ''),
            'author' => [
                'id' => (string) ($author['id'] ?? ''),
                'username' => (string) ($author['username'] ?? ''),
                'full_name' => (string) ($author['full_name'] ?? ''),
                'location' => (string) ($author['location'] ?? ''),
            ],
            'tags' => array_values(array_map('strval', $tags)),
            'comments' => $comments,
            'likes_count' => $likesCount,
            'views_count' => $viewsCount,
            'comments_count' => $commentsCount,
            'popularity_score' => $redisMetrics['popularity_score'],
            'created_at' => (string) ($arr['created_at'] ?? ''),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTags(mixed $rawTags): array
    {
        if (is_string($rawTags)) {
            $rawTags = explode(',', $rawTags);
        }

        if (! is_array($rawTags)) {
            return [];
        }

        $cleaned = [];
        foreach ($rawTags as $tag) {
            $trimmed = Str::lower(trim((string) $tag));
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * @return array<string|int, mixed>
     */
    private function toArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }
}
