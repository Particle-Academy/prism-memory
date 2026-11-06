<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Memory\Exceptions\UnsafeMemoryConfiguration;
use Prism\Memory\PrismMemoryServiceProvider;

it('rejects an unsafe extension schema during boot before any SQL', function (mixed $schema): void {
    $queries = [];
    DB::listen(function () use (&$queries): void {
        $queries[] = 'executed';
    });
    config()->set('memory.drivers.pgvector.extension_schema', $schema);

    $error = null;
    try {
        (new PrismMemoryServiceProvider(app()))->boot();
    } catch (UnsafeMemoryConfiguration $caught) {
        $error = $caught;
    }

    expect($error)->toBeInstanceOf(UnsafeMemoryConfiguration::class)
        ->and($queries)->toBe([]);
})->with([
    'empty' => [''],
    'padded' => [' public'],
    'trailing newline' => ["public\n"],
    'qualified name' => ['public.vector'],
    'quote' => ['private"schema'],
    'statement delimiter' => ['private;drop'],
    'digit first' => ['9schema'],
    'over PostgreSQL limit' => [str_repeat('a', 64)],
    'Unicode' => ['schéma'],
    'NUL' => ["private\0schema"],
    'boolean' => [false],
    'integer' => [0],
    'array' => [[]],
]);

it('accepts the nullable default and exact ASCII extension identifiers', function (?string $schema): void {
    config()->set('memory.drivers.pgvector.extension_schema', $schema);
    (new PrismMemoryServiceProvider(app()))->boot();

    expect(config('memory.drivers.pgvector.extension_schema'))->toBe($schema);
})->with([null, 'public', '_extensions', 'UpperCase17', str_repeat('a', 63)]);
