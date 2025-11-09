<?php

namespace NickPotts\Slice\Engine;

use Illuminate\Database\ConnectionInterface;
use NickPotts\Slice\Engine\Joins\JoinResolver;
use NickPotts\Slice\Support\MetricSource;
use NickPotts\Slice\Support\SchemaProviderManager;

/**
 * Builds query plans from normalized metrics
 *
 * Takes MetricSource objects from SchemaProviderManager and builds
 * a QueryPlan that can be executed.
 */
class QueryBuilder
{
    private SchemaProviderManager $manager;

    private JoinResolver $joinResolver;

    /**
     * Metrics to include in the query, keyed by their source
     *
     * @var array<string, MetricSource>
     */
    private array $metrics = [];

    /**
     * Tables involved in the query (de-duped)
     *
     * @var array<string, \Slice\Contracts\TableContract>
     */
    private array $tables = [];

    /**
     * Connection to use (if multi-table, all must be on same connection)
     */
    private ?ConnectionInterface $connection = null;

    public function __construct(SchemaProviderManager $manager, JoinResolver $joinResolver)
    {
        $this->manager = $manager;
        $this->joinResolver = $joinResolver;
    }

    /**
     * Add normalized metrics to the query
     *
     * @param  array<array{source: MetricSource, aggregation: \Slice\Metrics\Aggregations\Aggregation}>  $normalizedMetrics
     */
    public function addMetrics(array $normalizedMetrics): self
    {
        foreach ($normalizedMetrics as $item) {
            $source = $item['source'];
            $key = $source->key();

            $this->metrics[$key] = $source;

            // Extract table and validate connection consistency
            $table = $source->table;
            $connection = $source->getConnection();

            $isNewTable = ! isset($this->tables[$table->name()]);
            if ($isNewTable) {
                $this->tables[$table->name()] = $table;
            }

            if ($this->connection === null) {
                $this->connection = $this->getConnectionFromTable($table, $connection);
            } elseif ($isNewTable && $this->connection->getName() !== ($connection ?? $table->connection())) {
                throw new \RuntimeException(
                    'Cannot mix tables from different connections: '.
                    $this->connection->getName().' and '.$table->connection()
                );
            }
        }

        return $this;
    }

    /**
     * Build the query plan
     */
    public function build(): QueryPlan
    {
        if (empty($this->tables)) {
            throw new \RuntimeException('No tables selected for query');
        }

        $primaryTable = reset($this->tables);

        // Resolve joins for all tables
        $joinPlan = $this->joinResolver->resolve(array_values($this->tables));

        return new QueryPlan(
            primaryTable: $primaryTable,
            tables: $this->tables,
            metrics: $this->metrics,
            joinPlan: $joinPlan,
            connection: $this->connection,
        );
    }

    /**
     * Get the connection for a table
     */
    private function getConnectionFromTable($table, ?string $connectionName): ConnectionInterface
    {
        $connectionName = $connectionName ?? $table->connection();

        return \DB::connection($connectionName);
    }
}
