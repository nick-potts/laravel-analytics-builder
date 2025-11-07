<?php

use NickPotts\Slice\Engine\Drivers\ClickHouseDriver;
use NickPotts\Slice\Engine\Drivers\ClickHouseQueryAdapter;
use NickPotts\Slice\Engine\Grammar\ClickHouseGrammar;

it('creates driver with custom client', function () {
    $mockClient = function ($sql) {
        return ['result' => $sql];
    };

    $driver = new ClickHouseDriver($mockClient);

    expect($driver->name())->toBe('clickhouse')
        ->and($driver->supportsDatabaseJoins())->toBeTrue()
        ->and($driver->supportsCTEs())->toBeTrue();
});

it('creates query adapter for table', function () {
    $mockClient = function () {
        return [];
    };

    $driver = new ClickHouseDriver($mockClient);
    $adapter = $driver->createQuery('orders');

    expect($adapter)->toBeInstanceOf(ClickHouseQueryAdapter::class)
        ->and($adapter->getDriverName())->toBe('clickhouse')
        ->and($adapter->supportsCTEs())->toBeTrue();
});

it('uses ClickHouse grammar', function () {
    $mockClient = function () {
        return [];
    };

    $driver = new ClickHouseDriver($mockClient);
    $grammar = $driver->grammar();

    expect($grammar)->toBeInstanceOf(ClickHouseGrammar::class);
});

it('ClickHouse grammar formats time buckets correctly', function () {
    $grammar = new ClickHouseGrammar;

    expect($grammar->formatTimeBucket('orders', 'created_at', 'hour'))
        ->toBe('toStartOfHour(orders.created_at)')
        ->and($grammar->formatTimeBucket('orders', 'created_at', 'day'))
        ->toBe('toStartOfDay(orders.created_at)')
        ->and($grammar->formatTimeBucket('orders', 'created_at', 'week'))
        ->toBe('toMonday(orders.created_at)')
        ->and($grammar->formatTimeBucket('orders', 'created_at', 'month'))
        ->toBe('toStartOfMonth(orders.created_at)')
        ->and($grammar->formatTimeBucket('orders', 'created_at', 'year'))
        ->toBe('toStartOfYear(orders.created_at)');
});

it('ClickHouse adapter builds simple SELECT query', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->selectRaw('SUM(total) as revenue');
    $adapter->groupBy('date');

    $sql = $adapter->toSQL();

    expect($sql)->toContain('SELECT SUM(total) as revenue')
        ->and($sql)->toContain('FROM orders')
        ->and($sql)->toContain('GROUP BY date');
});

it('ClickHouse adapter builds query with joins', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->selectRaw('orders.total');
    $adapter->selectRaw('customers.name');
    $adapter->join('customers', 'orders.customer_id', '=', 'customers.id', 'inner');

    $sql = $adapter->toSQL();

    expect($sql)->toContain('SELECT orders.total, customers.name')
        ->and($sql)->toContain('FROM orders')
        ->and($sql)->toContain('INNER JOIN customers ON orders.customer_id = customers.id');
});

it('ClickHouse adapter builds query with WHERE clauses', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->selectRaw('*');
    $adapter->where('status', '=', 'completed');
    $adapter->whereIn('country', ['US', 'CA', 'UK']);
    $adapter->whereNotIn('payment_method', ['cash']);

    $sql = $adapter->toSQL();

    expect($sql)->toContain("WHERE status = 'completed'")
        ->and($sql)->toContain("country IN ('US', 'CA', 'UK')")
        ->and($sql)->toContain("payment_method NOT IN ('cash')");
});

it('ClickHouse adapter builds query with CTEs', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient);

    // Create CTE subquery
    $cteAdapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $cteAdapter->selectRaw('DATE(created_at) as date');
    $cteAdapter->selectRaw('SUM(total) as revenue');
    $cteAdapter->groupBy('DATE(created_at)');

    $adapter->withExpression('daily_revenue', $cteAdapter);
    $adapter->from('daily_revenue');
    $adapter->select('*');

    $sql = $adapter->toSQL();

    expect($sql)->toContain('WITH daily_revenue AS')
        ->and($sql)->toContain('SELECT DATE(created_at) as date')
        ->and($sql)->toContain('FROM orders')
        ->and($sql)->toContain('GROUP BY DATE(created_at)')
        ->and($sql)->toContain('SELECT * FROM daily_revenue');
});

it('ClickHouse adapter builds multi-level CTEs', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient);

    // Level 0 CTE
    $level0 = new ClickHouseQueryAdapter($mockClient, 'orders');
    $level0->selectRaw('SUM(total) as revenue');
    $level0->selectRaw('SUM(cost) as cost');

    // Level 1 CTE
    $level1 = new ClickHouseQueryAdapter($mockClient, 'level_0');
    $level1->select('*');
    $level1->selectRaw('revenue - cost as profit');

    $adapter->withExpression('level_0', $level0);
    $adapter->withExpression('level_1', $level1);
    $adapter->from('level_1');
    $adapter->select('*');

    $sql = $adapter->toSQL();

    expect($sql)->toContain('WITH level_0 AS')
        ->and($sql)->toContain('level_1 AS')
        ->and($sql)->toContain('revenue - cost as profit')
        ->and($sql)->toContain('SELECT * FROM level_1');
});

it('ClickHouse adapter handles NULL values in WHERE clauses', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->selectRaw('*');
    $adapter->where('deleted_at', '=', null);

    $sql = $adapter->toSQL();

    expect($sql)->toContain('WHERE deleted_at = NULL');
});

it('ClickHouse adapter quotes string values safely', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->selectRaw('*');
    $adapter->where('status', '=', "test's value");

    $sql = $adapter->toSQL();

    expect($sql)->toContain("status = 'test\\'s value'");
});

it('ClickHouse adapter executes query via client', function () {
    $executedSql = null;

    $mockClient = function ($sql) use (&$executedSql) {
        $executedSql = $sql;

        return ['rows' => [['revenue' => 1000]]];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->selectRaw('SUM(total) as revenue');

    $result = $adapter->execute();

    expect($executedSql)->toContain('SELECT SUM(total) as revenue')
        ->and($result)->toBe(['rows' => [['revenue' => 1000]]]);
});

it('ClickHouse adapter supports from() without initial table', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient);
    $adapter->from('orders');
    $adapter->selectRaw('*');

    $sql = $adapter->toSQL();

    expect($sql)->toContain('FROM orders');
});

it('ClickHouse adapter supports select() with array', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->select(['id', 'total', 'created_at']);

    $sql = $adapter->toSQL();

    expect($sql)->toContain('SELECT id, total, created_at');
});

it('ClickHouse adapter supports select() with string', function () {
    $mockClient = function () {
        return [];
    };

    $adapter = new ClickHouseQueryAdapter($mockClient, 'orders');
    $adapter->select('*');

    $sql = $adapter->toSQL();

    expect($sql)->toContain('SELECT *');
});
