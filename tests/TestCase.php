<?php

namespace NickPotts\Slice\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use NickPotts\Slice\SliceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected string $connection;

    protected function setUp(): void
    {
        // Allow env variable to override database driver
        $this->connection = getenv('DB_CONNECTION') ?: 'sqlite';

        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'NickPotts\\Slice\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->migrateDatabase();
    }

    protected function tearDown(): void
    {
        // Clean disconnect between tests
        // TODO: verify not needed
        //        DB::connection()->disconnect();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            SliceServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $config = require __DIR__.'/config/database.php';

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $config[$this->connection]);
    }

    protected function migrateDatabase(): void
    {
        $migrationPath = realpath(__DIR__.'/../workbench/database/migrations')
            ?: __DIR__.'/../workbench/database/migrations';

        // SingleStore doesn't support dropping multiple tables in a single query
        // So we need to drop tables individually and then migrate (not migrate:fresh)
        if ($this->connection === 'singlestore') {
            $this->dropTablesIndividually();
            Artisan::call('migrate', [
                '--database' => 'testing',
                '--path' => $migrationPath,
                '--realpath' => true,
            ]);
        } else {
            Artisan::call('migrate:fresh', [
                '--database' => 'testing',
                '--path' => $migrationPath,
                '--realpath' => true,
            ]);
        }
    }

    protected function dropTablesIndividually(): void
    {
        $tables = DB::connection('testing')->select('SHOW TABLES');
        $databaseName = DB::connection('testing')->getDatabaseName();
        $tableKey = "Tables_in_{$databaseName}";

        foreach ($tables as $table) {
            $tableName = $table->$tableKey;
            DB::connection('testing')->statement("DROP TABLE IF EXISTS `{$tableName}`");
        }
    }

    protected function seedDatabase(): void
    {
        Model::unguard();

        // Seed database using workbench seeders
        $seederClass = 'Workbench\Database\Seeders\DatabaseSeeder';

        if (! class_exists($seederClass)) {
            return;
        }

        app($seederClass)->run();

        Model::reguard();
    }
}
