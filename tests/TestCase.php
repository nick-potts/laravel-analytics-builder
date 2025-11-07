<?php

namespace NickPotts\Slice\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use NickPotts\Slice\SliceServiceProvider;

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
        DB::connection()->disconnect();

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

        Artisan::call('migrate:fresh', [
            '--database' => 'testing',
            '--path' => $migrationPath,
            '--realpath' => true,
        ]);
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
