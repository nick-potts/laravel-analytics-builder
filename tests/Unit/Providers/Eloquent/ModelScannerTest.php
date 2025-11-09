<?php

use NickPotts\Slice\Providers\Eloquent\ModelScanner;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Order;

use function Orchestra\Testbench\workbench_path;

it('scans directory for eloquent models', function () {
    $scanner = new ModelScanner;
    $models = $scanner->scan(
        workbench_path('app/Models'),
        'Workbench\\App\\Models'
    );

    expect(count($models))->toBeGreaterThanOrEqual(4);
    expect(in_array(Order::class, $models))->toBeTrue();
    expect(in_array(Customer::class, $models))->toBeTrue();
});

it('returns empty array for nonexistent directory', function () {
    $scanner = new ModelScanner;
    $models = $scanner->scan('/nonexistent/path', 'Some\\Namespace');

    expect($models)->toBeEmpty();
});

it('returns only eloquent models', function () {
    $scanner = new ModelScanner;
    $models = $scanner->scan(
        workbench_path('app/Models'),
        'Workbench\\App\\Models'
    );

    // All should be subclasses of Eloquent Model
    expect($models)->not->toBeEmpty();
    foreach ($models as $modelClass) {
        expect(class_exists($modelClass))->toBeTrue();
        $reflection = new ReflectionClass($modelClass);
        expect($reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class))->toBeTrue();
    }
});
