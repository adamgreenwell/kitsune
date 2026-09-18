<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Tenancy\Context;

/*
 * ADR-022's write side: an audited writer, automatic invalidation, and a timezone that cannot be stored wrong.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom Media', 'slug' => 'golfdom-media']);
    app(Context::class)->setOrg($this->org);

    $this->group = SiteGroup::create(['org_id' => $this->org->id, 'handle' => 'golfdom', 'name' => 'Golfdom']);
    $this->site = Site::create([
        'org_id' => $this->org->id, 'site_group_id' => $this->group->id,
        'handle' => 'golfdom-us', 'slug' => 'golfdom-us', 'name' => 'Golfdom US',
    ]);
    app(Context::class)->setSite($this->site);

    $this->writer = app(SettingsWriter::class);
    $this->resolver = app(SettingsResolver::class);
});

afterEach(fn () => app(Context::class)->forget());

/**
 * The timezone a site resolves to, and the level it came from.
 *
 * @return array{0: mixed, 1: ?string}
 */
function resolvedAt(Site $site): array
{
    $resolved = app(SettingsResolver::class)->resolve($site, 'timezone');

    return [$resolved?->value, $resolved?->origin];
}

/** The `settings` column exactly as stored, decoded, or null. */
function settingsStoredIn(string $table, int $id): ?array
{
    $raw = DB::table($table)->where('id', $id)->value('settings');

    return $raw === null ? null : json_decode((string) $raw, true);
}

describe('the writer', function (): void {
    it('sets a key at each level, and the site resolves the nearest with its provenance', function (): void {
        $this->writer->set($this->org, 'timezone', 'Europe/London');
        expect(resolvedAt($this->site))->toBe(['Europe/London', 'org']);

        $this->writer->set($this->group, 'timezone', 'America/Chicago');
        expect(resolvedAt($this->site))->toBe(['America/Chicago', 'site_group']);

        $this->writer->set($this->site, 'timezone', 'America/New_York');
        expect(resolvedAt($this->site))->toBe(['America/New_York', 'site']);

        expect(settingsStoredIn('orgs', $this->org->id))->toBe(['timezone' => 'Europe/London'])
            ->and(settingsStoredIn('site_groups', $this->group->id))->toBe(['timezone' => 'America/Chicago'])
            ->and(settingsStoredIn('sites', $this->site->id))->toBe(['timezone' => 'America/New_York']);
    });

    it('reverts by removing the key, so the level inherits again', function (): void {
        $this->writer->set($this->site, 'logo', 'site.svg');
        $this->writer->set($this->site, 'timezone', 'America/New_York');
        $this->writer->set($this->group, 'timezone', 'America/Chicago');

        $this->writer->revert($this->site, 'logo');

        // ADR-022: absent means inherit, and there is no unset sentinel. The key is GONE, not null.
        expect(settingsStoredIn('sites', $this->site->id))->toBe(['timezone' => 'America/New_York']);

        $this->writer->revert($this->site, 'timezone');

        expect(resolvedAt($this->site))->toBe(['America/Chicago', 'site_group'])
            // The last override removed stores "overrides nothing" rather than a JSON `[]`.
            ->and(DB::table('sites')->where('id', $this->site->id)->value('settings'))->toBeNull();
    });

    it('leaves the caller\'s instance describing the row as stored', function (): void {
        $this->writer->set($this->site, 'timezone', 'America/New_York');

        expect($this->site->settings)->toBe(['timezone' => 'America/New_York'])
            ->and($this->site->isDirty('settings'))->toBeFalse();
    });

    it('merges into the stored row, not into the caller\'s copy', function (): void {
        /*
         * ⚠️ ONE JSON COLUMN HOLDS EVERY KEY, so a merge into a stale instance writes back what that instance loaded.
         * `$stale` is loaded before another instance sets `logo`; setting `timezone` through `$stale` must keep it.
         */
        $stale = Site::query()->findOrFail($this->site->id);

        $this->writer->set(Site::query()->findOrFail($this->site->id), 'logo', 'site.svg');
        $this->writer->set($stale, 'timezone', 'America/New_York');

        expect(settingsStoredIn('sites', $this->site->id))
            ->toBe(['logo' => 'site.svg', 'timezone' => 'America/New_York']);
    });
});

describe('the audit trail', function (): void {
    it('writes nothing for a direct model write — the measured reason the writer records', function (): void {
        /*
         * ⚠️ THE FACT "EXACTLY ONCE" RESTS ON. `AuditedBuilder` is bound to `Entry`, so an org, site group or site
         * write records nothing — through the model or in bulk. If these writes are ever audited at the builder,
         * this fails, and so does the exactly-once test below: the writer's own record would be the second one.
         */
        $before = AuditLog::query()->count();

        $this->org->update(['settings' => ['timezone' => 'Europe/London']]);
        $this->group->update(['settings' => ['timezone' => 'America/Chicago']]);
        $this->site->update(['settings' => ['timezone' => 'America/New_York']]);
        Site::query()->whereKey($this->site->id)->update(['name' => 'Renamed']);

        expect(AuditLog::query()->count())->toBe($before);
    });

    it('records each change exactly once, as the action and the level', function (): void {
        foreach ([$this->org, $this->group, $this->site] as $scope) {
            $before = AuditLog::query()->count();

            $this->writer->set($scope, 'timezone', 'America/New_York');

            expect(AuditLog::query()->count())->toBe($before + 1, class_basename($scope).' set');

            $this->writer->revert($scope, 'timezone');

            expect(AuditLog::query()->count())->toBe($before + 2, class_basename($scope).' reverted');

            expect(AuditLog::for($scope)->orderBy('id')->pluck('action')->all())
                ->toBe([SettingsWriter::SET, SettingsWriter::REVERTED]);
        }
    });

    it('never records the value, nor the key', function (): void {
        // ADR-020: actor, action and target only. A setting may one day be a secret, and a key is caller text.
        $this->writer->set($this->site, 'analytics_secret', 'sk_live_do_not_log_me');

        $row = (array) DB::table('audit_log')->orderByDesc('id')->first();

        expect($row['action'])->toBe('settings.set')
            ->and($row['target_type'])->toBe((new Site)->getMorphClass())
            ->and((string) $row['target_id'])->toBe((string) $this->site->id);

        // ⚠️ Not `->not->toContain($needle, $message)`: `toContain()` is variadic, so a message becomes a second needle.
        foreach ($row as $column => $value) {
            expect(str_contains((string) $value, 'sk_live'))->toBeFalse("[{$column}] holds the value")
                ->and(str_contains((string) $value, 'analytics_secret'))->toBeFalse("[{$column}] holds the key");
        }
    });

    it('records nothing when nothing changed', function (): void {
        $this->writer->set($this->site, 'timezone', 'America/New_York');
        $before = AuditLog::query()->count();

        $this->writer->set($this->site, 'timezone', 'America/New_York');
        $this->writer->revert($this->site, 'logo');

        expect(AuditLog::query()->count())->toBe($before);
    });

    it('keeps neither the write nor a record when the value is refused', function (): void {
        $before = AuditLog::query()->count();

        expect(fn () => $this->writer->set($this->site, 'timezone', 'Mars/Olympus'))
            ->toThrow(RuntimeException::class, 'not an IANA timezone identifier');

        expect(settingsStoredIn('sites', $this->site->id))->toBeNull()
            ->and(AuditLog::query()->count())->toBe($before);
    });
});

describe('the org boundary', function (): void {
    /*
     * Written from the attacker's side. The audit row is filed under the CURRENT org, so a write to another org's
     * level would change its configuration while the trail landed in this org's log. An `Org` is unscoped, so no
     * query would notice on its own.
     */
    beforeEach(function (): void {
        $this->rival = Org::create(['name' => 'Rival', 'slug' => 'rival-store']);
        [$this->rivalGroup, $this->rivalSite] = SiteGroup::withoutScopeBecause('a rival org\'s levels, for the test', fn () => [
            $group = SiteGroup::create(['org_id' => $this->rival->id, 'handle' => 'rival', 'name' => 'Rival']),
            Site::create([
                'org_id' => $this->rival->id, 'site_group_id' => $group->id,
                'handle' => 'rival-site', 'slug' => 'rival-site', 'name' => 'Rival site',
            ]),
        ]);
    });

    it('refuses another org\'s org, site group and site, and writes nothing', function (): void {
        $before = AuditLog::query()->count();

        foreach ([$this->rival, $this->rivalGroup, $this->rivalSite] as $scope) {
            expect(fn () => $this->writer->set($scope, 'timezone', 'America/New_York'))
                ->toThrow(RuntimeException::class, 'belongs to another organisation');
        }

        expect(settingsStoredIn('orgs', $this->rival->id))->toBeNull()
            ->and(settingsStoredIn('site_groups', $this->rivalGroup->id))->toBeNull()
            ->and(settingsStoredIn('sites', $this->rivalSite->id))->toBeNull()
            ->and(AuditLog::query()->count())->toBe($before);
    });

    it('refuses with no org context, because the change could not be recorded', function (): void {
        app(Context::class)->forget();

        expect(fn () => $this->writer->set($this->org, 'timezone', 'America/New_York'))
            ->toThrow(RuntimeException::class, 'there is no organisation context');

        expect(settingsStoredIn('orgs', $this->org->id))->toBeNull();
    });
});

describe('invalidation is automatic', function (): void {
    it('drops a site\'s resolution when the writer changes a level above it', function (): void {
        expect($this->resolver->get($this->site, 'timezone'))->toBeString();

        $this->writer->set($this->org, 'timezone', 'Asia/Tokyo');

        expect($this->resolver->get($this->site, 'timezone'))->toBe('Asia/Tokyo');
    });

    it('drops it when a plain model update changes the settings', function (): void {
        expect($this->resolver->get($this->site, 'timezone'))->toBe('UTC');

        $this->group->update(['settings' => ['timezone' => 'Europe/Paris']]);

        expect($this->resolver->get($this->site, 'timezone'))->toBe('Europe/Paris');
    });

    it('drops it when the site moves to another group', function (): void {
        $other = SiteGroup::create([
            'org_id' => $this->org->id, 'handle' => 'other', 'name' => 'Other',
            'settings' => ['timezone' => 'Australia/Sydney'],
        ]);

        expect($this->resolver->get($this->site, 'timezone'))->toBe('UTC');

        $this->site->update(['site_group_id' => $other->id]);

        expect($this->resolver->get($this->site, 'timezone'))->toBe('Australia/Sydney');
    });

    it('drops it when the group it inherits from is deleted', function (): void {
        $this->group->update(['settings' => ['timezone' => 'Europe/Paris']]);
        expect($this->resolver->get($this->site, 'timezone'))->toBe('Europe/Paris');

        // `sites.site_group_id` is nullOnDelete: the database detaches the site, and no site event fires.
        $this->group->delete();

        expect($this->resolver->get($this->site, 'timezone'))->toBe('UTC');
    });

    it('drops it when a transaction holding the write rolls back', function (): void {
        /*
         * The write drops the memo, a lookup inside the transaction memoises the uncommitted value, and the
         * rollback has to drop it again — or the rest of the request resolves a setting that no longer exists.
         */
        try {
            DB::transaction(function (): void {
                $this->writer->set($this->site, 'timezone', 'Asia/Tokyo');

                expect($this->resolver->get($this->site, 'timezone'))->toBe('Asia/Tokyo');

                throw new RuntimeException('roll it back');
            });
        } catch (RuntimeException) {
        }

        expect($this->resolver->get($this->site, 'timezone'))->toBe('UTC');
    });
});

describe('a timezone is an IANA identifier, by every path', function (): void {
    it('accepts a canonical identifier and refuses what only looks like one', function (): void {
        $this->site->update(['settings' => ['timezone' => 'America/New_York']]);

        // An offset has no daylight-saving rules; an abbreviation is ambiguous; the spelling is exact; and there is
        // no unset sentinel — a key is reverted by removing it.
        foreach (['+05:00', 'EST', 'america/new_york', 'US/Eastern', 'Mars/Olympus', '', null, 5, ['UTC']] as $bad) {
            expect(fn () => $this->site->update(['settings' => ['timezone' => $bad]]))
                ->toThrow(RuntimeException::class, 'not an IANA timezone identifier');
        }

        expect(settingsStoredIn('sites', $this->site->id))->toBe(['timezone' => 'America/New_York']);
    });

    it('refuses it on create and update at every level', function (): void {
        expect(fn () => Org::create(['name' => 'Bad', 'slug' => 'bad', 'settings' => ['timezone' => 'Mars/Olympus']]))
            ->toThrow(RuntimeException::class, 'not an IANA timezone identifier')
            ->and(fn () => SiteGroup::create([
                'org_id' => $this->org->id, 'handle' => 'bad', 'name' => 'Bad', 'settings' => ['timezone' => 'Mars/Olympus'],
            ]))->toThrow(RuntimeException::class, 'not an IANA timezone identifier')
            ->and(fn () => Site::create([
                'org_id' => $this->org->id, 'handle' => 'bad', 'slug' => 'bad', 'name' => 'Bad',
                'settings' => ['timezone' => 'Mars/Olympus'],
            ]))->toThrow(RuntimeException::class, 'not an IANA timezone identifier');

        foreach ([$this->org, $this->group, $this->site] as $scope) {
            expect(fn () => $scope->update(['settings' => ['timezone' => 'Mars/Olympus']]))
                ->toThrow(RuntimeException::class, 'not an IANA timezone identifier');
        }

        expect(DB::table('orgs')->where('slug', 'bad')->exists())->toBeFalse()
            ->and(DB::table('site_groups')->where('handle', 'bad')->exists())->toBeFalse()
            ->and(DB::table('sites')->where('handle', 'bad')->exists())->toBeFalse();
    });

    it('refuses the paths that skip the model event, at every level', function (): void {
        /*
         * ⚠️ THE `saving` HOOK IS ONE DOOR, and a model event is not "the one place every path goes through" — this
         * project has found that on seven guards. Each of these writes `settings` without dispatching `saving`, so
         * each is refused by the builder (`columnsRequiringModelSave()`), or it would store a value nothing checked
         * AND leave every resolved site describing the old one.
         */
        $bad = json_encode(['timezone' => 'Mars/Olympus']);

        foreach ([Org::class => $this->org, SiteGroup::class => $this->group, Site::class => $this->site] as $class => $scope) {
            $attempts = [
                'bulk update' => fn () => $class::query()->whereKey($scope->id)->update(['settings' => $bad]),
                'JSON-path update' => fn () => $class::query()->whereKey($scope->id)->update(['settings->timezone' => 'Mars/Olympus']),
                'increment extras' => fn () => $class::query()->whereKey($scope->id)->increment('id', 0, ['settings' => $bad]),
                'quiet update' => fn () => $scope->fresh()->fill(['settings' => ['timezone' => 'Mars/Olympus']])->saveQuietly(),
                'without events' => fn () => $class::withoutEvents(fn () => $scope->fresh()->update(['settings' => ['timezone' => 'Mars/Olympus']])),
            ];

            foreach ($attempts as $path => $attempt) {
                expect($attempt)->toThrow(RuntimeException::class, 'cannot be written in bulk', "{$class}: {$path} was allowed");
            }

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))
                ->toBeNull("{$class}: a path stored settings past the guard");
        }
    });

    it('refuses to create a level in bulk, or quietly with settings', function (): void {
        $rows = [
            Org::class => ['name' => 'Bulk', 'slug' => 'bulk'],
            SiteGroup::class => ['org_id' => $this->org->id, 'handle' => 'bulk', 'name' => 'Bulk'],
            Site::class => ['org_id' => $this->org->id, 'handle' => 'bulk', 'slug' => 'bulk', 'name' => 'Bulk'],
        ];

        foreach ($rows as $class => $row) {
            $withSettings = $row + ['settings' => json_encode(['timezone' => 'Mars/Olympus']), 'created_at' => now(), 'updated_at' => now()];

            expect(fn () => $class::query()->insert($withSettings))
                ->toThrow(RuntimeException::class, 'cannot be created in bulk', "{$class}: bulk insert")
                ->and(fn () => $class::query()->insertGetId($withSettings))
                ->toThrow(RuntimeException::class, 'never set that value', "{$class}: insertGetId")
                ->and(fn () => $class::createQuietly(array_merge($row, ['settings' => ['timezone' => 'Mars/Olympus']])))
                ->toThrow(RuntimeException::class, 'never set that value', "{$class}: createQuietly");
        }

        expect(DB::table('orgs')->where('slug', 'bulk')->exists())->toBeFalse()
            ->and(DB::table('site_groups')->where('handle', 'bulk')->exists())->toBeFalse()
            ->and(DB::table('sites')->where('handle', 'bulk')->exists())->toBeFalse();
    });

    it('refuses a settings value that is not a map of names', function (): void {
        foreach (['a string', ['a', 'list'], [5 => 'numbered']] as $bad) {
            expect(fn () => $this->site->update(['settings' => $bad]))->toThrow(RuntimeException::class, 'Refusing the settings');
        }
    });
});

describe('the defaults', function (): void {
    it('come from config/kitsune.php, and a host overrides them', function (): void {
        expect(config('kitsune.settings.timezone'))->toBe('UTC');

        // A host's own config/kitsune.php is loaded before providers register; the package's is merged beneath it.
        config(['kitsune' => ['settings' => ['timezone' => 'Europe/Berlin']]]);
        (new KitsuneServiceProvider(app()))->register();

        expect(config('kitsune.settings.timezone'))->toBe('Europe/Berlin');
    });

    it('are held to the rules a stored value is, when the resolver is built', function (): void {
        app()->forgetScopedInstances();
        config(['kitsune.settings' => ['timezone' => 'Mars/Olympus']]);

        expect(fn () => app(SettingsResolver::class))
            ->toThrow(RuntimeException::class, 'the configured defaults');
    });

    it('are not built by a write that nothing resolved against', function (): void {
        // The invalidation hook asks whether this request has a resolver before touching one, so an unrelated save
        // neither pays for building it nor fails where a bad default would.
        app()->offsetUnset(SettingsResolver::class);
        app()->scoped(SettingsResolver::class, fn () => throw new RuntimeException('a write built a resolver'));

        $this->site->update(['settings' => ['timezone' => 'America/New_York']]);

        expect(settingsStoredIn('sites', $this->site->id))->toBe(['timezone' => 'America/New_York']);
    });
});
