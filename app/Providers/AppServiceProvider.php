<?php

namespace App\Providers;

use Cassandra\Connection as CassandraConnection;
use Cassandra\Connection\StreamNodeConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface as Neo4jClientInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Neo4jClientInterface::class, function (Application $app): Neo4jClientInterface {
            /** @var array{scheme?: string, host?: string, port?: int, database?: string, username?: string, password?: string} $config */
            $config = $app['config']->get('database.connections.neo4j', []);

            $uri = sprintf(
                '%s://%s:%d?database=%s',
                $config['scheme'] ?? 'bolt',
                $config['host'] ?? 'neo4j',
                (int) ($config['port'] ?? 7687),
                $config['database'] ?? 'neo4j'
            );

            return ClientBuilder::create()
                ->withDriver(
                    'default',
                    $uri,
                    Authenticate::basic(
                        (string) ($config['username'] ?? 'neo4j'),
                        (string) ($config['password'] ?? 'password')
                    )
                )
                ->withDefaultDriver('default')
                ->build();
        });

        $this->app->alias(Neo4jClientInterface::class, 'neo4j');

        $this->app->singleton(CassandraConnection::class, function (Application $app): CassandraConnection {
            /** @var array{host?: string, port?: int, keyspace?: string, username?: string, password?: string} $config */
            $config = $app['config']->get('database.connections.cassandra', []);

            $nodes = [
                new StreamNodeConfig(
                    host: (string) ($config['host'] ?? 'cassandra'),
                    port: (int) ($config['port'] ?? 9042),
                    username: (string) ($config['username'] ?? 'cassandra'),
                    password: (string) ($config['password'] ?? 'cassandra'),
                ),
            ];

            $connection = new CassandraConnection($nodes);
            $connection->connect();

            $keyspace = (string) ($config['keyspace'] ?? '');
            if ($keyspace !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $keyspace) === 1) {
                $connection->query(sprintf(
                    "CREATE KEYSPACE IF NOT EXISTS %s WITH replication = {'class': 'SimpleStrategy', 'replication_factor': 1}",
                    $keyspace
                ));
                $connection->setKeyspace($keyspace);
            }

            return $connection;
        });

        $this->app->alias(CassandraConnection::class, 'cassandra');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
