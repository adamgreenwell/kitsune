<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * A revision per saved version, so "what did this say last Tuesday" is
 * answerable — and so ADR-020's erasure has somewhere to reach.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Publisher', 'slug' => 'rev-pub']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'rev-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
});

afterEach(fn () => app(Context::class)->forget());

function anEntry(array $attributes = []): Entry
{
    return Entry::create([
        'entry_type_id' => test()->type->id,
        'title' => 'First',
        'values' => ['body' => 'one'],
        ...$attributes,
    ]);
}

describe('a revision per saved version', function (): void {
    it('records one when the entry is created', function (): void {
        $entry = anEntry();

        expect($entry->revisions()->count())->toBe(1)
            ->and($entry->revisions()->first()->title)->toBe('First');
    });

    it('records another on every content change', function (): void {
        $entry = anEntry();

        $entry->update(['title' => 'Second']);
        $entry->update(['values' => ['body' => 'two']]);

        expect($entry->revisionHistory()->pluck('title')->all())->toBe(['Second', 'Second', 'First']);
    });

    it('records NOTHING when nothing versioned changed', function (): void {
        // Touching a timestamp is not a new version of the content, and
        // recording one would fill the history with versions nobody authored.
        $entry = anEntry();

        $entry->touch();
        $entry->update(['author_id' => 7]);

        expect($entry->revisions()->count())->toBe(1);
    });

    it('snapshots the promoted columns, not only values', function (): void {
        // A revision holding only `values` restores an entry with no title,
        // which is worse than having no revisions at all.
        $entry = anEntry(['slug' => 'first', 'status' => 'published']);

        $revision = $entry->revisions()->first();

        expect($revision->title)->toBe('First')
            ->and($revision->slug)->toBe('first')
            ->and($revision->status)->toBe('published')
            ->and($revision->values)->toBe(['body' => 'one']);
    });

    it('records after the write, not before', function (): void {
        // A revision describing a save that then failed is a history of
        // things that never happened.
        $entry = anEntry();

        expect($entry->revisions()->first()->created_at)->not->toBeNull()
            ->and(Entry::whereKey($entry->getKey())->exists())->toBeTrue();
    });
});

describe('restoring puts state back as a NEW version', function (): void {
    it('writes the old state onto the entry', function (): void {
        $entry = anEntry();
        $original = $entry->revisions()->first();

        $entry->update(['title' => 'Changed', 'values' => ['body' => 'different']]);
        $entry->restoreRevision($original);

        expect($entry->fresh()->title)->toBe('First')
            ->and($entry->fresh()->values)->toBe(['body' => 'one']);
    });

    it('ADDS a version rather than rewriting history', function (): void {
        // Restoring version 1 must not delete version 2. Rewriting history
        // makes "what did this say last Tuesday" unanswerable, which is the
        // question revisions exist to answer.
        $entry = anEntry();
        $original = $entry->revisions()->first();

        $entry->update(['title' => 'Changed']);
        $entry->restoreRevision($original);

        expect($entry->revisions()->count())->toBe(3)
            ->and($entry->revisionHistory()->pluck('title')->all())->toBe(['First', 'Changed', 'First']);
    });

    it('refuses a revision belonging to another entry', function (): void {
        $mine = anEntry();
        $theirs = anEntry(['title' => 'Theirs', 'slug' => 'theirs']);

        expect(fn () => $mine->restoreRevision($theirs->revisions()->first()))
            ->toThrow(RuntimeException::class, 'belongs to another entry');
    });
});

it('keeps a bounded history, because full JSON snapshots are not free', function (): void {
    // The decision log lists revision storage growth as an open question and
    // it still is. Retention is the honest interim answer: a bound applied on
    // write, rather than a cost accumulating quietly while the question waits.
    $entry = anEntry();

    for ($i = 0; $i < Entry::KEEP_REVISIONS + 5; $i++) {
        $entry->update(['title' => "Version {$i}"]);
    }

    expect($entry->revisions()->count())->toBe(Entry::KEEP_REVISIONS)
        // The OLDEST go, not the newest.
        ->and($entry->revisionHistory()->first()->title)->toBe('Version '.(Entry::KEEP_REVISIONS + 4))
        ->and(EntryRevision::where('entry_id', $entry->getKey())->where('title', 'First')->exists())->toBeFalse();
});

it('leaves no revision behind for work that is not an authored change', function (): void {
    $entry = anEntry();

    $entry->withoutRevisions(fn (Entry $e) => $e->update(['title' => 'Silent']));

    expect($entry->revisions()->count())->toBe(1)
        ->and($entry->fresh()->title)->toBe('Silent');
});

it('restores revision recording afterwards, even when the work throws', function (): void {
    $entry = anEntry();

    try {
        $entry->withoutRevisions(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    $entry->update(['title' => 'After']);

    expect($entry->revisions()->count())->toBe(2);
});
