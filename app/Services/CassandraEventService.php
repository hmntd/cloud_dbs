<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Cassandra\Connection as CassandraConnection;
use Illuminate\Support\Str;

class CassandraEventService
{
    private bool $schemaInitialized = false;

    public function __construct(
        private readonly CassandraConnection $cassandra,
    ) {}

    /**
     * Ensure Cassandra tables for user and global activity events exist.
     */
    public function ensureSchema(): void
    {
        if ($this->schemaInitialized) {
            return;
        }

        $this->cassandra->query(
            'CREATE TABLE IF NOT EXISTS user_activity_events (
                user_id text,
                occurred_at bigint,
                event_id text,
                username text,
                event_type text,
                target_id text,
                description text,
                metadata text,
                PRIMARY KEY ((user_id), occurred_at, event_id)
            ) WITH CLUSTERING ORDER BY (occurred_at DESC, event_id ASC)'
        );

        $this->cassandra->query(
            'CREATE TABLE IF NOT EXISTS global_activity_events (
                bucket text,
                occurred_at bigint,
                event_id text,
                user_id text,
                username text,
                event_type text,
                target_id text,
                description text,
                metadata text,
                PRIMARY KEY ((bucket), occurred_at, event_id)
            ) WITH CLUSTERING ORDER BY (occurred_at DESC, event_id ASC)'
        );

        $this->schemaInitialized = true;
    }

    /**
     * Record a post, like, comment, view, or follow event in Cassandra.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function recordEvent(
        string $userId,
        string $username,
        string $eventType,
        string $targetId,
        string $description,
        array $metadata = [],
        ?int $occurredAtMs = null,
    ): array {
        $this->ensureSchema();

        $timestampMs = $occurredAtMs ?? (int) round(microtime(true) * 1000);
        $eventId = (string) Str::uuid();
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $userPrepared = $this->cassandra->prepare(
            'INSERT INTO user_activity_events (user_id, occurred_at, event_id, username, event_type, target_id, description, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $this->cassandra->execute($userPrepared, [
            $userId,
            $timestampMs,
            $eventId,
            $username,
            $eventType,
            $targetId,
            $description,
            $metadataJson,
        ]);

        $globalPrepared = $this->cassandra->prepare(
            'INSERT INTO global_activity_events (bucket, occurred_at, event_id, user_id, username, event_type, target_id, description, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $this->cassandra->execute($globalPrepared, [
            'all',
            $timestampMs,
            $eventId,
            $userId,
            $username,
            $eventType,
            $targetId,
            $description,
            $metadataJson,
        ]);

        return [
            'event_id' => $eventId,
            'user_id' => $userId,
            'username' => $username,
            'event_type' => $eventType,
            'target_id' => $targetId,
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at_ms' => $timestampMs,
            'occurred_at' => CarbonImmutable::createFromTimestampMsUTC($timestampMs)->toIso8601String(),
        ];
    }

    /**
     * Get a specific user's activity history for a given period (up to the last N events).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUserHistory(
        string $userId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 20,
    ): array {
        $this->ensureSchema();

        $conditions = ['user_id = ?'];
        $params = [$userId];

        if ($dateFrom !== null && $dateFrom !== '') {
            $fromMs = CarbonImmutable::parse($dateFrom)->startOfDay()->getTimestampMs();
            $conditions[] = 'occurred_at >= ?';
            $params[] = $fromMs;
        }

        if ($dateTo !== null && $dateTo !== '') {
            $toMs = CarbonImmutable::parse($dateTo)->endOfDay()->getTimestampMs();
            $conditions[] = 'occurred_at <= ?';
            $params[] = $toMs;
        }

        $cql = sprintf(
            'SELECT user_id, occurred_at, event_id, username, event_type, target_id, description, metadata
             FROM user_activity_events
             WHERE %s
             LIMIT %d',
            implode(' AND ', $conditions),
            max(1, min(100, $limit))
        );

        $prepared = $this->cassandra->prepare($cql);
        $rows = $this->cassandra->execute($prepared, $params)->asRowsResult();

        return $this->formatRows($rows->fetchAll());
    }

    /**
     * Get the last N events across the social network.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentEvents(int $limit = 20): array
    {
        $this->ensureSchema();

        $cql = sprintf(
            "SELECT user_id, occurred_at, event_id, username, event_type, target_id, description, metadata
             FROM global_activity_events
             WHERE bucket = 'all'
             LIMIT %d",
            max(1, min(100, $limit))
        );

        $rows = $this->cassandra->query($cql)->asRowsResult();

        return $this->formatRows($rows->fetchAll());
    }

    /**
     * Truncate Cassandra event tables.
     */
    public function clearAll(): void
    {
        $this->ensureSchema();
        $this->cassandra->query('TRUNCATE user_activity_events');
        $this->cassandra->query('TRUNCATE global_activity_events');
    }

    /**
     * @param  array<int, array<string|int, mixed>>  $rawRows
     * @return array<int, array<string, mixed>>
     */
    private function formatRows(array $rawRows): array
    {
        $events = [];

        foreach ($rawRows as $row) {
            $timestampMs = (int) ($row['occurred_at'] ?? 0);
            $decodedMeta = json_decode((string) ($row['metadata'] ?? '{}'), true);

            $events[] = [
                'event_id' => (string) ($row['event_id'] ?? ''),
                'user_id' => (string) ($row['user_id'] ?? ''),
                'username' => (string) ($row['username'] ?? ''),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'target_id' => (string) ($row['target_id'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'metadata' => is_array($decodedMeta) ? $decodedMeta : [],
                'occurred_at_ms' => $timestampMs,
                'occurred_at' => CarbonImmutable::createFromTimestampMsUTC($timestampMs)->toIso8601String(),
            ];
        }

        return $events;
    }
}
