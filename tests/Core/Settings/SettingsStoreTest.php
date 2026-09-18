<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Auth;
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

    it('records nothing for a value that only looks different before it is stored', function (): void {
        /*
         * ⚠️ THE WRITER DECIDED "CHANGED" FROM ITS OWN `===`, AND THE ROW IS JSON. A float 1.0 is stored as 1, an
         * object as a map, and a map's keys in whatever order the engine keeps — MySQL keeps its own. So a repeat
         * of each compared the caller's PHP value with the decoded row, saw a change, and recorded `settings.set`
         * for a write that changed nothing (on SQLite no UPDATE was even issued). Measured before the fix: +1 each.
         */
        $this->writer->set($this->site, 'ratio', 1.0);
        $this->writer->set($this->site, 'brand', (object) ['colour' => 'red']);
        $this->writer->set($this->site, 'nav', ['colour' => 'red', 'font' => 'serif']);
        $this->writer->set($this->site, 'weights', ['b' => 2, 'a' => 1]);
        $before = AuditLog::query()->count();

        $this->writer->set($this->site, 'ratio', 1.0);
        $this->writer->set($this->site, 'brand', (object) ['colour' => 'red']);
        // The identical map: MySQL hands its keys back in its own order, so only the matrix sees this line matter.
        $this->writer->set($this->site, 'nav', ['colour' => 'red', 'font' => 'serif']);
        $this->writer->set($this->site, 'nav', ['font' => 'serif', 'colour' => 'red']);
        // Both at once: the same map, as an object, in another order — and a float stored as an integer, reordered.
        $this->writer->set($this->site, 'nav', (object) ['font' => 'serif', 'colour' => 'red']);
        $this->writer->set($this->site, 'weights', ['a' => 1.0, 'b' => 2]);

        expect(AuditLog::query()->count())->toBe($before);
    });

    it('records a change of type, which is a change', function (): void {
        $this->writer->set($this->site, 'limit', 1);
        $before = AuditLog::query()->count();

        $this->writer->set($this->site, 'limit', '1');

        expect(AuditLog::query()->count())->toBe($before + 1)
            ->and(settingsStoredIn('sites', $this->site->id))->toBe(['limit' => '1']);
    });

    it('refuses a save a listener cancels, and neither records it nor describes it as written', function (): void {
        /*
         * ⚠️ `save()` RETURNS FALSE WHEN A LISTENER CANCELS, and the writer did not look: it recorded
         * `settings.set` for a row that was never written and set the caller's instance to the value — measured,
         * stored NULL, one audit row, and `$site->settings` reading Asia/Tokyo. ADR-020: the audited set and the
         * written set are the same set.
         */
        $before = AuditLog::query()->count();
        Site::updating(fn (): bool => false);

        expect(fn () => $this->writer->set($this->site, 'timezone', 'Asia/Tokyo'))
            ->toThrow(RuntimeException::class, 'cancelled');

        expect(settingsStoredIn('sites', $this->site->id))->toBeNull()
            ->and(AuditLog::query()->count())->toBe($before)
            ->and($this->site->settings)->toBeNull();
    });

    it('records nothing when a listener undoes the change before it is written', function (): void {
        // The save succeeds and writes nothing to `settings`, so there is no change to record.
        $before = AuditLog::query()->count();
        Site::saving(function (Site $site): void {
            $site->setAttribute('settings', $site->getOriginal('settings'));
        });

        $this->writer->set($this->site, 'timezone', 'Asia/Tokyo');

        expect(settingsStoredIn('sites', $this->site->id))->toBeNull()
            ->and(AuditLog::query()->count())->toBe($before)
            ->and($this->site->settings)->toBeNull();
    });

    it('keeps no write it could not record', function (): void {
        // ⚠️ The record is made inside the write's transaction, so an audit store that refuses takes the write with it.
        AuditLog::creating(fn () => throw new RuntimeException('audit store unavailable'));

        expect(fn () => $this->writer->set($this->site, 'timezone', 'Asia/Tokyo'))
            ->toThrow(RuntimeException::class, 'audit store unavailable');

        expect(settingsStoredIn('sites', $this->site->id))->toBeNull();
    });

    it('records who made the change, and nobody when nobody did', function (): void {
        $actor = new AuthUser;
        $actor->forceFill(['id' => 47]);
        Auth::login($actor);

        $this->writer->set($this->site, 'timezone', 'Asia/Tokyo');

        expect(DB::table('audit_log')->orderByDesc('id')->value('actor_id'))->toBe('47');

        Auth::logout();
        $this->writer->revert($this->site, 'timezone');

        expect(DB::table('audit_log')->orderByDesc('id')->value('actor_id'))->toBeNull();
    });

    it('keeps neither the write nor a record when the value is refused', function (): void {
        $before = AuditLog::query()->count();

        expect(fn () => $this->writer->set($this->site, 'timezone', 'Mars/Olympus'))
            ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists');

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

    it('refuses a rival\'s level whose org was rewritten in memory, because it asks the database', function (): void {
        /*
         * ⚠️ THE CHECK ABOVE READS AN ATTRIBUTE, WHICH ANY CALLER CAN SET. What stops a rival's site group or site
         * once `org_id` is forged is `lockedRow()` re-reading the row through the model's scoped query — and no
         * test reached it, so reading the row unscoped left the suite green while the rival's configuration was
         * written and the trail filed in this org's log. An org is not here: forging its key names this org's row.
         */
        $before = AuditLog::query()->count();

        foreach ([$this->rivalGroup, $this->rivalSite] as $scope) {
            $forged = (clone $scope)->forceFill(['org_id' => $this->org->id]);

            expect(fn () => $this->writer->set($forged, 'timezone', 'America/New_York'))
                ->toThrow(RuntimeException::class, 'is not visible from the current org')
                ->and(fn () => $this->writer->revert($forged, 'timezone'))
                ->toThrow(RuntimeException::class, 'is not visible from the current org');
        }

        expect(settingsStoredIn('site_groups', $this->rivalGroup->id))->toBeNull()
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

    it('drops it when the group is renamed, because the provenance quotes the name', function (): void {
        $this->group->update(['settings' => ['timezone' => 'Europe/Paris']]);
        expect($this->resolver->resolve($this->site, 'timezone')?->describe())->toBe('inherited from site group Golfdom');

        SiteGroup::query()->findOrFail($this->group->id)->update(['name' => 'Golfdom Rebrand']);

        expect($this->resolver->resolve($this->site, 'timezone')?->describe())->toBe('inherited from site group Golfdom Rebrand');
    });

    it('drops only the level a model save wrote, and the sites beneath it', function (): void {
        /*
         * An evented save names its row, so it drops that level and no further — a save to one org must not throw
         * away another org's work. Observed through staleness: the raw write to the rival fires nothing, so the
         * rival's site still reads the old value only if its memo was kept.
         */
        $rival = Org::create(['name' => 'Rival', 'slug' => 'rival-precise', 'settings' => ['timezone' => 'Asia/Tokyo']]);
        $stranger = Site::withoutScopeBecause('a rival org\'s site, for the test', fn () => Site::create([
            'org_id' => $rival->id, 'handle' => 'stranger', 'slug' => 'stranger', 'name' => 'Stranger',
        ]));

        expect($this->resolver->get($stranger, 'timezone'))->toBe('Asia/Tokyo');
        DB::table('orgs')->where('id', $rival->id)->update(['settings' => json_encode(['timezone' => 'Europe/Oslo'])]);

        $this->org->update(['settings' => ['timezone' => 'Europe/London']]);

        expect($this->resolver->get($this->site, 'timezone'))->toBe('Europe/London')
            ->and($this->resolver->get($stranger, 'timezone'))->toBe('Asia/Tokyo');
    });

    describe('by the paths that fire no model event', function (): void {
        /*
         * ⚠️ `saved` AND `deleted` FIRE FOR AN EVENTED SAVE OR DELETE OF ONE INSTANCE AND NOTHING ELSE, which is
         * where invalidation used to hang. Every write below changes what the site resolves and fires neither —
         * measured stale before invalidation moved to the builder they all go through.
         */
        beforeEach(function (): void {
            $this->org->update(['settings' => ['timezone' => 'Europe/London']]);
            $this->group->update(['settings' => ['timezone' => 'America/Chicago']]);
            $this->other = SiteGroup::create([
                'org_id' => $this->org->id, 'handle' => 'other', 'name' => 'Other',
                'settings' => ['timezone' => 'Australia/Sydney'],
            ]);

            expect(resolvedAt($this->site))->toBe(['America/Chicago', 'site_group']);
        });

        it('a bulk move to another group', function (): void {
            Site::query()->whereKey($this->site->id)->update(['site_group_id' => $this->other->id]);

            expect(resolvedAt($this->site))->toBe(['Australia/Sydney', 'site_group']);
        });

        it('a relation update that detaches the site', function (): void {
            $this->group->sites()->update(['site_group_id' => null]);

            expect(resolvedAt($this->site))->toBe(['Europe/London', 'org']);
        });

        it('a quiet move', function (): void {
            $fresh = Site::query()->findOrFail($this->site->id);
            $fresh->site_group_id = $this->other->id;
            $fresh->saveQuietly();

            expect(resolvedAt($this->site))->toBe(['Australia/Sydney', 'site_group']);
        });

        it('a bulk delete of the group, whose sites the database detaches', function (): void {
            SiteGroup::query()->whereKey($this->group->id)->delete();

            expect(resolvedAt($this->site))->toBe(['Europe/London', 'org']);
        });

        it('a quiet delete of the group', function (): void {
            SiteGroup::query()->findOrFail($this->group->id)->deleteQuietly();

            expect(resolvedAt($this->site))->toBe(['Europe/London', 'org']);
        });

        it('a bulk soft delete of the org', function (): void {
            $this->group->update(['settings' => null]);
            expect(resolvedAt($this->site))->toBe(['Europe/London', 'org']);

            Org::query()->whereKey($this->org->id)->delete();

            expect(resolvedAt($this->site))->toBe(['UTC', 'default']);
        });

        it('a bulk rename of the group', function (): void {
            SiteGroup::query()->whereKey($this->group->id)->update(['name' => 'Rebranded']);

            expect($this->resolver->resolve($this->site, 'timezone')?->describe())->toBe('inherited from site group Rebranded');
        });

        it('a bulk settings write inside the escape hatch, which stands the guards down and not this', function (): void {
            SiteGroup::withoutScopeBecause('the test writes past the per-row refusal', fn ($query) => $query
                ->whereKey($this->group->id)
                ->update(['settings' => json_encode(['timezone' => 'Asia/Tokyo'])]));

            expect(resolvedAt($this->site))->toBe(['Asia/Tokyo', 'site_group']);
        });

        it('an upsert of an existing row inside the escape hatch', function (): void {
            /*
             * ⚠️ THE ONE WRITE THAT CHANGED A ROW AND DROPPED NOTHING. `upsert()` updates an existing row on conflict,
             * and inside `withoutScopeBecause()` it went to the parent without `forgettingResolvedSettings()`, so the
             * memo primed above kept describing the old row. Codex found it on #127 after the settings check had been
             * added to `upsert()` but the invalidation had not. An audit of every write for BOTH properties — checks
             * the value, drops the memo, or refuses outright — found no other.
             */
            $row = (array) DB::table('site_groups')->where('id', $this->group->id)->first();
            $row['settings'] = json_encode(['timezone' => 'Asia/Tokyo']);

            SiteGroup::withoutScopeBecause('the test writes past the per-row refusal', fn ($query) => $query
                ->upsert([$row], ['id'], ['settings']));

            expect(resolvedAt($this->site))->toBe(['Asia/Tokyo', 'site_group']);
        });
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

        expect($this->resolver->get($this->site, 'timezone'))->toBe('UTC')
            // The caller's instance is not restored, as with any Eloquent save; what reads settings re-reads the rows.
            ->and($this->site->settings)->toBe(['timezone' => 'Asia/Tokyo'])
            ->and(settingsStoredIn('sites', $this->site->id))->toBeNull();
    });
});

describe('a timezone is an identifier PHP lists, by every path', function (): void {
    it('accepts a canonical identifier and refuses what only looks like one', function (): void {
        $this->site->update(['settings' => ['timezone' => 'America/New_York']]);

        // An offset has no daylight-saving rules; an abbreviation is ambiguous; the spelling is exact; and there is
        // no unset sentinel — a key is reverted by removing it.
        foreach (['+05:00', 'EST', 'america/new_york', 'US/Eastern', 'Mars/Olympus', '', null, 5, ['UTC']] as $bad) {
            expect(fn () => $this->site->update(['settings' => ['timezone' => $bad]]))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists');
        }

        expect(settingsStoredIn('sites', $this->site->id))->toBe(['timezone' => 'America/New_York']);
    });

    it('says what it checks, since a name it refuses may still be an IANA one', function (): void {
        /*
         * ⚠️ `Etc/UTC`, `GMT` and `US/Eastern` are all in the IANA database — as a zone, and as backward links — and
         * PHP's list leaves them out. The refusal said "not an IANA timezone identifier", which was untrue of each.
         */
        foreach (['Etc/UTC', 'GMT', 'US/Eastern'] as $alias) {
            try {
                $this->site->update(['settings' => ['timezone' => $alias]]);
                $message = null;
            } catch (RuntimeException $refused) {
                $message = $refused->getMessage();
            }

            expect($message)->toContain('is not a timezone identifier PHP lists', 'DateTimeZone::listIdentifiers()')
                ->and(str_contains((string) $message, 'not an IANA'))->toBeFalse("{$alias} was called not IANA");
        }
    });

    it('refuses it on create and update at every level', function (): void {
        expect(fn () => Org::create(['name' => 'Bad', 'slug' => 'bad', 'settings' => ['timezone' => 'Mars/Olympus']]))
            ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists')
            ->and(fn () => SiteGroup::create([
                'org_id' => $this->org->id, 'handle' => 'bad', 'name' => 'Bad', 'settings' => ['timezone' => 'Mars/Olympus'],
            ]))->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists')
            ->and(fn () => Site::create([
                'org_id' => $this->org->id, 'handle' => 'bad', 'slug' => 'bad', 'name' => 'Bad',
                'settings' => ['timezone' => 'Mars/Olympus'],
            ]))->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists');

        foreach ([$this->org, $this->group, $this->site] as $scope) {
            expect(fn () => $scope->update(['settings' => ['timezone' => 'Mars/Olympus']]))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists');
        }

        expect(DB::table('orgs')->where('slug', 'bad')->exists())->toBeFalse()
            ->and(DB::table('site_groups')->where('handle', 'bad')->exists())->toBeFalse()
            ->and(DB::table('sites')->where('handle', 'bad')->exists())->toBeFalse();
    });

    it('checks the value a save actually writes, after every saving listener has run', function (): void {
        /*
         * ⚠️ THE CHECK RAN FIRST, AND THE WRITE CAME LAST. `HoldsSettings` validates in a `saving` listener
         * registered when the model boots, and listeners run in the order they were registered — so a host's
         * `saving` listener, registered afterwards, runs after the check and before the write. One that set a
         * refused timezone was stored: the check had already passed on the value before it. Codex found it on
         * #127, and this file's own "listener undoes the change" case shows a later listener rewriting
         * `settings` is a path the store has to survive. The builder now checks what it is handed to write.
         */
        $rows = [
            Org::class => ['name' => 'Late', 'slug' => 'late'],
            SiteGroup::class => ['org_id' => $this->org->id, 'handle' => 'late', 'name' => 'Late'],
            Site::class => ['org_id' => $this->org->id, 'handle' => 'late', 'slug' => 'late', 'name' => 'Late'],
        ];

        foreach ([Org::class => $this->org, SiteGroup::class => $this->group, Site::class => $this->site] as $class => $scope) {
            $class::saving(static function ($model): void {
                $model->setAttribute('settings', ['timezone' => 'EST']);
            });

            expect(fn () => $scope->fresh()->update(['name' => 'Renamed']))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists', "{$class}: an update stored a later listener's value")
                ->and(fn () => $class::create($rows[$class]))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists', "{$class}: a create stored a later listener's value");

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))
                ->toBeNull("{$class}: the refused value reached the row");
        }

        expect(DB::table('orgs')->where('slug', 'late')->exists())->toBeFalse()
            ->and(DB::table('site_groups')->where('handle', 'late')->exists())->toBeFalse()
            ->and(DB::table('sites')->where('handle', 'late')->exists())->toBeFalse();
    });

    it('checks a whole map an arithmetic write carries inside the escape hatch', function (string $method): void {
        /*
         * ⚠️ THE CONTRACT BELOW SAID EVERY WHOLE MAP IS CHECKED IN THE ESCAPE HATCH, AND FOUR WRITES WERE NOT.
         * `increment()`, `decrement()`, `incrementEach()` and `decrementEach()` carry an `$extra` of plain
         * assignments; `guardArithmetic()` stands down inside `withoutScopeBecause()` and did not call the settings
         * check, so `['settings' => …EST…]` beside a no-op increment of `id` was stored. Codex found it on #127.
         */
        $bad = json_encode(['timezone' => 'EST']);

        foreach ([Org::class => $this->org, SiteGroup::class => $this->group, Site::class => $this->site] as $class => $scope) {
            $write = match ($method) {
                'increment', 'decrement' => fn ($query) => $query->whereKey($scope->id)->{$method}('id', 0, ['settings' => $bad]),
                'incrementEach', 'decrementEach' => fn ($query) => $query->whereKey($scope->id)->{$method}(['id' => 0], ['settings' => $bad]),
            };

            expect(fn () => $class::withoutScopeBecause('the test writes past the per-row refusal', $write))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists', "{$class}: {$method}() stored an unchecked map");

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))->toBeNull();
        }
    })->with(['increment', 'decrement', 'incrementEach', 'decrementEach']);

    it('checks a whole map an upsert carries inside the escape hatch', function (): void {
        // Refused outside the escape hatch; inside it, `upsert()` went straight to the parent with its rows unread —
        // the same hole as the arithmetic extras, in a method the finding did not name. Found by listing every write.
        foreach ([Org::class => $this->org, SiteGroup::class => $this->group, Site::class => $this->site] as $class => $scope) {
            $row = (array) DB::table($scope->getTable())->where('id', $scope->id)->first();
            $row['settings'] = json_encode(['timezone' => 'EST']);

            expect(fn () => $class::withoutScopeBecause('the test writes past the per-row refusal', fn ($query) => $query
                ->upsert([$row], ['id'], ['settings'])))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists', "{$class}: upsert() stored an unchecked map");

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))->toBeNull();
        }
    });

    it('checks a whole map written inside the escape hatch, which stands down the per-row refusal and not this', function (): void {
        // `withoutScopeBecause()` is about WHICH path may write a column; this is about WHAT a column may hold, so
        // standing the first down does not stand down the second. A JSON-path write there is still unchecked —
        // ADR-022 names it — because no whole map exists to judge until the database has merged the path in.
        foreach ([Org::class => $this->org, SiteGroup::class => $this->group, Site::class => $this->site] as $class => $scope) {
            expect(fn () => $class::withoutScopeBecause('the test writes past the per-row refusal', fn ($query) => $query
                ->whereKey($scope->id)
                ->update(['settings' => json_encode(['timezone' => 'EST'])])))
                ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists', "{$class}: the escape hatch stored an unchecked map");

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))->toBeNull();
        }
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
                /*
                 * ⚠️ A COLUMN NAME IS CASE-INSENSITIVE on SQLite, MySQL and MariaDB, so each of these writes
                 * `settings` — and the builder compared names exactly, so each was allowed. Measured before the
                 * fold: `SETTINGS` stored Mars/Olympus, and the memo kept describing the old value.
                 */
                'upper-cased column' => fn () => $class::query()->whereKey($scope->id)->update(['SETTINGS' => $bad]),
                'mis-cased JSON path' => fn () => $class::query()->whereKey($scope->id)->update(['Settings->timezone' => 'Mars/Olympus']),
                'mis-cased qualified column' => fn () => $class::query()->whereKey($scope->id)->update([$scope->getTable().'.Settings' => $bad]),
                'mis-cased increment extras' => fn () => $class::query()->whereKey($scope->id)->increment('id', 0, ['SETTINGS' => $bad]),
                // A JSON path may hold a dot; the table qualifier was found by the last one, so this read as `b`.
                // Allowed on MySQL and MariaDB before the fix; SQLite and PostgreSQL reject the SQL, so only the matrix
                // sees the refusal matter.
                'JSON path with a dot' => fn () => $class::query()->whereKey($scope->id)->update(['settings->a.b' => 'x']),
            ];

            foreach ($attempts as $path => $attempt) {
                expect($attempt)->toThrow(RuntimeException::class, 'cannot be written in bulk', "{$class}: {$path} was allowed");
            }

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))
                ->toBeNull("{$class}: a path stored settings past the guard");
        }
    });

    it('refuses a save that writes settings under another spelling, which the check does not read', function (): void {
        /*
         * ⚠️ AN ORDINARY SAVE, WITH EVERY EVENT, AND STILL PAST THE GUARD. `HoldsSettings` checks
         * `getAttribute('settings')`; `update(['Settings' => …])` sets a second attribute, the check passes on the
         * untouched first one, and the engine writes the second into the same column. Measured on SQLite, MySQL
         * and MariaDB before the fix: Mars/Olympus stored through `$site->update()`. Folding case in the bulk
         * comparison does not reach this, because a genuine save is the path that comparison stands aside for.
         */
        $bad = json_encode(['timezone' => 'Mars/Olympus']);
        $rows = [
            Org::class => ['name' => 'Spelled', 'slug' => 'spelled'],
            SiteGroup::class => ['org_id' => $this->org->id, 'handle' => 'spelled', 'name' => 'Spelled'],
            Site::class => ['org_id' => $this->org->id, 'handle' => 'spelled', 'slug' => 'spelled', 'name' => 'Spelled'],
        ];

        foreach ([Org::class => $this->org, SiteGroup::class => $this->group, Site::class => $this->site] as $class => $scope) {
            $attempts = [
                'update' => fn () => $scope->fresh()->update(['Settings' => $bad]),
                'JSON-path update' => fn () => $scope->fresh()->update(['SETTINGS->timezone' => 'Mars/Olympus']),
                'create' => fn () => $class::create($rows[$class] + ['Settings' => $bad]),
            ];

            foreach ($attempts as $path => $attempt) {
                expect($attempt)->toThrow(RuntimeException::class, 'the value would be stored unchecked', "{$class}: {$path} was allowed");
            }

            expect(DB::table($scope->getTable())->where('id', $scope->id)->value('settings'))
                ->toBeNull("{$class}: a save stored settings past the guard");
        }

        expect(DB::table('orgs')->where('slug', 'spelled')->exists())->toBeFalse()
            ->and(DB::table('site_groups')->where('handle', 'spelled')->exists())->toBeFalse()
            ->and(DB::table('sites')->where('handle', 'spelled')->exists())->toBeFalse();
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
                // The engine writes `SETTINGS` into `settings`; the guard looked the name up exactly.
                ->and(fn () => $class::query()->insertGetId(array_diff_key($withSettings, ['settings' => 0]) + ['SETTINGS' => $withSettings['settings']]))
                ->toThrow(RuntimeException::class, 'never set that value', "{$class}: insertGetId, upper-cased")
                ->and(fn () => $class::createQuietly(array_merge($row, ['settings' => ['timezone' => 'Mars/Olympus']])))
                ->toThrow(RuntimeException::class, 'never set that value', "{$class}: createQuietly");
        }

        expect(DB::table('orgs')->where('slug', 'bulk')->exists())->toBeFalse()
            ->and(DB::table('site_groups')->where('handle', 'bulk')->exists())->toBeFalse()
            ->and(DB::table('sites')->where('handle', 'bulk')->exists())->toBeFalse();
    });

    it('refuses every save of a row holding a refused value, until the value is reverted', function (): void {
        /*
         * The whole map is checked on every save, so a value written past the check — below Eloquent here, or stored
         * before the check existed — blocks a rename as surely as a settings change. The revert is the way out: it
         * removes the key, and the map it leaves passes.
         */
        DB::table('sites')->where('id', $this->site->id)->update(['settings' => json_encode(['timezone' => 'EST', 'logo' => 'a.svg'])]);
        $site = $this->site->fresh();

        expect(fn () => $site->update(['name' => 'Renamed']))
            ->toThrow(RuntimeException::class, 'is not a timezone identifier PHP lists');

        $this->writer->revert($site, 'timezone');
        $site->update(['name' => 'Renamed']);

        expect(settingsStoredIn('sites', $this->site->id))->toBe(['logo' => 'a.svg'])
            ->and(DB::table('sites')->where('id', $this->site->id)->value('name'))->toBe('Renamed');
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

    it('are not built by a write that nothing in this job resolved against', function (): void {
        /*
         * Invalidation drops what live resolvers hold and builds none, so an unrelated save neither pays for building
         * one nor fails where a bad default would.
         *
         * ⚠️ A WORKER'S RESET, NOT `offsetUnset()`, which is what this test used and why it could not see the defect.
         * `offsetUnset()` also clears the container's record that the abstract was ever resolved; the queue worker's
         * `forgetScopedInstances()` clears only the instance. The check asked `app()->resolved()`, which stayed true
         * for every later job — so each org, site group or site write built a fresh resolver to empty it. Measured:
         * with the defaults since made invalid, the next job's rename failed.
         */
        $this->resolver->get($this->site, 'timezone');

        // The previous job ends: the worker resets scope, and nothing but the container held the resolver.
        $this->resolver = null;
        app()->forgetScopedInstances();

        $built = 0;
        app()->afterResolving(SettingsResolver::class, function () use (&$built): void {
            $built++;
        });
        config(['kitsune.settings' => ['timezone' => 'Mars/Olympus']]);

        $this->site->update(['name' => 'Renamed in the next job']);
        $this->site->update(['settings' => ['timezone' => 'America/New_York']]);

        expect($built)->toBe(0)
            ->and(settingsStoredIn('sites', $this->site->id))->toBe(['timezone' => 'America/New_York']);
    });
});
