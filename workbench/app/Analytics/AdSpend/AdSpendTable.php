<?php

namespace Workbench\App\Analytics\AdSpend;

use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Tables\Table;

class AdSpendTable extends Table
{
    protected string $table = 'ad_spend';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('date')
                ->asDate()
                ->minGranularity('day'),
            Dimension::class.'::channel' => Dimension::make('channel')
                ->label('Marketing Channel')
                ->type('string'),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
