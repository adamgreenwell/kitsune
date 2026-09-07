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
