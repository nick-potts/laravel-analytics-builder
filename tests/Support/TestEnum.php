<?php

namespace NickPotts\Slice\Tests\Support;

enum TestEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
