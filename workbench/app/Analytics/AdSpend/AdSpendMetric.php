<?php

namespace Workbench\App\Analytics\AdSpend;

use NickPotts\Slice\Contracts\EnumMetric;
use NickPotts\Slice\Contracts\Metric;
use NickPotts\Slice\Contracts\MetricContract;
use NickPotts\Slice\Metrics\Computed;
use NickPotts\Slice\Metrics\Sum;
use NickPotts\Slice\Tables\Table;

enum AdSpendMetric: string implements MetricContract
{
    use EnumMetric;
    case Spend = 'spend';
    case Impressions = 'impressions';
    case Clicks = 'clicks';
    case Conversions = 'conversions';
    case CTR = 'ctr';
    case CPA = 'cpa';

    public function table(): Table
    {
        return new AdSpendTable();
    }

    public function get(): Metric
    {
        return match ($this) {
            self::Spend => Sum::make('ad_spend.spend')
                ->label('Ad Spend')
                ->currency('USD'),

            self::Impressions => Sum::make('ad_spend.impressions')
                ->label('Impressions')
                ->decimals(0),

            self::Clicks => Sum::make('ad_spend.clicks')
                ->label('Clicks')
                ->decimals(0),

            self::Conversions => Sum::make('ad_spend.conversions')
                ->label('Conversions')
                ->decimals(0),

            self::CTR => Computed::make('(clicks / impressions) * 100')
                ->label('Click-Through Rate')
                ->dependsOn('ad_spend.clicks', 'ad_spend.impressions')
                ->percentage()
                ->forTable($this->table()),

            self::CPA => Computed::make('spend / conversions')
                ->label('Cost Per Acquisition')
                ->dependsOn('ad_spend.spend', 'ad_spend.conversions')
                ->currency('USD')
                ->forTable($this->table()),
        };
    }
}

