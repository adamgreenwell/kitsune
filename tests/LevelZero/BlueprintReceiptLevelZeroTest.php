<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;

/*
 * The blueprint receipt, seen as production commits it — ADR-039's owed "killing an apply between the receipt and
 * the rows", at a real level 0.
 *
 * ⚠️ READ FROM A SECOND CONNECTION, BECAUSE ONLY A COMMIT IS VISIBLE THERE. Under `RefreshDatabase` every write is in
 * one wrapper transaction, so "the receipt commits before the rows" and "the manifest commits with the rows" are both
 * claims nothing in the rest of the suite can see. A second PDO on the same file sees what has committed and nothing
 * else, which is exactly what a process killed at that moment leaves behind.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
        ]),
    ];
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']])];

    $this->org = Org::create(['slug' => 'receipt-zero', 'name' => 'Receipt zero']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/** What a second process sees: the committed state of the file, and nothing in flight. @return array<string, mixed> */
function receiptZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);
    $receipt = $pdo->query("select version, manifest, applied_at from blueprints where handle = 'fixture'")->fetch(PDO::FETCH_ASSOC);

    return [
        'receipt' => $receipt === false ? null : $receipt,
        'entry_types' => (int) $pdo->query("select count(*) from entry_types where handle = 'dispatch'")->fetchColumn(),
        'roles' => (int) $pdo->query("select count(*) from roles where handle = 'dispatcher'")->fetchColumn(),
    ];
}

it('commits the receipt before it writes the rows, so a kill between the two leaves it findable', function (): void {
    $seen = null;

    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        if ($seen === null && str_starts_with($query->sql, 'insert into "entry_types"')) {
            $seen = receiptZeroSeen($this->custodyFile);

            throw new RuntimeException('killed between the receipt and the rows');
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'killed between the receipt and the rows');

    expect($seen)->toBe([
        'receipt' => ['version' => '1.0.0', 'manifest' => null, 'applied_at' => null],
        'entry_types' => 0,
        'roles' => 0,
    ]);

    $after = receiptZeroSeen($this->custodyFile);

    expect($after['entry_types'])->toBe(0)
        ->and($after['roles'])->toBe(0)
        ->and($after['receipt'])->toBe(['version' => '1.0.0', 'manifest' => null, 'applied_at' => null])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

/**
 * ⚠️ THE MANIFEST COMMITS WITH THE ROWS. A kill after the commit and before the finish leaves rows AND their manifest,
 * which is the one state the next run completes rather than redoes — and the second connection proves both landed.
 */
it('commits the manifest with the rows, so a kill after them is finished by the next run', function (): void {
    SchemaManagerStandIn::install()->throwOnce(new RuntimeException('killed after the rows committed'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'killed after the rows committed');

    $seen = receiptZeroSeen($this->custodyFile);

    expect($seen['entry_types'])->toBe(1)
        ->and($seen['roles'])->toBe(1)
        ->and($seen['receipt']['manifest'])->not->toBeNull()
        ->and(json_decode((string) $seen['receipt']['manifest'], true)['roles'][0]['handle'])->toBe('dispatcher')
        ->and($seen['receipt']['applied_at'])->toBeNull()
        ->and(DB::connection()->transactionLevel())->toBe(0);

    app()->forgetInstance(SchemaManager::class);

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(1);

    $finished = receiptZeroSeen($this->custodyFile);

    expect($finished['entry_types'])->toBe(1)
        ->and($finished['roles'])->toBe(1)
        ->and($finished['receipt']['applied_at'])->not->toBeNull()
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull()
        /* The finish built the index for real: the generated column is on the table. */
        ->and(array_filter(Schema::getColumnListing('entries'), static fn (string $column): bool => str_starts_with($column, 'idx_dispatch_code')))->not->toBeEmpty();
});

/**
 * ⚠️ AND INSIDE THEIR TRANSACTION, NOT JUST AFTER IT. A kill between a commit of the rows and a separate write of the
 * manifest is the stranded org this exists to prevent — rows there, manifest null, and the next run refusing its own
 * types. So at the moment the manifest is written, a second process must not yet see the rows: they commit together.
 */
it('writes the manifest before the rows are visible, in their transaction', function (): void {
    $seen = null;

    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        if ($seen === null && str_starts_with($query->sql, 'update "blueprints"') && str_contains($query->sql, '"manifest"')) {
            $seen = receiptZeroSeen($this->custodyFile) + ['level' => DB::connection()->transactionLevel()];

            throw new RuntimeException('killed as the manifest was written');
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'killed as the manifest was written');

    expect($seen['entry_types'])->toBe(0)
        ->and($seen['roles'])->toBe(0)
        ->and($seen['level'])->toBe(1);

    $after = receiptZeroSeen($this->custodyFile);

    expect($after['entry_types'])->toBe(0)
        ->and($after['roles'])->toBe(0)
        ->and($after['receipt'])->toBe(['version' => '1.0.0', 'manifest' => null, 'applied_at' => null]);
});

/** The fixture at 1.1.0: one indexed field more, so a merge has rows to write and a sync to stop in. */
function receiptZeroNextVersion(): void
{
    FixtureBlueprint::$version = '1.1.0';
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
            new FieldDeclaration(handle: 'dispatch_ref', type: 'text', label: 'Reference', piiClass: 'none', isIndexed: true),
        ]),
    ];
}

/**
 * ⚠️ A MERGE'S VERSION AND MANIFEST COMMIT WITH ITS ROWS, `applied_at` NULLED WITH THEM. A kill in the sync after the
 * commit leaves all three together at the new version — state 2 — which the next run finishes; a version written after
 * the commit would leave 1.1.0's rows under a receipt that says 1.0.0.
 */
it('commits a merge\'s version and manifest with its rows, so a kill after them is finished at the new version', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    receiptZeroNextVersion();
    SchemaManagerStandIn::install()->throwOnce(new RuntimeException('killed after the merge committed'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'killed after the merge committed');

    $seen = receiptZeroSeen($this->custodyFile);
    $pdo = new PDO('sqlite:'.$this->custodyFile);

    expect($seen['receipt']['version'])->toBe('1.1.0')
        ->and(json_decode((string) $seen['receipt']['manifest'], true)['version'])->toBe('1.1.0')
        ->and(array_column(json_decode((string) $seen['receipt']['manifest'], true)['entry_types'][0]['fields'], 'handle'))->toBe(['dispatch_code', 'dispatch_ref'])
        ->and($seen['receipt']['applied_at'])->toBeNull()
        ->and((int) $pdo->query("select count(*) from field_storage where handle = 'dispatch_ref'")->fetchColumn())->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);

    app()->forgetInstance(SchemaManager::class);

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(2)
        ->and(receiptZeroSeen($this->custodyFile)['receipt']['applied_at'])->not->toBeNull();
});

/**
 * ⚠️ A RIVAL HOLDING THE WRITE LOCK IS NAMED, NOT SURFACED AS `database is locked`. SQLite compiles `lockForUpdate()`
 * away, so a merge that has read fails at its first write — at once, without waiting out the busy timeout, because a
 * reader upgrading past a writer would deadlock — and the receipt it re-reads has not moved: the rival has not
 * committed. Measured so in both journal modes (ADR-039, the merge as built).
 */
it('names a rival holding the database\'s write lock, rather than its raw lock error', function (string $mode): void {
    DB::statement("pragma journal_mode = {$mode}");
    BlueprintApplier::apply(new FixtureBlueprint);
    receiptZeroNextVersion();
    SchemaManagerStandIn::install();

    $rival = new PDO('sqlite:'.$this->custodyFile);
    $rival->exec('begin immediate');

    try {
        $started = microtime(true);

        expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(
            RuntimeException::class,
            'The merge of [fixture] from 1.0.0 to 1.1.0 stopped: another write reached the same rows at the same moment',
        );

        expect(microtime(true) - $started)->toBeLessThan(2.0);
    } finally {
        $rival->exec('rollback');
    }

    expect(receiptZeroSeen($this->custodyFile)['receipt']['version'])->toBe('1.0.0')
        ->and(DB::connection()->transactionLevel())->toBe(0)
        /* And run again, as the refusal says, it merges. */
        ->and(BlueprintApplier::apply(new FixtureBlueprint)['version'])->toBe('1.1.0');
})->with(['delete', 'wal']);

/**
 * ⚠️ AND A RIVAL THAT COMMITTED WHILE THIS ONE PLANNED IS ANOTHER APPLY. In WAL mode this run's snapshot is older than
 * the rival's commit, so its first write fails with the same lock error — and the receipt it re-reads afterwards has
 * moved, which is what the refusal names.
 */
it('names a rival that committed while it planned', function (): void {
    DB::statement('pragma journal_mode = wal');
    BlueprintApplier::apply(new FixtureBlueprint);
    receiptZeroNextVersion();
    SchemaManagerStandIn::install();

    $file = $this->custodyFile;
    $staged = false;

    DB::listen(function (QueryExecuted $query) use (&$staged, $file): void {
        if (! $staged && DB::connection()->transactionLevel() === 1 && str_contains($query->sql, 'from "blueprints"')) {
            $staged = true;
            (new PDO('sqlite:'.$file))->exec("update blueprints set version = '1.2.0' where handle = 'fixture'");
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'another apply of it ran at the same moment');

    expect($staged)->toBeTrue()
        ->and(receiptZeroSeen($this->custodyFile)['receipt']['version'])->toBe('1.2.0');
});

/**
 * ⚠️ AND IN ONE STATEMENT, INSIDE THE ROWS' TRANSACTION. A version written after the commit — even before the sync — would
 * leave a moment where 1.1.0's rows and manifest stand under a receipt that says 1.0.0, finished; and an `applied_at`
 * left set would let a kill there read as done. So the write is caught as it happens: one update carrying all three, at
 * transaction level 1, while a second connection cannot yet see the field the merge added.
 */
it('writes a merge\'s version, manifest and applied_at together, inside its rows\' transaction', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    receiptZeroNextVersion();
    SchemaManagerStandIn::install();

    $file = $this->custodyFile;
    $seen = null;

    DB::listen(function (QueryExecuted $query) use (&$seen, $file): void {
        if ($seen === null && str_starts_with($query->sql, 'update "blueprints"') && str_contains($query->sql, '"manifest"')) {
            $pdo = new PDO('sqlite:'.$file);
            $seen = [
                'level' => DB::connection()->transactionLevel(),
                'version' => str_contains($query->sql, '"version"'),
                'applied_at' => str_contains($query->sql, '"applied_at"') && in_array(null, $query->bindings, true),
                'visible' => (int) $pdo->query("select count(*) from field_storage where handle = 'dispatch_ref'")->fetchColumn(),
            ];
        }
    });

    expect(BlueprintApplier::apply(new FixtureBlueprint)['version'])->toBe('1.1.0')
        ->and($seen)->toBe(['level' => 1, 'version' => true, 'applied_at' => true, 'visible' => 0]);
});

/**
 * ⚠️ A COMMIT THAT FAILS IS ROLLED BACK, NOT LEFT OPEN. In rollback-journal mode a reader holding its lock makes this
 * run's COMMIT fail busy, and SQLite keeps the transaction — while Laravel's count drops to 0 — so the re-read saw this
 * run's own receipt, named it another apply's, and the connection kept the write lock for whatever it ran next. Found
 * by review; the busy timeout is shortened here only so the test does not wait out the configured five seconds.
 */
it('rolls back a merge whose commit failed, names the contention, and leaves the connection usable', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    receiptZeroNextVersion();
    SchemaManagerStandIn::install();
    DB::connection()->getPdo()->exec('pragma busy_timeout = 200');

    $reader = new PDO('sqlite:'.$this->custodyFile);
    $reader->exec('begin');
    $reader->query('select count(*) from orgs')->fetchColumn();

    try {
        expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(
            RuntimeException::class,
            'The merge of [fixture] from 1.0.0 to 1.1.0 stopped: another write reached the same rows at the same moment',
        );

        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    } finally {
        $reader->exec('rollback');
    }

    $seen = receiptZeroSeen($this->custodyFile);

    expect($seen['receipt']['version'])->toBe('1.0.0')
        ->and($seen['receipt']['applied_at'])->not->toBeNull()
        ->and((int) (new PDO('sqlite:'.$this->custodyFile))->query("select count(*) from field_storage where handle = 'dispatch_ref'")->fetchColumn())->toBe(0)
        /* And the same connection, run again as the refusal says, merges. */
        ->and(BlueprintApplier::apply(new FixtureBlueprint)['version'])->toBe('1.1.0');
});

/** The fresh apply's catch discards a failed commit too; its lock error still reads as itself (ADR-039's limit). */
it('rolls back a fresh apply whose commit failed, and leaves the connection usable', function (): void {
    DB::connection()->getPdo()->exec('pragma busy_timeout = 200');

    /* The reader takes its lock inside the rows' transaction, after the intent record committed, so it is the COMMIT that fails. */
    $reader = new PDO('sqlite:'.$this->custodyFile);
    $staged = false;
    DB::listen(function (QueryExecuted $query) use ($reader, &$staged): void {
        if (! $staged && str_starts_with($query->sql, 'insert into "entry_types"')) {
            $staged = true;
            $reader->exec('begin');
            $reader->query('select count(*) from orgs')->fetchColumn();
        }
    });

    try {
        expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(PDOException::class, 'database is locked');

        expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    } finally {
        if ($reader->inTransaction()) {
            $reader->exec('rollback');
        }
    }

    expect(receiptZeroSeen($this->custodyFile))->toBe([
        'receipt' => ['version' => '1.0.0', 'manifest' => null, 'applied_at' => null],
        'entry_types' => 0,
        'roles' => 0,
    ])
        ->and(BlueprintApplier::apply(new FixtureBlueprint)['roles_created'])->toBe(['dispatcher']);
});

/*
 * ── The reverse (ADR-039, the reverse as built) ─────────────────────────────────────────────────────────────────────
 */

/**
 * ⚠️ THE ROWS AND THE RECEIPT GO TOGETHER OR NOT AT ALL. A receipt naming rows that are gone is the one state nothing
 * can recover from, so at the moment the receipt is deleted — the transaction's last statement — a second process must
 * still see both; and after the commit, neither.
 */
it('removes the rows and the receipt in one commit', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $file = $this->custodyFile;
    $seen = null;

    DB::listen(function (QueryExecuted $query) use (&$seen, $file): void {
        if ($seen === null && str_starts_with($query->sql, 'delete from "blueprints"')) {
            $seen = receiptZeroSeen($file) + ['level' => DB::connection()->transactionLevel()];
        }
    });

    expect(BlueprintApplier::reverse('fixture')['outcome'])->toBe('reversed');

    expect($seen['receipt'])->not->toBeNull()
        ->and($seen['entry_types'])->toBe(1)
        ->and($seen['roles'])->toBe(1)
        ->and($seen['level'])->toBe(1)
        ->and(receiptZeroSeen($this->custodyFile))->toBe(['receipt' => null, 'entry_types' => 0, 'roles' => 0]);
});

/** The generated column's name, as the real schema manager names it — read off the table, so nothing is assumed. */
function receiptZeroColumns(): array
{
    return array_values(array_filter(Schema::getColumnListing('entries'), static fn (string $column): bool => str_starts_with($column, 'idx_dispatch_code')));
}

/**
 * ⚠️ DDL AFTER THE COMMIT, AND REFERENCE-COUNTED. The column is dropped once the receipt is gone for every process — DDL
 * commits implicitly on MySQL and MariaDB, so inside it would have committed half a reverse — and a column another
 * organisation's indexed field still projects to is kept.
 */
it('drops the generated column after its commit, and keeps one another organisation still uses', function (bool $shared): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $column = receiptZeroColumns();
    expect($column)->toHaveCount(1);

    if ($shared) {
        $other = Org::create(['slug' => 'receipt-zero-other', 'name' => 'Other']);
        app(Context::class)->setOrg($other);
        BlueprintApplier::apply(new FixtureBlueprint);
        app(Context::class)->setOrg($this->org);
    }

    $file = $this->custodyFile;
    $seen = null;

    DB::listen(function (QueryExecuted $query) use (&$seen, $file): void {
        if ($seen === null && str_contains(strtolower($query->sql), 'drop')) {
            $seen = receiptZeroSeen($file) + ['level' => DB::connection()->transactionLevel()];
        }
    });

    $result = BlueprintApplier::reverse('fixture');

    expect($result['dropped'])->toBe(1)
        ->and($result['undropped'])->toBe([])
        ->and(receiptZeroColumns())->toBe($shared ? $column : []);

    if ($shared) {
        /* Nothing was dropped, and the other organisation's rows and receipt stand. */
        expect($seen)->toBeNull()
            ->and((int) (new PDO('sqlite:'.$this->custodyFile))->query("select count(*) from blueprints where handle = 'fixture'")->fetchColumn())->toBe(1);
    } else {
        expect($seen)->toBe(['receipt' => null, 'entry_types' => 0, 'roles' => 0, 'level' => 0]);
    }
})->with(['its own' => false, 'shared with another organisation' => true]);

/**
 * ⚠️ A RIVAL HOLDING THE WRITE LOCK IS NAMED, AND NOTHING IS WRITTEN. As the merge measured: SQLite compiles every
 * `lockForUpdate()` away, so the reverse plans under no lock and fails at its first write, at once, in both journal
 * modes — and the receipt it re-reads has not moved.
 */
it('names a rival holding the database\'s write lock, and reverses when run again', function (string $mode): void {
    DB::statement("pragma journal_mode = {$mode}");
    BlueprintApplier::apply(new FixtureBlueprint);
    SchemaManagerStandIn::install()->recordOnly();

    $rival = new PDO('sqlite:'.$this->custodyFile);
    $rival->exec('begin immediate');

    try {
        $started = microtime(true);

        expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
            'The reverse of [fixture] stopped: another write reached the same rows at the same moment, and nothing this run '
            .'wrote was kept. Run it again.'
        );

        expect(microtime(true) - $started)->toBeLessThan(2.0);
    } finally {
        $rival->exec('rollback');
    }

    $seen = receiptZeroSeen($this->custodyFile);

    expect($seen['receipt'])->not->toBeNull()
        ->and($seen['entry_types'])->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(BlueprintApplier::reverse('fixture')['outcome'])->toBe('reversed');
})->with(['delete', 'wal']);

/** ⚠️ A COMMIT THAT FAILS IS ROLLED BACK, NOT LEFT OPEN — the merge's finding, held for the reverse's catch too. */
it('rolls back a reverse whose commit failed, names the contention, and leaves the connection usable', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    SchemaManagerStandIn::install()->recordOnly();
    DB::connection()->getPdo()->exec('pragma busy_timeout = 200');

    $reader = new PDO('sqlite:'.$this->custodyFile);
    $staged = false;
    DB::listen(function (QueryExecuted $query) use ($reader, &$staged): void {
        if (! $staged && str_starts_with($query->sql, 'delete from "blueprints"')) {
            $staged = true;
            $reader->exec('begin');
            $reader->query('select count(*) from orgs')->fetchColumn();
        }
    });

    try {
        expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class,
            'The reverse of [fixture] stopped: another write reached the same rows at the same moment'
        );

        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    } finally {
        if ($reader->inTransaction()) {
            $reader->exec('rollback');
        }
    }

    $seen = receiptZeroSeen($this->custodyFile);

    expect($staged)->toBeTrue()
        ->and($seen['receipt'])->not->toBeNull()
        ->and($seen['entry_types'])->toBe(1)
        ->and(BlueprintApplier::reverse('fixture')['outcome'])->toBe('reversed');
});

/**
 * ⚠️ AN INTENT RECORD A REVERSE DELETED BEFORE THE ROWS IS NAMED, AND NO ROW COMMITS WITHOUT IT. Before the apply re-read
 * its receipt inside the rows' transaction, the rows committed with no receipt naming them and the run printed "Applied".
 */
it('stops a fresh apply whose intent record another process deleted, in either journal mode', function (string $mode): void {
    DB::statement("pragma journal_mode = {$mode}");
    SchemaManagerStandIn::install()->recordOnly();
    $file = $this->custodyFile;
    $staged = false;

    DB::listen(function (QueryExecuted $query) use (&$staged, $file): void {
        if (! $staged && str_starts_with($query->sql, 'insert into "blueprints"')) {
            $staged = true;
            (new PDO('sqlite:'.$file))->exec("delete from blueprints where handle = 'fixture'");
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'The apply of [fixture] stopped and wrote nothing: this organisation\'s receipt for it was removed while it ran'
    );

    expect($staged)->toBeTrue()
        ->and(receiptZeroSeen($this->custodyFile))->toBe(['receipt' => null, 'entry_types' => 0, 'roles' => 0])
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with(['delete', 'wal']);

/**
 * ⚠️ AND IN WAL MODE, ONE DELETED AFTER THE APPLY'S RE-READ: the apply's snapshot is then stale, its first write fails
 * busy, and its catch finds the receipt gone — named as removed, not as the raw lock error. In rollback-journal mode the
 * deleter cannot get in at that moment at all: the apply's read holds the shared lock until it commits.
 */
it('names an intent record deleted after its re-read as removed, in WAL mode', function (): void {
    DB::statement('pragma journal_mode = wal');
    SchemaManagerStandIn::install()->recordOnly();
    $file = $this->custodyFile;
    $staged = false;

    DB::listen(function (QueryExecuted $query) use (&$staged, $file): void {
        if (! $staged && DB::connection()->transactionLevel() === 1 && str_contains($query->sql, 'from "blueprints"')) {
            $staged = true;
            (new PDO('sqlite:'.$file))->exec("delete from blueprints where handle = 'fixture'");
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))->toThrow(RuntimeException::class,
        'The apply of [fixture] stopped and wrote nothing: this organisation\'s receipt for it was removed while it ran'
    );

    expect($staged)->toBeTrue()
        ->and(receiptZeroSeen($this->custodyFile))->toBe(['receipt' => null, 'entry_types' => 0, 'roles' => 0])
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});
