<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

class RedisActivityService
{
    private const POPULARITY_KEY = 'social:posts:popularity';

    private const ACTIVE_USERS_SET_KEY = 'social:active_users:ids';

    /**
     * Initialize or sync Redis counters and popularity rating for a post.
     *
     * @return array{likes: int, views: int, comments: int, popularity_score: float}
     */
    public function initializePostMetrics(string $postId, int $likes = 0, int $views = 0, int $comments = 0): array
    {
        Redis::set($this->likesKey($postId), $likes);
        Redis::set($this->viewsKey($postId), $views);
        Redis::set($this->commentsKey($postId), $comments);

        $score = $this->calculatePopularityScore($likes, $views, $comments);
        Redis::zadd(self::POPULARITY_KEY, $score, $postId);

        return [
            'likes' => $likes,
            'views' => $views,
            'comments' => $comments,
            'popularity_score' => $score,
        ];
    }

    /**
     * Increment the like counter for a post and update its popularity rating in Redis.
     *
     * @return array{likes: int, views: int, comments: int, popularity_score: float}
     */
    public function incrementLikes(string $postId, string $userId): array
    {
        Redis::sadd($this->likedByKey($postId), $userId);
        $likes = (int) Redis::incr($this->likesKey($postId));
        $views = (int) (Redis::get($this->viewsKey($postId)) ?? 0);
        $comments = (int) (Redis::get($this->commentsKey($postId)) ?? 0);

        $score = $this->calculatePopularityScore($likes, $views, $comments);
        Redis::zadd(self::POPULARITY_KEY, $score, $postId);

        return [
            'likes' => $likes,
            'views' => $views,
            'comments' => $comments,
            'popularity_score' => $score,
        ];
    }

    /**
     * Increment the view counter for a post and update its popularity rating in Redis.
     *
     * @return array{likes: int, views: int, comments: int, popularity_score: float}
     */
    public function incrementViews(string $postId): array
    {
        $views = (int) Redis::incr($this->viewsKey($postId));
        $likes = (int) (Redis::get($this->likesKey($postId)) ?? 0);
        $comments = (int) (Redis::get($this->commentsKey($postId)) ?? 0);

        $score = $this->calculatePopularityScore($likes, $views, $comments);
        Redis::zadd(self::POPULARITY_KEY, $score, $postId);

        return [
            'likes' => $likes,
            'views' => $views,
            'comments' => $comments,
            'popularity_score' => $score,
        ];
    }

    /**
     * Increment the comments counter for a post and update its popularity rating in Redis.
     *
     * @return array{likes: int, views: int, comments: int, popularity_score: float}
     */
    public function incrementComments(string $postId): array
    {
        $comments = (int) Redis::incr($this->commentsKey($postId));
        $likes = (int) (Redis::get($this->likesKey($postId)) ?? 0);
        $views = (int) (Redis::get($this->viewsKey($postId)) ?? 0);

        $score = $this->calculatePopularityScore($likes, $views, $comments);
        Redis::zadd(self::POPULARITY_KEY, $score, $postId);

        return [
            'likes' => $likes,
            'views' => $views,
            'comments' => $comments,
            'popularity_score' => $score,
        ];
    }

    /**
     * Get Redis counters and popularity score for a given post.
     *
     * @return array{likes: int, views: int, comments: int, popularity_score: float}
     */
    public function getPostMetrics(string $postId): array
    {
        $likes = (int) (Redis::get($this->likesKey($postId)) ?? 0);
        $views = (int) (Redis::get($this->viewsKey($postId)) ?? 0);
        $comments = (int) (Redis::get($this->commentsKey($postId)) ?? 0);
        $score = Redis::zscore(self::POPULARITY_KEY, $postId);

        if ($score === false || $score === null) {
            $score = $this->calculatePopularityScore($likes, $views, $comments);
        }

        return [
            'likes' => $likes,
            'views' => $views,
            'comments' => $comments,
            'popularity_score' => (float) $score,
        ];
    }

    /**
     * Determine the top N (default 10) posts by popularity score from Redis Sorted Set.
     *
     * @return array<string, float>
     */
    public function getTopPopularPostScores(int $limit = 10): array
    {
        $raw = Redis::zrevrange(self::POPULARITY_KEY, 0, max(0, $limit - 1), ['WITHSCORES' => true]);

        if (! is_array($raw)) {
            return [];
        }

        $scores = [];
        foreach ($raw as $postId => $score) {
            $scores[(string) $postId] = (float) $score;
        }

        return $scores;
    }

    /**
     * Store temporary data of an active user with a TTL in Redis.
     *
     * @return array<string, mixed>
     */
    public function touchActiveUser(
        string $userId,
        string $username,
        string $fullName,
        string $lastAction,
        int $ttlSeconds = 900,
    ): array {
        $payload = [
            'user_id' => $userId,
            'username' => $username,
            'full_name' => $fullName,
            'status' => 'online',
            'last_action' => $lastAction,
            'updated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $key = $this->activeUserKey($userId);
        Redis::setex($key, $ttlSeconds, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        Redis::sadd(self::ACTIVE_USERS_SET_KEY, $userId);

        $payload['ttl_seconds'] = (int) Redis::ttl($key);

        return $payload;
    }

    /**
     * Retrieve all currently active users whose temporary session data has not expired.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveUsers(): array
    {
        $userIds = Redis::smembers(self::ACTIVE_USERS_SET_KEY);

        if (! is_array($userIds)) {
            return [];
        }

        $activeUsers = [];

        foreach ($userIds as $userId) {
            $userIdStr = (string) $userId;
            $key = $this->activeUserKey($userIdStr);
            $raw = Redis::get($key);

            if ($raw === false || $raw === null) {
                Redis::srem(self::ACTIVE_USERS_SET_KEY, $userIdStr);

                continue;
            }

            $decoded = json_decode((string) $raw, true);
            if (! is_array($decoded)) {
                continue;
            }

            $decoded['ttl_seconds'] = max(0, (int) Redis::ttl($key));
            $activeUsers[] = $decoded;
        }

        usort($activeUsers, fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return $activeUsers;
    }

    /**
     * Clear all social Redis keys.
     */
    public function clearAll(): void
    {
        $userIds = Redis::smembers(self::ACTIVE_USERS_SET_KEY);
        if (is_array($userIds)) {
            foreach ($userIds as $userId) {
                Redis::del($this->activeUserKey((string) $userId));
            }
        }

        $postScores = Redis::zrange(self::POPULARITY_KEY, 0, -1);
        if (is_array($postScores)) {
            foreach ($postScores as $postId) {
                $postIdStr = (string) $postId;
                Redis::del(
                    $this->likesKey($postIdStr),
                    $this->viewsKey($postIdStr),
                    $this->commentsKey($postIdStr),
                    $this->likedByKey($postIdStr),
                );
            }
        }

        Redis::del(self::POPULARITY_KEY, self::ACTIVE_USERS_SET_KEY);
    }

    private function calculatePopularityScore(int $likes, int $views, int $comments): float
    {
        return (float) (($likes * 3) + ($comments * 2) + $views);
    }

    private function likesKey(string $postId): string
    {
        return "social:post:{$postId}:likes";
    }

    private function viewsKey(string $postId): string
    {
        return "social:post:{$postId}:views";
    }

    private function commentsKey(string $postId): string
    {
        return "social:post:{$postId}:comments";
    }

    private function likedByKey(string $postId): string
    {
        return "social:post:{$postId}:liked_by";
    }

    private function activeUserKey(string $userId): string
    {
        return "social:active_user:{$userId}";
    }
}
