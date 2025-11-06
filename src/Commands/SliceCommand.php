<?php

namespace NickPotts\Slice\Commands;

use Illuminate\Console\Command;

class SliceCommand extends Command
{
    public $signature = 'slice';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
