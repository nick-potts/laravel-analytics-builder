<?php

namespace Workbench\App\Analytics\Customers;

use NickPotts\Slice\Schemas\Dimension;
use NickPotts\Slice\Schemas\TimeDimension;
use NickPotts\Slice\Tables\Table;
use Workbench\App\Analytics\Dimensions\CountryDimension;

class CustomersTable extends Table
{
    protected string $table = 'customers';

    public function dimensions(): array
    {
        return [
            TimeDimension::class => TimeDimension::make('created_at')
                ->asTimestamp()
                ->minGranularity('day'),
            Dimension::class.'::segment' => Dimension::make('segment')
                ->label('Customer Segment')
                ->type('string'),
            CountryDimension::class => CountryDimension::make('country'),
        ];
    }

    public function relations(): array
    {
        return [];
    }
}
