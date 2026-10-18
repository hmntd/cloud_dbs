<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class DemoSeederService
{
    public function __construct(
        private readonly MongoSocialService $mongoService,
        private readonly RedisActivityService $redisService,
        private readonly Neo4jGraphService $neo4jService,
        private readonly CassandraEventService $cassandraService,
    ) {}

    /**
     * Clear all data across MongoDB, Redis, Neo4j, and Cassandra.
     */
    public function clearAll(): void
    {
        $this->mongoService->clearAll();
        $this->redisService->clearAll();
        $this->neo4jService->clearAll();
        $this->cassandraService->clearAll();
    }

    /**
     * Seed initial demo data across MongoDB, Redis, Neo4j, and Cassandra if empty, or force reset.
     */
    public function seed(bool $forceReset = false): void
    {
        if (! $forceReset && count($this->mongoService->getUsers()) > 0) {
            return;
        }

        $this->clearAll();

        $now = CarbonImmutable::now();

        $users = [
            [
                'id' => 'u_alice',
                'username' => 'alice',
                'full_name' => 'Alice Johnson',
                'email' => 'alice@example.com',
                'bio' => 'Distributed systems architect & graph database enthusiast.',
                'location' => 'Kyiv, Ukraine',
                'website' => 'https://alice.dev',
                'interests' => ['neo4j', 'mongodb', 'distributed-systems', 'laravel'],
            ],
            [
                'id' => 'u_bob',
                'username' => 'bob',
                'full_name' => 'Bob Smith',
                'email' => 'bob@example.com',
                'bio' => 'Backend engineer focused on high-throughput caching and Redis.',
                'location' => 'Lviv, Ukraine',
                'website' => 'https://bobsmith.io',
                'interests' => ['redis', 'php', 'laravel', 'performance'],
            ],
            [
                'id' => 'u_charlie',
                'username' => 'charlie',
                'full_name' => 'Charlie Davis',
                'email' => 'charlie@example.com',
                'bio' => 'Cassandra & wide-column store specialist.',
                'location' => 'Warsaw, Poland',
                'website' => 'https://charliedata.net',
                'interests' => ['cassandra', 'nosql', 'event-sourcing'],
            ],
            [
                'id' => 'u_diana',
                'username' => 'diana',
                'full_name' => 'Diana Prince',
                'email' => 'diana@example.com',
                'bio' => 'Full-stack developer building with Vue 3, PrimeVue, and Laravel.',
                'location' => 'Berlin, Germany',
                'website' => 'https://diana.codes',
                'interests' => ['vue', 'primevue', 'tailwind', 'mongodb'],
            ],
            [
                'id' => 'u_evan',
                'username' => 'evan',
                'full_name' => 'Evan Wright',
                'email' => 'evan@example.com',
                'bio' => 'Cloud infrastructure & polyglot persistence researcher.',
                'location' => 'London, UK',
                'website' => 'https://evancloud.org',
                'interests' => ['cloud', 'cassandra', 'neo4j', 'redis'],
            ],
        ];

        foreach ($users as $u) {
            $this->mongoService->createUser($u, $u['id']);
        }

        // Build Neo4j subscription graph:
        // Alice and Bob both follow Charlie and Diana (so Charlie & Diana are mutual connections of Alice & Bob).
        // Charlie and Diana both follow Evan (so Evan is recommended to Alice & Bob via friend-of-friend).
        $subscriptions = [
            ['u_alice', 'u_charlie'],
            ['u_alice', 'u_diana'],
            ['u_bob', 'u_charlie'],
            ['u_bob', 'u_diana'],
            ['u_charlie', 'u_evan'],
            ['u_diana', 'u_evan'],
            ['u_evan', 'u_alice'],
        ];

        foreach ($subscriptions as [$followerId, $followeeId]) {
            $this->mongoService->subscribeUser($followerId, $followeeId);
        }

        // Seed posts across several days with embedded authors & tags
        $posts = [
            [
                'id' => 'p_polyglot_1',
                'author_id' => 'u_alice',
                'title' => 'Designing Polyglot Persistence in Laravel 13',
                'content' => 'Combining MongoDB documents, Redis sorted sets, Neo4j graphs, and Cassandra wide-column event logs gives each workload the ideal storage engine.',
                'tags' => ['laravel', 'mongodb', 'neo4j', 'cassandra', 'redis'],
                'created_at' => $now->subDays(4)->setTime(10, 15),
                'views' => 42,
            ],
            [
                'id' => 'p_redis_rank_2',
                'author_id' => 'u_bob',
                'title' => 'Real-Time Post Popularity Leaderboards with Redis ZSET',
                'content' => 'Using ZADD and ZREVRANGE in Redis lets us compute the top 10 most popular posts in O(log N) time while keeping active user sessions with TTL.',
                'tags' => ['redis', 'leaderboard', 'caching', 'performance'],
                'created_at' => $now->subDays(3)->setTime(14, 30),
                'views' => 35,
            ],
            [
                'id' => 'p_neo4j_graph_3',
                'author_id' => 'u_charlie',
                'title' => 'Friend-of-Friend Recommendations Using Cypher in Neo4j',
                'content' => 'Graph traversal queries find mutual friends and suggest new accounts to follow in milliseconds without expensive SQL self-joins.',
                'tags' => ['neo4j', 'cypher', 'graph', 'recommendations'],
                'created_at' => $now->subDays(2)->setTime(9, 45),
                'views' => 28,
            ],
            [
                'id' => 'p_cassandra_log_4',
                'author_id' => 'u_charlie',
                'title' => 'Time-Series User Activity Logs in Apache Cassandra 5.0',
                'content' => 'Partitioning by user_id and clustering by occurred_at DESC allows instant time-range slices and retrieving the last 20 events per user.',
                'tags' => ['cassandra', 'events', 'timeseries', 'nosql'],
                'created_at' => $now->subDays(1)->setTime(16, 20),
                'views' => 31,
            ],
            [
                'id' => 'p_mongo_embed_5',
                'author_id' => 'u_diana',
                'title' => 'Embedded Comments and Aggregation Pipelines in MongoDB',
                'content' => 'Embedding comment subdocuments inside post documents ensures single-read rendering, while $group and $sort aggregate the most-liked authors.',
                'tags' => ['mongodb', 'aggregation', 'nosql', 'vue'],
                'created_at' => $now->subHours(12),
                'views' => 24,
            ],
            [
                'id' => 'p_cloud_sail_6',
                'author_id' => 'u_evan',
                'title' => 'Running Multi-Database Clusters Locally with Laravel Sail & PHP 8.5',
                'content' => 'With Docker Compose healthchecks and PHP 8.5 drivers, spinning up MongoDB, Redis, Neo4j, and Cassandra takes a single sail up command.',
                'tags' => ['laravel', 'docker', 'php85', 'cloud'],
                'created_at' => $now->subHours(3),
                'views' => 19,
            ],
        ];

        foreach ($posts as $p) {
            $this->mongoService->createPost(
                data: $p,
                customId: $p['id'],
                createdAt: $p['created_at'],
                initialLikes: 0,
                initialViews: $p['views'],
            );
        }

        // Seed likes (recorded in Redis, MongoDB, and Cassandra)
        $likes = [
            ['p_polyglot_1', 'u_bob', $now->subDays(3)->addHours(1)],
            ['p_polyglot_1', 'u_charlie', $now->subDays(3)->addHours(2)],
            ['p_polyglot_1', 'u_diana', $now->subDays(2)->addHours(3)],
            ['p_polyglot_1', 'u_evan', $now->subDays(1)->addHours(1)],
            ['p_redis_rank_2', 'u_alice', $now->subDays(2)->addHours(4)],
            ['p_redis_rank_2', 'u_charlie', $now->subDays(2)->addHours(5)],
            ['p_redis_rank_2', 'u_diana', $now->subDays(1)->addHours(2)],
            ['p_neo4j_graph_3', 'u_alice', $now->subDays(1)->addHours(4)],
            ['p_neo4j_graph_3', 'u_bob', $now->subDays(1)->addHours(5)],
            ['p_cassandra_log_4', 'u_alice', $now->subHours(18)],
            ['p_cassandra_log_4', 'u_evan', $now->subHours(15)],
            ['p_mongo_embed_5', 'u_alice', $now->subHours(8)],
            ['p_mongo_embed_5', 'u_bob', $now->subHours(6)],
            ['p_cloud_sail_6', 'u_diana', $now->subHours(2)],
        ];

        foreach ($likes as [$postId, $userId, $likedAt]) {
            $this->mongoService->likePost($postId, $userId, $likedAt);
        }

        // Seed embedded comments (recorded in MongoDB, Redis, and Cassandra)
        $comments = [
            ['p_polyglot_1', 'u_bob', 'Great breakdown! Redis sorted sets pair really nicely with MongoDB documents.', $now->subDays(3)->addHours(3)],
            ['p_polyglot_1', 'u_charlie', 'Storing the immutable audit trail in Cassandra keeps write latency predictable.', $now->subDays(2)->addHours(6)],
            ['p_redis_rank_2', 'u_alice', 'Love how clean the ZREVRANGE top-10 lookup is.', $now->subDays(2)->addHours(7)],
            ['p_neo4j_graph_3', 'u_diana', 'Friend-of-friend Cypher queries are so much cleaner than recursive CTEs.', $now->subDays(1)->addHours(6)],
            ['p_mongo_embed_5', 'u_evan', 'Embedded comments make loading the feed super fast.', $now->subHours(5)],
        ];

        foreach ($comments as [$postId, $userId, $text, $commentedAt]) {
            $this->mongoService->addComment($postId, $userId, $text, $commentedAt);
        }

        // Seed explicit view events in Cassandra & Redis
        $views = [
            ['p_polyglot_1', 'u_evan', $now->subHours(4)],
            ['p_redis_rank_2', 'u_diana', $now->subHours(3)],
            ['p_cloud_sail_6', 'u_alice', $now->subHour()],
        ];

        foreach ($views as [$postId, $userId, $viewedAt]) {
            $this->mongoService->viewPost($postId, $userId, $viewedAt);
        }
    }
}
