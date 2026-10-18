# Social Network Polyglot Persistence

A social network data storage application built with **Laravel 13**, **PHP 8.5**, **Vue 3**, **PrimeVue**, and **Tailwind CSS**. It demonstrates a polyglot persistence architecture utilizing four distinct database paradigms (MongoDB, Redis, Neo4j, and Cassandra) in a single integrated platform.

---

## Database Implementations

### 1. MongoDB (Document Store)
* **Purpose**: Core entity storage with hierarchical subdocuments and embedded arrays.
* **Collections & Schema**:
  * `social_users`: Stores user identity with an embedded `profile` document (`bio`, `location`, `website`) and an `interests` array of tags.
  * `social_posts`: Stores posts with an embedded `author` object (`id`, `username`, `full_name`, `location`), a `tags` array, and an embedded array of `comments` subdocuments (`id`, `author`, `content`, `created_at`).
* **Operations**:
  * **Embedded Comments**: Appends comments directly into the post document via atomic `$push` and increments comment count with `$inc`, enabling single-document reads for feed rendering.
  * **Search by Author & Date**: Filters posts by `author.id`, BSON `UTCDateTime` date ranges (`$gte`, `$lte`), and exact tag matching.
  * **Most-Liked Users Aggregation**: Runs a native aggregation pipeline (`$group` by `$author.id` summing `likes_count` and counting posts, sorted by `total_likes DESC`) to calculate author popularity rankings.

### 2. Redis (In-Memory Data Structures)
* **Purpose**: Sub-millisecond atomic counters, real-time rankings, and expiring presence tracking.
* **Keys & Data Structures**:
  * **Popularity Leaderboard (`ZSET`)**: Maintained at `social:posts:popularity`. Whenever a post receives a like, view, or comment, its composite score is computed:
    $$\text{Score} = (\text{likes} \times 3) + (\text{comments} \times 2) + (\text{views} \times 1)$$
    The top 10 most popular posts are fetched in $O(\log(N) + M)$ using `ZREVRANGE`.
  * **Atomic Counters**: Dedicated keys for post metrics (`social:post:{id}:likes`, `social:post:{id}:views`, `social:post:{id}:comments`) modified via `INCR`, along with unique like tracking via Sets (`SADD social:post:{id}:liked_by`).
  * **Active User Sessions**: Stored in `social:active_user:{userId}` with a Time-To-Live (TTL) using `SETEX` and indexed in `social:active_users:ids` for real-time presence monitoring.

### 3. Neo4j (Graph Database)
* **Purpose**: Complex social relationship mapping, mutual connection analysis, and recommendation traversals.
* **Graph Structure**:
  * **Nodes**: `(:User {id, username, full_name})`
  * **Relationships**: `(:User)-[:FOLLOWS {created_at}]->(:User)`
* **Cypher Queries**:
  * **Mutual Friends**: Identifies users mutually followed by two separate accounts:
    ```cypher
    MATCH (a:User {id: $userAId})-[:FOLLOWS]->(mutual:User)<-[:FOLLOWS]-(b:User {id: $userBId})
    WHERE mutual.id <> $userAId AND mutual.id <> $userBId
    RETURN DISTINCT mutual.id AS id, mutual.username AS username, mutual.full_name AS full_name
    ```
  * **Subscription Recommendations**: Traverses 2-hop friend-of-friend paths to suggest accounts followed by an account's followed users, ranked by shared connection count:
    ```cypher
    MATCH (u:User {id: $userId})-[:FOLLOWS]->(friend:User)-[:FOLLOWS]->(rec:User)
    WHERE rec.id <> $userId AND NOT (u)-[:FOLLOWS]->(rec)
    RETURN rec.id AS id, rec.username AS username, rec.full_name AS full_name,
           count(DISTINCT friend) AS mutual_count,
           collect(DISTINCT friend.username) AS followed_by
    ORDER BY mutual_count DESC
    LIMIT 10
    ```

### 4. Cassandra (Wide-Column / Time-Series Store)
* **Purpose**: High-throughput, write-heavy, immutable chronological event logging.
* **Tables & Partitioning**:
  * `user_activity_events`:
    * **Partition Key**: `((user_id))` ensures all events for a given user reside sequentially on the same cluster node.
    * **Clustering Key**: `occurred_at DESC, event_id ASC` keeps events pre-sorted chronologically on disk for efficient sequential disk reads.
    * **Queries**: Slices activity history for a specific user over an arbitrary date range (`occurred_at >= ? AND occurred_at <= ?`) or fetches the user's last 20 events.
  * `global_activity_events`:
    * **Partition Key**: `((bucket))` (e.g. `'all'`) with clustering `occurred_at DESC, event_id ASC` to retrieve the latest 20 global network events without distributed scans.

---

## License

This project is open-sourced software licensed under the [MIT license](LICENSE) by **Ruslan Bazavluk**.
