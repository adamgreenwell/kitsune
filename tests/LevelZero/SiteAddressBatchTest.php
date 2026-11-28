<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Batch', 'slug' => 'batch']);
    app(Context::class)->setOrg($this->org);
});

function addressBatchSite(string $handle, ?string $address = null): Site
{
    return new Site(['handle' => $handle, 'slug' => $handle, 'name' => $handle, 'locale' => 'en', 'base_url' => $address]);
}

it('locks the sorted origin and destination union before saving moves and creates in input order', function (): void {
    $first = addressBatchSite('first', 'https://b.test');
    $second = addressBatchSite('second', 'https://a.test');
    $first->save();
    $second->save();
    $new = addressBatchSite('admin-only');
    $first->base_url = 'https://c.test';
    $second->base_url = 'https://d.test';

    $sequence = [];
    DB::listen(function (QueryExecuted $query) use (&$sequence): void {
        if (str_starts_with($query->sql, 'insert into "site_host_claims"')) {
            $sequence[] = $query->bindings[0];
        }
    });
    Site::saving(function (Site $site) use (&$sequence): void {
        $sequence[] = $site->handle;
    });

    [$movedFirst, $created, $movedSecond] = Site::saveAllInHostOrder([$first, $new, $second]);

    expect($sequence)->toBe(['a.test', 'b.test', 'c.test', 'd.test', 'first', 'admin-only', 'second'])
        ->and($movedFirst->fresh()->canonical_host)->toBe('c.test')
        ->and($movedSecond->fresh()->canonical_host)->toBe('d.test')
        ->and($created->fresh()->canonical_host)->toBeNull()
        ->and($first->getRawOriginal('canonical_host'))->toBe('b.test')
        ->and($first->isDirty('base_url'))->toBeTrue()
        ->and($new->exists)->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0);
});

it('rolls back a cancelled batch and lets the same prepared models be retried', function (): void {
    $first = addressBatchSite('first', 'https://before.test');
    $first->save();
    $first->base_url = 'https://after.test';
    $second = addressBatchSite('second', 'https://created.test');
    $cancel = true;
    $committed = [];
    Site::saving(function (Site $site) use (&$cancel): ?bool {
        return $site->handle === 'second' && $cancel ? false : null;
    });
    Site::saved(function (Site $site) use (&$committed): void {
        DB::afterCommit(function () use ($site, &$committed): void {
            $committed[] = $site->handle;
        });
    });

    expect(fn () => Site::saveAllInHostOrder([$first, $second]))->toThrow(RuntimeException::class, 'cancelled')
        ->and($first->fresh()->canonical_host)->toBe('before.test')
        ->and($first->isDirty('base_url'))->toBeTrue()
        ->and($second->exists)->toBeFalse()
        ->and($committed)->toBe([])
        ->and(DB::table('site_host_claims')->where('canonical_host', 'after.test')->exists())->toBeFalse();

    $cancel = false;
    [$moved, $created] = Site::saveAllInHostOrder([$first, $second]);
    expect($moved->fresh()->canonical_host)->toBe('after.test')
        ->and($created->fresh()->canonical_host)->toBe('created.test')
        ->and($committed)->toBe(['first', 'second']);
});

it('refuses outer transactions opened through Laravel or directly through PDO', function (): void {
    $site = addressBatchSite('first', 'https://nested.test');
    DB::transaction(function () use ($site): void {
        expect(fn () => Site::saveAllInHostOrder([$site]))->toThrow(RuntimeException::class, 'own transaction');
    });

    $pdo = DB::connection()->getPdo();
    $pdo->beginTransaction();
    try {
        expect(fn () => Site::saveAllInHostOrder([$site]))->toThrow(RuntimeException::class, 'own transaction');
    } finally {
        $pdo->rollBack();
    }

    expect(Site::query()->count())->toBe(0)->and(DB::table('site_host_claims')->count())->toBe(0);
});

it('refuses another Org, another connection, and duplicate rows before acquiring claims', function (): void {
    $site = addressBatchSite('first', 'https://first.test');
    $site->save();
    $batch = [$site, addressBatchSite('foreign', 'https://foreign.test')->fill(['org_id' => $this->org->id + 1])];
    expect(fn () => Site::saveAllInHostOrder($batch))->toThrow(InvalidArgumentException::class, 'current Org');
    $batch[1] = addressBatchSite('other', 'https://other.test')->setConnection('secondary');
    expect(fn () => Site::saveAllInHostOrder($batch))->toThrow(InvalidArgumentException::class, 'one connection')
        ->and(fn () => Site::saveAllInHostOrder([$site, $site->fresh()]))->toThrow(InvalidArgumentException::class, 'only once')
        ->and(DB::table('site_host_claims')->pluck('canonical_host')->all())->toBe(['first.test']);
});

it('refuses an observer changing the prepared address or adding another Site write', function (string $observer): void {
    $site = addressBatchSite('original', 'https://original.test');
    $site->save();
    $site->base_url = 'https://prepared.test';

    Site::saving(function (Site $saving) use ($observer): void {
        if ($observer === 'change') {
            $saving->base_url = 'https://observer.test';

            return;
        }

        if ($observer === 'change-connection') {
            $saving->setConnection('secondary');

            return;
        }

        $extra = addressBatchSite('observer', 'https://observer.test');
        if ($observer === 'other-connection') {
            $extra->setConnection('secondary');
        }

        $extra->save();
    });

    expect(fn () => Site::saveAllInHostOrder([$site]))->toThrow(RuntimeException::class, 'batch')
        ->and($site->fresh()->canonical_host)->toBe('original.test')
        ->and(DB::table('site_host_claims')->pluck('canonical_host')->all())->toBe(['original.test']);
})->with(['change', 'change-connection', 'append', 'other-connection']);

it('rolls back the union when a prepared Site has moved or disappeared', function (bool $deleted): void {
    $site = addressBatchSite('first', 'https://before.test');
    $site->save();
    $stale = $site->fresh();
    $stale->base_url = 'https://planned.test';
    if ($deleted) {
        $site->delete();
    } else {
        $site->update(['base_url' => 'https://current.test']);
    }

    expect(fn () => Site::saveAllInHostOrder([$stale]))->toThrow(RuntimeException::class,
        $deleted ? 'no longer available' : 'loaded while it answered')
        ->and(DB::table('site_host_claims')->where('canonical_host', 'planned.test')->exists())->toBeFalse();
})->with(['moved' => false, 'deleted' => true]);
