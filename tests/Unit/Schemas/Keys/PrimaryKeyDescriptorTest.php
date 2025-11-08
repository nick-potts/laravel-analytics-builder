<?php

namespace NickPotts\Slice\Tests\Unit\Schemas\Keys;

use NickPotts\Slice\Schemas\Keys\PrimaryKeyDescriptor;

it('creates primary key with single column', function () {
    $pk = new PrimaryKeyDescriptor(['id']);
    expect($pk->isSingle())->toBeTrue();
    expect($pk->isComposite())->toBeFalse();
    expect($pk->column())->toBe('id');
});

it('creates primary key with composite columns', function () {
    $pk = new PrimaryKeyDescriptor(['org_id', 'id']);
    expect($pk->isComposite())->toBeTrue();
    expect($pk->isSingle())->toBeFalse();
    expect($pk->column())->toBeNull();
});

it('gets all columns', function () {
    $pk = new PrimaryKeyDescriptor(['id', 'org_id']);
    expect($pk->getColumns())->toBe(['id', 'org_id']);
});

it('stores auto increment flag', function () {
    $autoIncrement = new PrimaryKeyDescriptor(['id'], true);
    $nonAutoIncrement = new PrimaryKeyDescriptor(['uuid'], false);
    expect($autoIncrement->autoIncrement)->toBeTrue();
    expect($nonAutoIncrement->autoIncrement)->toBeFalse();
});
