<?php

namespace NickPotts\Slice\Commands;

use Illuminate\Console\Command;
use NickPotts\Slice\Support\SchemaProviderManager;

class SchemaCacheCommand extends Command
{
    protected $signature = 'slice:schema-cache';

    protected $description = 'Cache Slice schema provider metadata';

    public function handle(SchemaProviderManager $manager): int
    {
        $this->info('Building schema cache...');

        // Get the cache instance
        $cache = $manager->getCache();

        // Force a fresh scan by disabling cache temporarily
        $cache->flush();
        $cache->enable();

        // Re-boot all providers to force introspection
        $providers = $manager->getProviders();
        foreach ($providers as $provider) {
            $this->info("  Scanning provider: {$provider->name()}");
            $provider->boot($cache);
        }

        $this->info('Schema cache built successfully!');
        $this->line('Cache size: '.$cache->size().' entries');

        return self::SUCCESS;
    }
}
