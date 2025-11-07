<?php

namespace NickPotts\Slice\Engine\Drivers;

use Illuminate\Support\Facades\DB;
use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Engine\Grammar\FirebirdGrammar;
use NickPotts\Slice\Engine\Grammar\MariaDbGrammar;
use NickPotts\Slice\Engine\Grammar\MySqlGrammar;
use NickPotts\Slice\Engine\Grammar\PostgresGrammar;
use NickPotts\Slice\Engine\Grammar\QueryGrammar;
use NickPotts\Slice\Engine\Grammar\SingleStoreGrammar;
use NickPotts\Slice\Engine\Grammar\SqliteGrammar;
use NickPotts\Slice\Engine\Grammar\SqlServerGrammar;
use Staudenmeir\LaravelCte\Query\Builder as CteBuilder;

class LaravelQueryDriver implements QueryDriver
{
    /**
     * Custom grammar registry for third-party drivers.
     *
     * @var array<string, class-string<QueryGrammar>>
     */
    protected static array $customGrammars = [];

    protected QueryGrammar $grammar;

    public function __construct(
        protected ?string $connection = null
    ) {
        $this->grammar = $this->resolveGrammar();
    }

    public function name(): string
    {
        return DB::connection($this->connection)->getDriverName();
    }

    public function createQuery(?string $table = null): QueryAdapter
    {
        $connection = DB::connection($this->connection);

        // Start with the CTE-capable builder if available
        if (class_exists(CteBuilder::class)) {
            $builder = new CteBuilder($connection);
            if ($table) {
                $builder->from($table);
            }
        } else {
            $builder = $table ? $connection->table($table) : $connection->query();
        }

        return new LaravelQueryAdapter($builder);
    }

    public function grammar(): QueryGrammar
    {
        return $this->grammar;
    }

    /**
     * Register a custom grammar for a database driver.
     *
     * Example:
     * LaravelQueryDriver::extend('clickhouse', ClickhouseGrammar::class);
     *
     * @param  class-string<QueryGrammar>  $grammarClass
     */
    public static function extend(string $driverName, string $grammarClass): void
    {
        static::$customGrammars[$driverName] = $grammarClass;
    }

    /**
     * Get all registered custom grammars.
     *
     * @return array<string, class-string<QueryGrammar>>
     */
    public static function getCustomGrammars(): array
    {
        return static::$customGrammars;
    }

    /**
     * Clear all custom grammar registrations (useful for testing).
     */
    public static function clearCustomGrammars(): void
    {
        static::$customGrammars = [];
    }

    protected function resolveGrammar(): QueryGrammar
    {
        $driverName = DB::connection($this->connection)->getDriverName();

        // Check for custom grammar first
        if (isset(static::$customGrammars[$driverName])) {
            $grammarClass = static::$customGrammars[$driverName];

            return new $grammarClass;
        }

        // Built-in grammars
        return match ($driverName) {
            'mysql' => new MySqlGrammar,
            'mariadb' => new MariaDbGrammar,
            'pgsql' => new PostgresGrammar,
            'sqlsrv' => new SqlServerGrammar,
            'singlestore' => new SingleStoreGrammar,
            'firebird' => new FirebirdGrammar,
            'sqlite' => new SqliteGrammar,
            default => new MySqlGrammar, // Default fallback
        };
    }

    public function supportsDatabaseJoins(): bool
    {
        return true;
    }

    public function supportsCTEs(): bool
    {
        return class_exists(CteBuilder::class);
    }
}
