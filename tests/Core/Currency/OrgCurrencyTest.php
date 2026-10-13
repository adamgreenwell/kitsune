<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Query\Expression;
use Kitsune\Core\Models\Org;

it('leaves currency unselected until a supported code is explicitly stored', function (): void {
    $org = Org::create(['name' => 'Acme', 'slug' => 'acme']);

    expect($org->fresh()->currency)->toBeNull();

    $org->currency = 'JPY';
    $org->saveQuietly();
    expect($org->fresh()->currency)->toBe('JPY');

    Org::query()->whereKey($org->getKey())->update(['currency' => 'BHD']);
    expect($org->fresh()->currency)->toBe('BHD');

    $org->currency = null;
    $org->save();
    expect($org->fresh()->currency)->toBeNull();
});

it('checks the final currency on normal and quiet creation', function (mixed $currency, bool $quiet): void {
    $org = new Org(['name' => 'Acme', 'slug' => 'acme', 'currency' => $currency]);

    expect(fn () => $quiet ? $org->saveQuietly() : $org->save())
        ->toThrow(RuntimeException::class, 'currency');
    expect(Org::query()->count())->toBe(0);
})->with([
    'unknown' => ['ZZZ'],
    'lowercase' => ['usd'],
    'padded' => [' USD'],
    'integer' => [840],
    'float' => [840.0],
    'boolean' => [true],
    'array' => [['USD']],
    'expression' => [new Expression("'USD'")],
])->with([false, true]);

it('checks after later model listeners and on quiet updates', function (bool $quiet): void {
    $org = Org::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD']);

    if (! $quiet) {
        Org::updating(static function (Org $row): void {
            $row->currency = 'ZZZ';
        });
        $org->currency = 'EUR';
    } else {
        $org->currency = 'ZZZ';
    }

    expect(fn () => $quiet ? $org->saveQuietly() : $org->save())
        ->toThrow(RuntimeException::class, 'currency');
    expect($org->fresh()->currency)->toBe('USD');
})->with([false, true]);

it('checks bulk and arithmetic extras inside and outside the scope escape hatch', function (Closure $write, bool $escape): void {
    $org = Org::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD']);
    $attempt = static fn () => $escape
        ? Org::withoutScopeBecause('currency boundary test', static fn ($query) => $write($query->whereKey($org->getKey())))
        : $write(Org::query()->whereKey($org->getKey()));

    expect($attempt)->toThrow(RuntimeException::class, 'currency');
    expect($org->fresh()->currency)->toBe('USD');
})->with([
    'update' => [static fn ($query) => $query->update(['currency' => 'ZZZ'])],
    'case-folded column' => [static fn ($query) => $query->update(['CURRENCY' => 'ZZZ'])],
    'qualified column' => [static fn ($query) => $query->update(['orgs.currency' => 'ZZZ'])],
    'expression' => [static fn ($query) => $query->update(['currency' => new Expression("'EUR'")])],
    'JSON path' => [static fn ($query) => $query->update(['currency->code' => 'USD'])],
    'increment extra' => [static fn ($query) => $query->increment('id', 0, ['currency' => 'ZZZ'])],
    'decrement extra' => [static fn ($query) => $query->decrement('id', 0, ['currency' => 'ZZZ'])],
    'incrementEach extra' => [static fn ($query) => $query->incrementEach(['id' => 0], ['currency' => 'ZZZ'])],
    'decrementEach extra' => [static fn ($query) => $query->decrementEach(['id' => 0], ['currency' => 'ZZZ'])],
    'increment target hidden by extra' => [static fn ($query) => $query->increment('currency', 0, ['currency' => 'USD'])],
    'decrement target' => [static fn ($query) => $query->decrement('currency', 0)],
    'incrementEach target' => [static fn ($query) => $query->incrementEach(['currency' => 0])],
    'decrementEach target' => [static fn ($query) => $query->decrementEach(['currency' => 0])],
])->with([false, true]);

it('checks every concrete insert and upsert currency even in the scope escape hatch', function (Closure $write): void {
    $org = Org::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD']);

    expect(fn () => Org::withoutScopeBecause('currency boundary test', static fn ($query) => $write($query, $org)))
        ->toThrow(RuntimeException::class, 'currency');
    expect(Org::query()->count())->toBe(1)
        ->and($org->fresh()->currency)->toBe('USD');
})->with([
    'insert second row' => [static fn ($query) => $query->insert([
        ['name' => 'Good', 'slug' => 'good', 'currency' => 'USD'],
        ['name' => 'Bad', 'slug' => 'bad', 'currency' => 'ZZZ'],
    ])],
    'insertGetId' => [static fn ($query) => $query->insertGetId(['name' => 'Bad', 'slug' => 'bad', 'currency' => 'ZZZ'])],
    'insertOrIgnore' => [static fn ($query) => $query->insertOrIgnore(['name' => 'Bad', 'slug' => 'bad', 'currency' => 'ZZZ'])],
    'upsert row' => [static fn ($query, Org $org) => $query->upsert([
        ['id' => $org->getKey(), 'name' => 'Acme', 'slug' => 'acme', 'currency' => 'ZZZ'],
    ], ['id'], ['currency'])],
    'upsert explicit assignment' => [static fn ($query, Org $org) => $query->upsert([
        ['id' => $org->getKey(), 'name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD'],
    ], ['id'], ['currency' => 'ZZZ'])],
]);

it('refuses uninspectable subquery currencies even in the scope escape hatch', function (string $method): void {
    Org::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD']);

    expect(fn () => Org::withoutScopeBecause('currency boundary test', static fn ($query) => $query->{$method}(
        ['name', 'slug', 'currency'],
        Org::query()->selectRaw("'Other', 'other', 'ZZZ'"),
    )))->toThrow(RuntimeException::class, 'currency');
    expect(Org::query()->count())->toBe(1);
})->with(['insertUsing', 'insertOrIgnoreUsing']);

it('allows concrete supported bulk values without an optional commerce runtime', function (): void {
    $org = Org::createQuietly(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'USD']);
    Org::query()->whereKey($org->getKey())->increment('id', 0, ['currency' => 'EUR']);
    expect($org->fresh()->currency)->toBe('EUR');

    Org::withoutScopeBecause('currency boundary test', static fn ($query) => $query->whereKey($org->getKey())
        ->update(['currency' => null]));
    expect($org->fresh()->currency)->toBeNull();
});
