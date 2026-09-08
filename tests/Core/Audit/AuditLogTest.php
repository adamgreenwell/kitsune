<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    it('refuses a truncate, which would leave nothing to say what had been there', function (): void {
        expect(fn () => Entry::query()->truncate())->toThrow(RuntimeException::class)
            ->and(Entry::query()->count())->toBe(2);
    });

    it('audits only the rows the predicate actually matched', function (): void {
        // Reading the keys BEFORE the write is what makes this possible: after
        // it, an updated row may no longer match and a deleted one has no id.
        Entry::query()->where('title', 'One')->update(['status' => 'published']);

        expect(AuditLog::for($this->one)->where('action', 'entry.updated')->count())->toBe(1)
            ->and(AuditLog::for($this->two)->where('action', 'entry.updated')->count())->toBe(0);
    });
});
