<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Once;
use Kitsune\Core\Auth\EntryListAuthorization;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

it('batches stored row reads for visible controls and leaves direct policy checks independent', function (int $pageSize): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $org = Org::create(['slug' => 'media-scope', 'name' => 'Media']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'media', 'slug' => 'media', 'name' => 'Media']);
    $context->setSite($site);
    $type = EntryType::create(['handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images']);
    $records = collect(range(1, $pageSize))->map(fn (int $number): Entry => Entry::create([
        'entry_type_id' => $type->getKey(), 'title' => "Media $number",
    ]));
    $otherSite = Site::create(['handle' => 'other', 'slug' => 'other', 'name' => 'Other']);
    $context->setSite($otherSite);
    $otherRecord = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'On the other Site']);
    $context->setSite($site);

    $user = TestUser::create(['email' => 'media-scope@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true])->assignTo($user->getKey());

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $sql = str_replace(['"', '`'], '', $query->sql);
        if (str_starts_with($sql, 'select') && str_contains($sql, 'from entries') && str_contains($sql, 'type_handle')) {
            $reads++;
        }
    });

    $hidden = $records->last();
    $gate = Gate::forUser($user);
    $gate->before(static fn ($actor, string $ability, array $arguments): ?bool => $ability === 'view'
        && $arguments === [$hidden] ? false : null);

    $presentation = app(EntryListAuthorization::class);
    $html = $presentation->render($records, function () use ($records, $presentation, $gate, $hidden): string {
        foreach ($records as $record) {
            expect($presentation->forControl($record, 'view', fn () => $gate->allows('view', $record)))
                ->toBe($record !== $hidden);
        }

        return 'table';
    });

    expect($html)->toBe('table')
        ->and($reads)->toBe(1)
        ->and($presentation->storedRecord($records->first(), 'view'))->toBeNull();

    // Display checks do not prime the ordinary policy's one-read-per-entry memo.
    foreach ($records as $record) {
        expect($gate->allows('update', $record))->toBeTrue();
    }
    expect($reads)->toBe(1 + $pageSize);

    expect(fn () => $presentation->render($records, fn () => $presentation->forControl(
        $records->first(), 'view', fn () => throw new RuntimeException('render interrupted'),
    )))->toThrow(RuntimeException::class, 'render interrupted');

    expect($presentation->storedRecord($records->first(), 'view'))->toBeNull();

    $trashed = $records->first();
    $trashed->delete();
    Once::flush();
    $presentation->render([$trashed, $otherRecord], function () use ($presentation, $gate, $otherRecord, $trashed, &$reads): string {
        $before = $reads;
        expect($presentation->storedRecord($trashed, 'restore'))->toBeNull();
        $presentation->forControl($trashed, 'restore', function () use ($gate, $trashed, $presentation, &$reads, $before): void {
            expect($presentation->storedRecord($trashed, 'restore'))->not->toBeNull()
                ->and($presentation->storedRecord($trashed, 'update'))->toBeNull()
                ->and($gate->allows('update', $trashed))->toBeTrue()
                ->and($reads)->toBe($before + 1);
        });
        Once::flush();
        $before = $reads;
        expect($presentation->forControl($trashed, 'restore', fn () => $gate->allows('restore', $trashed)))->toBeTrue()
            ->and($presentation->forControl($trashed, 'forceDelete', fn () => $gate->allows('forceDelete', $trashed)))->toBeTrue()
            ->and($reads)->toBe($before);
        expect($presentation->forControl($otherRecord, 'view', fn () => $gate->allows('view', $otherRecord)))->toBeFalse()
            ->and($reads)->toBe($before + 1);

        return 'trash';
    });
})->with([5, 50]);
