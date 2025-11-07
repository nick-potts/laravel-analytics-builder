<?php

namespace NickPotts\Slice\Engine\Drivers;

use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\Grammar\ClickHouseGrammar;
use NickPotts\Slice\Engine\Grammar\QueryGrammar;

/**
 * Driver for ClickHouse using a ClickHouse PHP client.
 *
 * Install: composer require tinkerbell/clickhouse-php
 * Or any other ClickHouse PHP client that implements select() method.
 *
 * Configuration example:
 * 'clickhouse' => [
 *     'driver' => 'clickhouse',
 *     'host' => env('CLICKHOUSE_HOST', 'localhost'),
 *     'port' => env('CLICKHOUSE_PORT', 8123),
 *     'username' => env('CLICKHOUSE_USERNAME', 'default'),
 *     'password' => env('CLICKHOUSE_PASSWORD', ''),
 *     'database' => env('CLICKHOUSE_DATABASE', 'default'),
 * ]
 */
class ClickHouseDriver implements QueryDriver
{
    protected QueryGrammar $grammar;

    public function __construct(
        protected mixed $client = null
    ) {
        $this->client ??= $this->createClient();
        $this->grammar = new ClickHouseGrammar;
    }

    public function name(): string
    {
        return 'clickhouse';
    }

    public function createQuery(?string $table = null): QueryAdapter
    {
        return new ClickHouseQueryAdapter($this->client, $table);
    }

    public function grammar(): QueryGrammar
    {
        return $this->grammar;
    }

    public function supportsDatabaseJoins(): bool
    {
        return true; // ClickHouse supports JOINs
    }

    public function supportsCTEs(): bool
    {
        return true; // ClickHouse supports WITH clauses
    }

    /**
     * Create a ClickHouse client instance.
     * Override this method or pass a client to constructor for custom implementations.
     */
    protected function createClient(): mixed
    {
        // Check if we have config for ClickHouse
        if (! function_exists('config')) {
            throw new \RuntimeException('Cannot create ClickHouse client: config helper not available');
        }

        $config = config('database.connections.clickhouse');

        if (! $config) {
            throw new \RuntimeException(
                'ClickHouse connection not configured. Add "clickhouse" to config/database.php connections array.'
            );
        }

        // Try to create client using common ClickHouse libraries
        if (class_exists(\ClickHouseDB\Client::class)) {
            // Using tinkerbell/clickhouse-php
            return new \ClickHouseDB\Client([
                'host' => $config['host'] ?? 'localhost',
                'port' => $config['port'] ?? 8123,
                'username' => $config['username'] ?? 'default',
                'password' => $config['password'] ?? '',
            ]);
        }

        // For testing without ClickHouse: return a mock callable
        return function ($sql) {
            throw new \RuntimeException(
                "ClickHouse client not installed. Run: composer require tinkerbell/clickhouse-php\n".
                "Query would be: {$sql}"
            );
        };
    }
}
