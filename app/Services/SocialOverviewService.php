<?php

namespace App\Services;

class SocialOverviewService
{
    public function __construct(
        private readonly DemoSeederService $seederService,
        private readonly MongoSocialService $mongoService,
        private readonly RedisActivityService $redisService,
        private readonly Neo4jGraphService $neo4jService,
        private readonly CassandraEventService $cassandraService,
    ) {}

    /**
     * Clear all data across all 4 databases and return empty overview.
     *
     * @return array<string, mixed>
     */
    public function clearAll(): array
    {
        $this->seederService->clearAll();

        return $this->buildOverview();
    }

    /**
     * Reseed demo data across all 4 databases and return overview.
     *
     * @return array<string, mixed>
     */
    public function reseed(): array
    {
        $this->seederService->seed(true);

        return $this->buildOverview();
    }

    /**
     * Build the full multi-database overview payload without auto-reseeding.
     *
     * @return array<string, mixed>
     */
    public function getOverview(bool $autoSeedIfEmpty = false): array
    {
        if ($autoSeedIfEmpty) {
            $this->seederService->seed(false);
        }

        return $this->buildOverview();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOverview(): array
    {
        $users = $this->mongoService->getUsers();
        $firstUserId = $users[0]['id'] ?? '';
        $secondUserId = $users[1]['id'] ?? '';

        return [
            'mongodb' => [
                'users' => $users,
                'posts' => $this->mongoService->searchPosts(),
                'most_liked_users' => $this->mongoService->getTopUsersByLikes(10),
            ],
            'redis' => [
                'top_posts' => $this->mongoService->getTopPopularPosts(10),
                'active_users' => $this->redisService->getActiveUsers(),
            ],
            'neo4j' => [
                'subscriptions' => $this->neo4jService->getSubscriptions(),
                'mutual_friends' => ($firstUserId !== '' && $secondUserId !== '')
                    ? $this->neo4jService->getMutualFriends($firstUserId, $secondUserId)
                    : [],
                'recommendations' => $firstUserId !== ''
                    ? $this->neo4jService->getSubscriptionRecommendations($firstUserId, 10)
                    : [],
                'default_user_a_id' => $firstUserId,
                'default_user_b_id' => $secondUserId,
                'default_recommend_user_id' => $firstUserId,
            ],
            'cassandra' => [
                'recent_events' => $this->cassandraService->getRecentEvents(20),
                'user_events' => $firstUserId !== ''
                    ? $this->cassandraService->getUserHistory($firstUserId, null, null, 20)
                    : [],
                'default_user_id' => $firstUserId,
            ],
        ];
    }
}
