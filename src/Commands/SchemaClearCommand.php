<?php

namespace NickPotts\Slice\Commands;

use Illuminate\Console\Command;
use NickPotts\Slice\Support\SchemaProviderManager;

class SchemaClearCommand extends Command
{
    protected $signature = 'slice:schema-clear';

    protected $description = 'Clear Slice schema provider cache';

    public function handle(SchemaProviderManager $manager): int
    {
        $this->info('Clearing schema cache...');

        $cache = $manager->getCache();
        $cache->flush();

        $this->info('Schema cache cleared successfully!');

        return self::SUCCESS;
    }
}
