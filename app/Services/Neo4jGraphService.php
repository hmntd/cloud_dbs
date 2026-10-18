<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Laudis\Neo4j\Contracts\ClientInterface as Neo4jClientInterface;

class Neo4jGraphService
{
    public function __construct(
        private readonly Neo4jClientInterface $neo4j,
    ) {}

    /**
     * Create or update a User node in the Neo4j graph.
     */
    public function upsertUserNode(string $userId, string $username, string $fullName): void
    {
        $this->neo4j->run(
            'MERGE (u:User {id: $id})
             SET u.username = $username, u.full_name = $fullName',
            [
                'id' => $userId,
                'username' => $username,
                'fullName' => $fullName,
            ]
        );
    }

    /**
     * Create a FOLLOWS subscription edge between two users in Neo4j.
     *
     * @return array<string, mixed>
     */
    public function followUser(string $followerId, string $followeeId): array
    {
        $createdAt = CarbonImmutable::now()->toIso8601String();

        $result = $this->neo4j->run(
            'MATCH (follower:User {id: $followerId}), (followee:User {id: $followeeId})
             MERGE (follower)-[r:FOLLOWS]->(followee)
             ON CREATE SET r.created_at = $createdAt
             RETURN follower.id AS follower_id,
                    follower.username AS follower_username,
                    follower.full_name AS follower_name,
                    followee.id AS followee_id,
                    followee.username AS followee_username,
                    followee.full_name AS followee_name,
                    r.created_at AS created_at',
            [
                'followerId' => $followerId,
                'followeeId' => $followeeId,
                'createdAt' => $createdAt,
            ]
        );

        if ($result->isEmpty()) {
            return [];
        }

        $record = $result->first();

        return [
            'follower_id' => (string) $record->get('follower_id'),
            'follower_username' => (string) $record->get('follower_username'),
            'follower_name' => (string) $record->get('follower_name'),
            'followee_id' => (string) $record->get('followee_id'),
            'followee_username' => (string) $record->get('followee_username'),
            'followee_name' => (string) $record->get('followee_name'),
            'created_at' => (string) ($record->get('created_at') ?? $createdAt),
        ];
    }

    /**
     * Retrieve all subscription edges in the Neo4j user graph.
     *
     * @return array<int, array<string, string>>
     */
    public function getSubscriptions(): array
    {
        $result = $this->neo4j->run(
            'MATCH (follower:User)-[r:FOLLOWS]->(followee:User)
             RETURN follower.id AS follower_id,
                    follower.username AS follower_username,
                    follower.full_name AS follower_name,
                    followee.id AS followee_id,
                    followee.username AS followee_username,
                    followee.full_name AS followee_name,
                    coalesce(r.created_at, "") AS created_at
             ORDER BY follower.username ASC, followee.username ASC'
        );

        $subscriptions = [];
        foreach ($result as $record) {
            $subscriptions[] = [
                'follower_id' => (string) $record->get('follower_id'),
                'follower_username' => (string) $record->get('follower_username'),
                'follower_name' => (string) $record->get('follower_name'),
                'followee_id' => (string) $record->get('followee_id'),
                'followee_username' => (string) $record->get('followee_username'),
                'followee_name' => (string) $record->get('followee_name'),
                'created_at' => (string) $record->get('created_at'),
            ];
        }

        return $subscriptions;
    }

    /**
     * Find mutual friends / shared subscriptions between two users in Neo4j.
     *
     * @return array<int, array<string, string>>
     */
    public function getMutualFriends(string $userAId, string $userBId): array
    {
        $result = $this->neo4j->run(
            'MATCH (a:User {id: $userAId})-[:FOLLOWS]->(mutual:User)<-[:FOLLOWS]-(b:User {id: $userBId})
             WHERE mutual.id <> $userAId AND mutual.id <> $userBId
             RETURN DISTINCT mutual.id AS id,
                    mutual.username AS username,
                    mutual.full_name AS full_name
             ORDER BY mutual.username ASC',
            [
                'userAId' => $userAId,
                'userBId' => $userBId,
            ]
        );

        $mutuals = [];
        foreach ($result as $record) {
            $mutuals[] = [
                'id' => (string) $record->get('id'),
                'username' => (string) $record->get('username'),
                'full_name' => (string) $record->get('full_name'),
            ];
        }

        return $mutuals;
    }

    /**
     * Determine subscription recommendations for a user using friend-of-friend graph traversal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubscriptionRecommendations(string $userId, int $limit = 10): array
    {
        $result = $this->neo4j->run(
            'MATCH (u:User {id: $userId})-[:FOLLOWS]->(friend:User)-[:FOLLOWS]->(rec:User)
             WHERE rec.id <> $userId AND NOT (u)-[:FOLLOWS]->(rec)
             RETURN rec.id AS id,
                    rec.username AS username,
                    rec.full_name AS full_name,
                    count(DISTINCT friend) AS mutual_count,
                    collect(DISTINCT friend.username) AS followed_by
             ORDER BY mutual_count DESC, rec.username ASC
             LIMIT $limit',
            [
                'userId' => $userId,
                'limit' => $limit,
            ]
        );

        $recommendations = [];
        foreach ($result as $record) {
            $followedByRaw = $record->get('followed_by');
            $followedBy = [];
            if (is_iterable($followedByRaw)) {
                foreach ($followedByRaw as $uname) {
                    $followedBy[] = (string) $uname;
                }
            }

            $recommendations[] = [
                'id' => (string) $record->get('id'),
                'username' => (string) $record->get('username'),
                'full_name' => (string) $record->get('full_name'),
                'mutual_count' => (int) $record->get('mutual_count'),
                'followed_by' => $followedBy,
            ];
        }

        return $recommendations;
    }

    /**
     * Remove all User nodes and FOLLOWS relationships in Neo4j.
     */
    public function clearAll(): void
    {
        $this->neo4j->run('MATCH (u:User) DETACH DELETE u');
    }
}
