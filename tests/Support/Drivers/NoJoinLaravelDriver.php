<?php

namespace NickPotts\Slice\Tests\Support\Drivers;

use NickPotts\Slice\Engine\Drivers\LaravelQueryDriver;

class NoJoinLaravelDriver extends LaravelQueryDriver
{
    public function supportsDatabaseJoins(): bool
    {
        return false;
    }
}
