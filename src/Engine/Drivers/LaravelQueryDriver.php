<?php

namespace NickPotts\Slice\Engine\Drivers;

use Illuminate\Support\Facades\DB;
use NickPotts\Slice\Contracts\QueryAdapter;
use NickPotts\Slice\Contracts\QueryDriver;
use NickPotts\Slice\Contracts\QueryGrammar;
use NickPotts\Slice\Contracts\TableContract;
use NickPotts\Slice\Engine\Grammar\MySQLGrammar;
use NickPotts\Slice\Engine\Grammar\PostgresGrammar;
use NickPotts\Slice\Engine\Grammar\SqliteGrammar;

/**
 * Query driver for Laravel-supported databases.
 *
 * Supports: MySQL, PostgreSQL, SQLite, SQL Server, MariaDB, etc.
 * Uses Laravel's Query Builder for query execution.
 */
class LaravelQueryDriver implements QueryDriver
{
    protected QueryGrammar $grammar;

    /**
     * Create a new Laravel query driver.
     *
     * @param  string|null  $connection  Database connection name
     */
    public function __construct(
        protected ?string $connection = null
    ) {
        $this->grammar = $this->resolveGrammar();
    }

    public function name(): string
    {
        return 'laravel';
    }

    public function query(TableContract $table): QueryAdapter
    {
        $connection = $table->connection() ?? $this->connection;

        $query = DB::connection($connection)->table($table->name());

        return new LaravelQueryAdapter($query);
    }

    public function supportsJoins(): bool
    {
        return true; // Laravel Query Builder supports joins
    }

    public function supportsCTEs(): bool
    {
        // Check driver capability
        $driver = DB::connection($this->connection)->getDriverName();

        return in_array($driver, ['mysql', 'pgsql', 'sqlite', 'sqlsrv']);
    }

    public function grammar(): QueryGrammar
    {
        return $this->grammar;
    }

    /**
     * Resolve the appropriate grammar based on database driver.
     */
    protected function resolveGrammar(): QueryGrammar
    {
        $driver = DB::connection($this->connection)->getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => new MySQLGrammar,
            'pgsql' => new PostgresGrammar,
            'sqlite' => new SqliteGrammar,
            default => new MySQLGrammar, // Default fallback
        };
    }
}
