<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Audit\AppendOnlyBuilder;
use Kitsune\Core\Audit\AuditedBuilder;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * ADR-020 primitive 4. What is ABSENT from this table is the design.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'A', 'slug' => 'audit-a']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'audit-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'A', 'plural_name' => 'As']);
});

afterEach(fn () => app(Context::class)->forget());

it('records actor, action and target — AND NOTHING ELSE', function (): void {
    /*
     * ⚠️ The load-bearing test in this file, and it asserts the column list
     * EXACTLY rather than checking a few are present.
     *
     * "User 47 updated entry 1203" survives an erasure. "User 47 changed name
     * from X to Y" does not — it re-creates the erased value inside the log
     * meant to prove the erasure happened, putting SOC 2 and GDPR in direct
     * conflict for no gain (ADR-020).
     *
     * Adding a `changes`, `before`, `after` or `payload` column fails here,
     * which is the point: a convention everyone agrees with is one someone
     * eventually breaks under deadline.
     */
    $columns = Schema::getColumnListing('audit_log');
    sort($columns);

    expect($columns)->toBe([
        'action', 'actor_id', 'created_at', 'id', 'org_id', 'site_id', 'target_id', 'target_type',
    ]);
});

it('offers no way to pass a payload, because the signature has nowhere for one', function (): void {
    // A stronger guarantee than a rule: the caller cannot supply a diff
    // because there is no parameter for it.
    $record = new ReflectionMethod(Auditor::class, 'record');

    expect(array_map(fn (ReflectionParameter $p): string => $p->getName(), $record->getParameters()))
        ->toBe(['action', 'target']);
});

describe('what gets recorded', function (): void {
    it('records an entry being created', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

        $row = AuditLog::for($entry)->first();

        expect($row->action)->toBe('entry.created')
            ->and($row->target_type)->toBe($entry->getMorphClass())
            ->and($row->target_id)->toBe($entry->getKey())
            ->and($row->org_id)->toBe($this->org->id)
            ->and($row->site_id)->toBe($this->site->id);
    });

    it('records an update and a delete as separate rows', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $entry->update(['title' => 'Changed']);
        $entry->delete();

        expect(AuditLog::for($entry)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.updated', 'entry.deleted']);
    });

    it('records the console through the same path as the admin', function (): void {
        // Auditing from model events rather than from the admin, because an
        // audit trail that only covers the UI is one with a documented hole.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'From a command']);

        expect(AuditLog::for($entry)->exists())->toBeTrue();
    });

    it('leaves the actor NULL when the system acts on its own', function (): void {
        // Attributing a scheduled prune to whoever happened to be logged in
        // would be a lie in the one place that must not hold one.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

        expect(AuditLog::for($entry)->first()->actor_id)->toBeNull();
    });
});

describe('the log obeys the org boundary it records', function (): void {
    it('does not leak another org\'s rows', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Mine']);
        expect(AuditLog::count())->toBeGreaterThan(0);

        $rival = Org::create(['name' => 'B', 'slug' => 'audit-b']);
        app(Context::class)->setOrg($rival);

        // An audit log that crosses orgs tells one customer when another
        // edited something — a leak with a compliance flavour.
        expect(AuditLog::count())->toBe(0)
            ->and(AuditLog::for($entry)->exists())->toBeFalse();
    });

    it('returns nothing with no org context, failing closed', function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Mine']);

        app(Context::class)->forget();

        expect(AuditLog::count())->toBe(0);
    });
});

describe('an entry write that cannot be audited is refused', function (): void {
    /*
     * ⚠️ `record()` is deliberately silent with no org context, and that
     * leniency is right for an action a caller CHOSE to record. It is wrong
     * for a write that has already happened: console code supplying `org_id`
     * and `site_id` by hand inserts an entry perfectly well without
     * populating Context, and the audit then returned null while the
     * transaction committed — an entry with no trail, which is precisely what
     * ADR-020's amendment says is refused.
     */
    /*
     * ⚠️ Quiet creation suppresses EnforcesScope's stamping listener, so the
     * caller supplies the scope columns — and could supply ANOTHER org's. The
     * audit row is written under the CURRENT context, so that org would gain
     * an entry with no trail while this one gained a trail for a row it does
     * not own. The second half is the worse one: it reads as evidence.
     */
    it('refuses a quiet create carrying another org\'s scope keys', function (): void {
        $rival = Org::create(['name' => 'Q', 'slug' => 'quiet-rival']);

        expect(fn () => Entry::createQuietly([
            'org_id' => $rival->id, 'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id, 'type_handle' => 'page', 'title' => 'Smuggled',
        ]))->toThrow(RuntimeException::class, 'outside the current scope');

        expect(Entry::withoutGlobalScopes()->where('title', 'Smuggled')->exists())->toBeFalse();
    });

    it('still allows a quiet create in the CURRENT scope', function (): void {
        $entry = Entry::createQuietly([
            'org_id' => $this->org->id, 'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id, 'type_handle' => 'page', 'title' => 'Fine',
        ]);

        expect(AuditLog::for($entry)->pluck('action')->all())->toBe(['entry.created']);
    });

    it('refuses a create with no org context, and leaves no entry behind', function (): void {
        $org = $this->org;
        $site = $this->site;
        $type = $this->type;

        app(Context::class)->forget();

        expect(fn () => Entry::create([
            'org_id' => $org->id, 'site_id' => $site->id, 'entry_type_id' => $type->id,
            'type_handle' => 'page', 'title' => 'Untraceable',
        ]))->toThrow(RuntimeException::class, 'no organisation context');

        app(Context::class)->setOrg($org);
        app(Context::class)->setSite($site);

        expect(Entry::query()->where('title', 'Untraceable')->exists())->toBeFalse();
    });
});

it('records nothing rather than throwing when there is no org', function (): void {
    // Console commands, migrations and the installer all run without one.
    // Throwing would make audit an obstacle to routine work, and an audit
    // system people switch off records nothing at all.
    app(Context::class)->forget();

    expect(app(Auditor::class)->record('something.happened'))->toBeNull();
});

/*
 * Three from review, each a way the log failed to be what it claims.
 */
it('records an action that has no target at all', function (): void {
    // The signature advertises an optional target, and `target_type` was
    // NOT NULL — so the advertised call failed on insert. Plenty of
    // auditable actions have no model behind them: a settings change, a
    // sign-in, an export.
    $row = app(Auditor::class)->record('settings.updated');

    expect($row)->not->toBeNull()
        ->and($row->action)->toBe('settings.updated')
        ->and($row->target_type)->toBeNull()
        ->and($row->target_id)->toBeNull();
});

describe('creation cannot be made quiet', function (): void {
    /*
     * ⚠️ A `created` model listener is silently skipped by createQuietly()
     * and by anything inside withoutEvents(), both of which still insert the
     * row — through insertGetId(), which is where creation is audited now.
     * That is the one insert path that CAN be audited: it returns the id it
     * wrote, so there is a target to name.
     */
    /*
     * ⚠️ org_id and site_id are stamped by an EnforcesScope `creating`
     * listener, which quiet creation suppresses along with everything else —
     * so these have to be supplied by hand here. Entry happens to be saved
     * from the worst of it by a NOT NULL org_id, which turns a quiet create
     * into a constraint violation rather than an unscoped row. That is a
     * database constraint doing the work, not the design, and it says nothing
     * about a nullable column: auditing at insertGetId() holds either way.
     */
    it('audits an entry created quietly', function (): void {
        $entry = Entry::createQuietly([
            'org_id' => $this->org->id, 'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id, 'type_handle' => 'page', 'title' => 'Quiet',
        ]);

        expect(AuditLog::for($entry)->pluck('action')->all())->toBe(['entry.created']);
    });

    it('audits an entry created inside withoutEvents', function (): void {
        $entry = Entry::withoutEvents(fn () => Entry::create([
            'org_id' => $this->org->id, 'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id, 'type_handle' => 'page', 'title' => 'Silent',
        ]));

        expect(AuditLog::for($entry)->pluck('action')->all())->toBe(['entry.created']);
    });

    it('still records creation exactly once on the ordinary path', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Loud']);

        expect(AuditLog::for($entry)->where('action', 'entry.created')->count())->toBe(1);
    });
});

describe('a trail an outsider can append to is worse than no trail', function (): void {
    /*
     * ⚠️ Append-only ALLOWS insert, and that is the path nobody guarded.
     * `AuditLog::create(['org_id' => $rival, ...])` wrote an immutable,
     * apparently authoritative record into another org's trail — and a bulk
     * `insert()` skipped the model's creating hook entirely, so it did not
     * even get the stamping that relied on.
     */
    it('refuses a create into another org\'s trail', function (): void {
        $rival = Org::create(['name' => 'A', 'slug' => 'append-rival']);

        expect(fn () => AuditLog::create([
            'org_id' => $rival->id, 'action' => 'forged.evidence', 'created_at' => now(),
        ]))->toThrow(RuntimeException::class);

        expect(AuditLog::withoutGlobalScopes()->where('action', 'forged.evidence')->exists())->toBeFalse();
    });

    it('refuses a bulk insert into another org\'s trail, which skips the hook', function (): void {
        $rival = Org::create(['name' => 'B', 'slug' => 'append-rival-bulk']);

        expect(fn () => AuditLog::query()->insert([[
            'org_id' => $rival->id, 'action' => 'forged.bulk', 'created_at' => now(),
        ]]))->toThrow(RuntimeException::class, 'worse than no trail');

        expect(AuditLog::withoutGlobalScopes()->where('action', 'forged.bulk')->exists())->toBeFalse();
    });

    it('still appends to its own trail', function (): void {
        expect(fn () => AuditLog::create([
            'org_id' => $this->org->id, 'action' => 'legitimate.action', 'created_at' => now(),
        ]))->not->toThrow(RuntimeException::class);
    });
});

describe('a cascade that removes entries has to be refused, not audited', function (): void {
    /*
     * ⚠️ `entries.site_id` and `entries.entry_type_id` are both
     * `cascadeOnDelete`, so deleting either removed every entry INSIDE the
     * database: no per-row event, so no audit row, and a hard DELETE, so
     * SoftDeletes never applied and the rows were unrecoverable. Verified by
     * probe — three entries gone, zero audit rows, the org still present, so
     * not the documented org-cascade exception.
     *
     * The guarantee is "no Eloquent path creates, changes or removes an entry
     * without an audit row OR A REFUSAL". This is the refusal.
     */
    beforeEach(function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Held']);
    });

    it('refuses to delete a site whose entries would cascade', function (): void {
        expect(fn () => $this->site->delete())
            ->toThrow(RuntimeException::class, 'would delete them by cascade');

        expect(Entry::withoutGlobalScopes()->withTrashed()->count())->toBe(1);
    });

    it('refuses to delete an entry type whose entries would cascade', function (): void {
        expect(fn () => $this->type->delete())
            ->toThrow(RuntimeException::class, 'would delete them by cascade');
    });

    it('counts SOFT-deleted entries too, which the cascade would still take', function (): void {
        Entry::query()->delete();

        expect(fn () => $this->site->delete())
            ->toThrow(RuntimeException::class, 'would delete them by cascade');
    });

    it('allows the delete once the entries are gone through the audited path', function (): void {
        Entry::query()->forceDelete();

        expect(fn () => $this->type->delete())->not->toThrow(RuntimeException::class);
    });
});

describe('the log is append-only, enforced', function (): void {
    it('refuses to rewrite a row', function (): void {
        // An audit log application code can rewrite is not evidence of
        // anything, and `$guarded = []` makes accidental rewriting a
        // one-liner.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $row = AuditLog::for($entry)->first();

        expect(fn () => $row->update(['action' => 'entry.something.else']))
            ->toThrow(RuntimeException::class, 'append-only');
    });

    it('refuses to delete a row', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

        expect(fn () => AuditLog::for($entry)->first()->delete())
            ->toThrow(RuntimeException::class, 'append-only');
    });

    it('refuses updateFrom and touch, which PostgreSQL and Eloquent add', function (): void {
        $before = AuditLog::query()->count();

        expect(fn () => AuditLog::query()->updateFrom(['action' => 'forged']))->toThrow(RuntimeException::class)
            ->and(fn () => AuditLog::query()->touch())->toThrow(RuntimeException::class)
            ->and(AuditLog::query()->count())->toBe($before);
    });

    it('refuses the plural increments too', function (): void {
        $before = AuditLog::query()->count();

        expect(fn () => AuditLog::query()->incrementEach(['actor_id' => 1]))->toThrow(RuntimeException::class)
            ->and(fn () => AuditLog::query()->decrementEach(['actor_id' => 1]))->toThrow(RuntimeException::class)
            ->and(AuditLog::query()->count())->toBe($before);
    });

    it('refuses every other mutator the builder exposes', function (): void {
        // ⚠️ These were overridden one at a time as each was found, which is
        // how truncate() survived three rounds — Eloquent forwards it to the
        // query builder, so it erased the whole table with no override and no
        // model event. "Append-only" is a claim about every path.
        $before = AuditLog::query()->count();

        expect(fn () => AuditLog::query()->truncate())->toThrow(RuntimeException::class)
            ->and(fn () => AuditLog::query()->upsert([['action' => 'x']], ['id']))->toThrow(RuntimeException::class)
            ->and(fn () => AuditLog::query()->updateOrInsert(['action' => 'x'], ['action' => 'y']))->toThrow(RuntimeException::class)
            ->and(fn () => AuditLog::query()->increment('actor_id'))->toThrow(RuntimeException::class)
            ->and(fn () => AuditLog::query()->decrement('actor_id'))->toThrow(RuntimeException::class);

        expect(AuditLog::query()->count())->toBe($before);
    });

    it('still lets the org cascade take them, which is the intended exception', function (): void {
        // A database-level ON DELETE CASCADE does not go through Eloquent.
        // Erasing an organisation should not leave its audit trail behind.
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $orgId = $this->org->id;

        app(Context::class)->forget();
        DB::table('orgs')->where('id', $orgId)->delete();

        expect(DB::table('audit_log')->where('org_id', $orgId)->count())->toBe(0);
    });
});

describe('a restore is its own action', function (): void {
    it('records entry.restored rather than another entry.updated', function (): void {
        // restore() nulls deleted_at and saves, so a delete/restore pair was
        // recorded as deleted-then-updated — the actual lifecycle action
        // hidden behind an ordinary-looking edit.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $entry->delete();
        $entry->restore();

        expect(AuditLog::for($entry)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.deleted', 'entry.restored']);
    });

    it('still records an ordinary edit as an update', function (): void {
        // The restore carve-out must not swallow real edits.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $entry->update(['title' => 'Changed']);

        expect(AuditLog::for($entry)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.updated']);
    });
});

describe('append-only holds for BULK paths too', function (): void {
    it('refuses a bulk update, which fires no model events at all', function (): void {
        // ⚠️ `updating`/`deleting` guards cover instance mutations only.
        // `query()->update()` compiles straight to SQL — so the contract held
        // for the path a test exercises and not for the one-liner that
        // rewrites the whole table.
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

        expect(fn () => AuditLog::query()->update(['action' => 'nothing.happened']))
            ->toThrow(RuntimeException::class, 'append-only');
    });

    it('refuses a bulk delete', function (): void {
        Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

        expect(fn () => AuditLog::query()->delete())
            ->toThrow(RuntimeException::class, 'append-only');
    });

    it('leaves the rows untouched after a refused bulk write', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

        try {
            AuditLog::query()->update(['action' => 'nothing.happened']);
        } catch (RuntimeException) {
            // expected
        }

        expect(AuditLog::for($entry)->first()->action)->toBe('entry.created');
    });
});

describe('a permanent deletion is its own action', function (): void {
    it('records force_deleted rather than a second entry.deleted', function (): void {
        // `deleted` fires during a force-delete too, so recording it
        // unconditionally gave a permanently removed entry two deletion rows
        // and never said it had become irrecoverable — the one deletion an
        // operator most needs to find.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $id = $entry->getKey();

        $entry->delete();
        $entry->forceDelete();

        expect(AuditLog::query()->where('target_id', $id)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.deleted', 'entry.force_deleted']);
    });

    it('records a force-delete that skipped the soft delete entirely', function (): void {
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);
        $id = $entry->getKey();

        $entry->forceDelete();

        expect(AuditLog::query()->where('target_id', $id)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.force_deleted']);
    });
});

it('refuses forceDelete on the audit log, which does not go through delete()', function (): void {
    // ⚠️ Eloquent's forceDelete() calls the UNDERLYING query builder, so
    // neither the delete() override nor the model's `deleting` listener saw
    // it — a one-liner erasing audit evidence past two guards that both look
    // like they cover deletion.
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Hello']);

    expect(fn () => AuditLog::query()->forceDelete())
        ->toThrow(RuntimeException::class, 'append-only');

    expect(AuditLog::for($entry)->exists())->toBeTrue();
});

describe('bulk entry writes are audited too', function (): void {
    /*
     * ⚠️ `Entry::query()->update()` and its siblings write straight through
     * the query builder and dispatch NO per-model events — so entries could
     * change or disappear leaving no trace, while the claim was that the API
     * and the console go through the same code path as the admin.
     */
    beforeEach(function (): void {
        $this->one = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'One']);
        $this->two = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Two']);
    });

    it('records a row per entry for a bulk update', function (): void {
        Entry::query()->update(['status' => 'published']);

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1)
            ->and(AuditLog::for($this->two)->where('action', 'entry.updated')->count())->toBe(1);
    });

    it('records a bulk soft delete as a DELETE, not an update', function (): void {
        // A soft delete arrives at the builder as an update; recording both
        // would file a deletion as an edit as well.
        Entry::query()->delete();

        expect(AuditLog::for($this->one)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.deleted']);
    });

    it('records a bulk force delete', function (): void {
        $id = $this->one->getKey();

        Entry::query()->forceDelete();

        expect(AuditLog::query()->where('target_id', $id)->pluck('action')->all())
            ->toContain('entry.force_deleted');
    });

    it('records a bulk restore as a RESTORE', function (): void {
        Entry::query()->delete();

        Entry::onlyTrashed()->restore();

        expect(AuditLog::for($this->one)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.deleted', 'entry.restored']);
    });

    /*
     * ⚠️ The obvious design — model events for single rows, this builder for
     * bulk — records every ordinary write TWICE, because `$entry->save()` and
     * `$entry->delete()` are themselves builder writes. Nothing above would
     * have caught it: they all assert a row EXISTS, and two rows satisfy that
     * as readily as one.
     */
    it('records each single-entry write exactly once, not once per layer', function (): void {
        $this->one->update(['title' => 'Renamed']);
        $this->one->delete();
        $this->one->restore();

        expect(AuditLog::for($this->one)->orderBy('id')->pluck('action')->all())
            ->toBe(['entry.created', 'entry.updated', 'entry.deleted', 'entry.restored']);
    });

    /*
     * ⚠️ Creation has a bulk path too, and it is the same hole in reverse:
     * `Entry::query()->insert()` writes rows that dispatch no `created`
     * event, so an entry could APPEAR with no audit row. These methods return
     * a row count rather than the keys they wrote, so there is nothing to
     * name as the target — they are refused instead of quietly unaudited.
     */
    it('refuses every bulk path that would create an entry untraced', function (): void {
        $row = [
            'org_id' => $this->org->id, 'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id, 'type_handle' => 'page',
            'title' => 'Smuggled', 'status' => 'draft',
        ];

        expect(fn () => Entry::query()->insert([$row]))->toThrow(RuntimeException::class, 'audit trail')
            ->and(fn () => Entry::query()->insertOrIgnore([$row]))->toThrow(RuntimeException::class)
            ->and(fn () => Entry::query()->upsert([$row], ['id']))->toThrow(RuntimeException::class);

        // The guard has to fire BEFORE the write, or it documents a hole
        // rather than closing one.
        expect(Entry::query()->where('title', 'Smuggled')->exists())->toBeFalse();
    });

    it('refuses the forwarded writers that create or modify without an override', function (): void {
        // ⚠️ updateOrInsert() is forwarded WHOLE to the query builder, so its
        // internal insert or update reaches neither these overrides nor the
        // created event — it could create or modify an entry untraced
        // depending only on whether the predicate matched.
        expect(fn () => Entry::query()->updateOrInsert(['title' => 'X'], ['status' => 'draft']))
            ->toThrow(RuntimeException::class)
            ->and(Entry::query()->where('title', 'X')->exists())->toBeFalse();
    });

    it('audits an increment, which is an update that skips update()', function (): void {
        // Audited rather than refused: unlike the insert paths, the rows
        // already exist and have keys to name.
        Entry::query()->whereKey($this->one->getKey())->increment('id', 0);

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1)
            ->and(AuditLog::for($this->two)->where('action', 'entry.updated')->count())->toBe(0);
    });

    it('audits the PLURAL increments, which are separate methods', function (): void {
        // ⚠️ `incrementEach()` is its own query-builder method, so overriding
        // the singular ones left a multi-column increment forwarding straight
        // past every guard — the same omission, one API call along.
        Entry::query()->whereKey($this->one->getKey())->incrementEach(['id' => 0]);

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1);
    });

    it('audits a bulk touch, which Eloquent implements as toBase()->update()', function (): void {
        // ⚠️ `touch()` goes straight past the update() override, so every
        // matching entry had its `updated_at` moved with no audit row.
        Entry::query()->whereKey($this->one->getKey())->touch();

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1)
            ->and(AuditLog::for($this->two)->where('action', 'entry.updated')->count())->toBe(0);
    });

    /*
     * ⚠️ REFLECTED, and filtered to LOCALLY DECLARED methods.
     *
     * Two earlier versions of this test did not work. The first invoked four
     * methods by name, so a new mutator left it green. The second reflected
     * over the builder but counted INHERITED methods as handled — and every
     * mutator Eloquent\Builder declares is inherited, so `update`, `delete`,
     * `upsert`, `touch` and the increments were all permanently "covered"
     * whether overridden or not.
     *
     * That is also why my check on the second version passed: I renamed
     * `insertOrIgnoreUsing`, which Eloquent\Builder does NOT declare — it is
     * forwarded through __call — so it was the one case the bug did not
     * affect. Verifying with an example the defect cannot reach proves
     * nothing, which is the same mistake as the masking save() elsewhere in
     * this stack.
     */
    it('leaves no mutating builder method unhandled', function (): void {
        // Names that write, matched as PREFIXES so a future
        // `insertAnything()` is caught without editing this list.
        $writes = ['insert', 'update', 'upsert', 'delete', 'truncate', 'increment', 'decrement', 'touch', 'restore'];

        $declaredBy = function (string $class): array {
            return array_values(array_filter(array_map(
                fn (ReflectionMethod $m): ?string => $m->getDeclaringClass()->getName() === $class ? $m->getName() : null,
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
            )));
        };

        $surface = [];

        foreach ([Builder::class, Illuminate\Database\Eloquent\Builder::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($writes as $prefix) {
                    if (str_starts_with(strtolower($method->getName()), $prefix)) {
                        $surface[] = $method->getName();
                    }
                }
            }
        }

        $surface = array_values(array_unique($surface));

        // ⚠️ NOT covered, and deliberately so: `toBase()` hands back the
        // underlying query builder, which is the same door as
        // `DB::table('entries')`. No model-layer guard stands in front of raw
        // SQL, and it cannot be overridden because Laravel's own update(),
        // count() and pluck() route through it. ADR-020 scopes the guarantee
        // to the Eloquent layer for exactly this reason. Named here so the
        // boundary is a decision on the record rather than a silent gap.
        expect(method_exists(AuditedBuilder::class, 'toBase'))->toBeTrue();

        // Handled elsewhere, deliberately, each with its reason.
        $exempt = [
            // Routed to update() by SoftDeletingScope, where actionFor()
            // names it — auditing here too would record it twice.
            'delete', 'restore', 'restoreOrCreate', 'createOrRestore',
            // Reads, not writes.
            'onDelete', 'withTrashed', 'withoutTrashed', 'onlyTrashed',
            // Funnel into save() or into the overrides above.
            'updateOrCreate', 'insertOrIgnoreReturningIds', 'firstOrCreate',
            'createOrFirst', 'incrementQuietly', 'decrementQuietly',
            // firstOrCreate() then $instance->increment(): the first reaches
            // insertGetId() and the second the increment() override, so both
            // halves are already audited.
            'incrementOrCreate',
        ];

        // Append-only means exactly that: INSERTS are the one thing it must
        // allow, so the insert family is not a gap there.
        $appendable = [
            'insert', 'insertOrIgnore', 'insertOrIgnoreReturning', 'insertGetId',
            'insertUsing', 'insertOrIgnoreUsing',
            // Reach insert (allowed) or update (refused). Neither adds a path.
            'updateOrCreate', 'incrementOrCreate', 'firstOrCreate', 'createOrFirst',
            'onDelete', 'withTrashed', 'withoutTrashed', 'onlyTrashed',
            'restoreOrCreate', 'createOrRestore', 'restore',
            'insertOrIgnoreReturningIds', 'incrementQuietly', 'decrementQuietly',
        ];

        $unhandled = [
            'entries' => array_values(array_diff($surface, $declaredBy(AuditedBuilder::class), $exempt)),
            'audit log' => array_values(array_diff($surface, $declaredBy(AppendOnlyBuilder::class), $appendable)),
        ];

        expect($unhandled['entries'])->toBe([])
            ->and($unhandled['audit log'])->toBe([]);
    });

    it('refuses the forwarded creators, and writes nothing doing it', function (): void {
        $row = [
            'org_id' => $this->org->id, 'site_id' => $this->site->id,
            'entry_type_id' => $this->type->id, 'type_handle' => 'page',
            'title' => 'Smuggled', 'status' => 'draft',
        ];
        $sub = Entry::query()->select('id')->toBase();

        expect(fn () => Entry::query()->insertOrIgnoreUsing(['id'], $sub))->toThrow(RuntimeException::class)
            ->and(fn () => Entry::query()->insertUsing(['id'], $sub))->toThrow(RuntimeException::class)
            ->and(fn () => Entry::query()->insertOrIgnoreReturning([$row]))->toThrow(RuntimeException::class)
            ->and(fn () => Entry::query()->updateFrom(['status' => 'published']))->toThrow(RuntimeException::class);

        expect(Entry::query()->where('title', 'Smuggled')->exists())->toBeFalse();
    });

    it('refuses a truncate, which would leave nothing to say what had been there', function (): void {
        expect(fn () => Entry::query()->truncate())->toThrow(RuntimeException::class)
            ->and(Entry::query()->count())->toBe(2);
    });

    it('records ONE row per entry when a join matches it more than once', function (): void {
        // ⚠️ A bulk write over a join yields an entry's key once per matching
        // row. The write touches it once, so a row per duplicate would claim
        // a single change happened several times — an audit trail that
        // overstates is not evidence either.
        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        foreach ([1, 2, 3] as $ordering) {
            DB::table('entry_relations')->insert([
                'org_id' => $this->org->id, 'source_entry_id' => $this->one->getKey(),
                'target_entry_id' => $alice->getKey(), 'ordering' => $ordering,
            ]);
        }

        Entry::query()
            ->join('entry_relations', 'entry_relations.source_entry_id', '=', 'entries.id')
            ->update(['entries.status' => 'published']);

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1);
    });

    it('keeps a joined update\'s own expressions intact', function (): void {
        /*
         * ⚠️ Capturing keys and then writing on a FRESH key-only builder
         * dropped every join, so an assignment referencing the joined table
         * compiled against an alias that was no longer there. The query is
         * constrained now rather than replaced.
         */
        $alice = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Alice']);

        DB::table('entry_relations')->insert([
            'org_id' => $this->org->id, 'source_entry_id' => $this->one->getKey(),
            'target_entry_id' => $alice->getKey(), 'ordering' => 1,
        ]);

        Entry::query()
            ->join('entry_relations', 'entry_relations.source_entry_id', '=', 'entries.id')
            ->update(['entries.status' => DB::raw('CASE WHEN entry_relations.ordering = 1 THEN \'published\' ELSE \'draft\' END')]);

        expect($this->one->fresh()->status)->toBe('published')
            ->and($this->two->fresh()->status)->not->toBe('published')
            ->and(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1);
    })->skip(
        env('DB_CONNECTION', 'testing') !== 'mysql',
        'MySQL only, and the reason is an engine difference rather than a Kitsune one: '
        .'Laravel compiles a joined UPDATE as a subquery on SQLite and PostgreSQL, so their SET '
        .'clause cannot reference the joined table at all. MySQL is where the expression this '
        .'guards can exist, so MySQL is where it is asserted.'
    );

    it('does not reapply a spent limit to the captured keys', function (): void {
        /*
         * ⚠️ `offset(1)->limit(1)->update(...)` captured the SECOND row and
         * then reapplied offset 1 to that singleton, so the write touched
         * nothing while the loop still recorded the action. A limit that has
         * already selected the keys must not select among them again — the
         * audited set and the written set have to be the same set.
         */
        Entry::query()->orderBy('id')->offset(1)->limit(1)->update(['status' => 'published']);

        expect($this->two->fresh()->status)->toBe('published')
            ->and($this->one->fresh()->status)->not->toBe('published')
            ->and(AuditLog::for($this->two)->where('action', 'entry.updated')->count())->toBe(1)
            ->and(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(0);
    });

    it('refuses an update that moves an entry to another scope', function (): void {
        /*
         * ⚠️ The scope restricts which rows are SELECTED and says nothing
         * about the values written. `EnforcesScope` stamps these columns on
         * create only, so an update could transfer an entry out of the current
         * scope while the audit row was written under the OLD context —
         * leaving the destination holding an entry whose only trail belongs to
         * somebody else.
         */
        $rival = Org::create(['name' => 'T', 'slug' => 'transfer-rival']);

        expect(fn () => Entry::query()->whereKey($this->one->getKey())->update(['org_id' => $rival->id]))
            ->toThrow(RuntimeException::class, 'outside the current scope');

        expect($this->one->fresh()->org_id)->toBe($this->org->id);
    });

    it('refuses a QUALIFIED scope key, which a joined update writes', function (): void {
        /*
         * ⚠️ A joined update writes `entries.status` — this file has a MySQL
         * regression doing exactly that — so a guard looking for the bare
         * name was walked past by `entries.org_id`, moving entries across orgs
         * while the audit rows stayed under the old context.
         */
        $rival = Org::create(['name' => 'Q', 'slug' => 'qualified-rival']);

        expect(fn () => Entry::query()->whereKey($this->one->getKey())->update(['entries.org_id' => $rival->id]))
            ->toThrow(RuntimeException::class, 'outside the current scope');

        expect($this->one->fresh()->org_id)->toBe($this->org->id);
    });

    it('still allows an update that leaves the scope keys alone', function (): void {
        expect(fn () => Entry::query()->update(['status' => 'published']))
            ->not->toThrow(RuntimeException::class);
    });

    it('audits only the rows the predicate actually matched', function (): void {
        // Reading the keys BEFORE the write is what makes this possible: after
        // it, an updated row may no longer match and a deleted one has no id.
        Entry::query()->where('title', 'One')->update(['status' => 'published']);

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1)
            ->and(AuditLog::for($this->two)->where('action', 'entry.updated')->count())->toBe(0);
    });
});
