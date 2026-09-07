<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\LogicalType;
use Kitsune\Core\Fields\Projection;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\FieldStorage;
use RuntimeException;

/**
 * Turns `field_storage.is_indexed` into real generated columns and indexes.
 *
 * This is the mechanism that avoids Drupal's join explosion (ADR-006): one
 * table, one row per entry, and real indexes on the fields that need them —
 * rather than a table per field and the 27-join, 697-second query that
 * followed from it.
 *
 * ADR-028: a column is named for its projection, not its owner, and is shared
 * by every field storage row that projects the same way. So `dropIndex()` is
 * reference-counted — an org un-indexing its own field must not remove a
 * column another org is still querying.
 *
 * **Not a model observer, deliberately.** DDL implicitly commits on MySQL, so
 * a row write and a schema change cannot be one transaction there whatever we
 * do. Hiding that inside `saved()` would make an un-rollbackable operation
 * look atomic. Callers invoke `sync()` explicitly after saving, and
 * `reconcile()` exists to repair the drift that a half-completed pair leaves.
 */
final class SchemaManager
{
    /**
     * Generated columns permitted on the shared `entries` table.
     *
     * This is a cap on columns, not on rows in `field_storage` — many rows
     * across many orgs can share one column (ADR-028), and the scarce
     * resource is the table. PostgreSQL stops at 1600 columns and MySQL at a
     * 65,535-byte row; well before either, every generated column is write
     * amplification on every entry save. 20 is a starting figure pending
     * benchmark data, and it is enforced rather than documented.
     */
    public const MAX_GENERATED_COLUMNS = 20;

    /** PostgreSQL truncates identifiers here; MySQL rejects past 64. */
    public const MAX_IDENTIFIER_BYTES = 63;

    private const COLUMN_PREFIX = 'idx_';

    public function __construct(
        private readonly FieldTypeRegistry $registry,
    ) {}

    /**
     * Reconcile the database with what this field storage row now says.
     *
     * ⚠️ A no-op for a field that projects to nothing. `rich_text`, `json`,
     * `relation` and `slug` deliberately have no generated column, and
     * `dropIndex()` starts by asking for the column NAME — which throws for
     * them. So the documented "call sync() after saving" flow blew up on four
     * perfectly ordinary field types, and every caller would have had to
     * special-case them.
     */
    public function sync(FieldStorage $storage): void
    {
        if ($this->registry->get($storage->type)->projection(new FieldConfig($storage)) === null) {
            return;
        }

        $storage->is_indexed ? $this->index($storage) : $this->dropIndex($storage);
    }

    /**
     * Reconcile the whole table: add what is missing, drop what is orphaned.
     *
     * The repair path for drift — a DDL statement that failed after its row
     * was written, a database restored from a dump taken mid-change, or a
     * field storage row deleted without a matching drop.
     *
     * @return array{added: list<string>, dropped: list<string>}
     */
    public function reconcile(): array
    {
        $wanted = [];

        foreach (FieldStorage::query()->where('is_indexed', true)->get() as $storage) {
            $wanted[$storage->generatedColumnName()] = $storage;
        }

        // ⚠️ Orphans go FIRST. With the table at the cap and drift consisting
        // of one orphan plus one wanted column, adding first hits guardCap()
        // and throws — so a capacity-NEUTRAL replacement could never be
        // repaired, and --force reported a failure the operator could not act
        // on. Dropping first makes the swap fit.
        $dropped = [];

        foreach ($this->generatedColumns() as $column) {
            if (! isset($wanted[$column])) {
                $this->dropColumn($column, $column.'_site_idx');
                $dropped[] = $column;
            }
        }

        $added = [];

        foreach ($wanted as $column => $storage) {
            if ($this->hasColumn($column) && $this->hasIndex($storage->generatedIndexName())) {
                continue;
            }

            $this->index($storage);
            $added[] = $column;
        }

        return ['added' => $added, 'dropped' => $dropped];
    }

    public function index(FieldStorage $storage): void
    {
        $this->guard($storage);

        $driver = $this->driver();
        $column = $storage->generatedColumnName();

        // The column and the index are checked separately on purpose. They
        // are two statements and DDL implicitly commits on MySQL, so the pair
        // can half-succeed: returning early because the column exists would
        // leave a missing index that nothing ever retries, while the dry run
        // reported the schema as in sync and every query scanned.
        $hasColumn = $this->hasColumn($column);
        $hasIndex = $this->hasIndex($storage->generatedIndexName());

        if ($hasColumn && $hasIndex) {
            // Another row already projects this way. ADR-028: that is
            // deduplication, not a conflict — the expression is identical.
            return;
        }

        $projection = $this->projectionFor($storage);

        if (! $hasColumn) {
            DB::statement($driver->addGeneratedColumnSql(
                'entries',
                $column,
                'values',
                $storage->handle,
                $projection,
            ));
        }

        if (! $hasIndex) {
            // ADR-021: every composite index leads with the scope key. entries
            // is site-scoped, so site_id leads — which also gives org isolation
            // transitively, since a site belongs to exactly one org.
            DB::statement($driver->createIndexSql(
                'entries',
                $storage->generatedIndexName(),
                'site_id',
                $column,
            ));
        }
    }

    /**
     * Drop the column only once nothing else projects to it.
     *
     * ADR-028: `idx_price__number` may be shared by several orgs' `price`
     * fields. Dropping it because one of them un-indexed would break the
     * others' queries — cross-org action at a distance, which is the class of
     * defect ADR-021 says has no framework safety net.
     */
    public function dropIndex(FieldStorage $storage): void
    {
        $column = $storage->generatedColumnName();

        if (! $this->hasColumn($column)) {
            return;
        }

        if ($this->otherRowsWant($storage)) {
            return;
        }

        $this->dropColumn($column, $storage->generatedIndexName());
    }

    private function dropColumn(string $column, string $index): void
    {
        $driver = $this->driver();

        // Index first: a generated column cannot be dropped while an index
        // references it, and SQLite refuses outright.
        //
        // Guarded, because MySQL's DROP INDEX has no IF EXISTS and errors
        // with `ERROR 1091` when the index is absent — which is exactly the
        // half-applied state this path exists to repair, so dropping
        // unconditionally meant reconcile() could never reach the column.
        if ($this->hasIndex($index)) {
            DB::statement($driver->dropIndexSql('entries', $index));
        }

        DB::statement($driver->dropGeneratedColumnSql('entries', $column));
    }

    /**
     * ⚠️ Compared by COLUMN NAME, not by field type handle.
     *
     * The column is named for its projection signature, and a signature is
     * not a function of the type: `text` with `maxLength: 64` and `select`
     * both project to `string64` and deliberately SHARE one column, while two
     * `number` rows configured `integer` and `decimal` do not. A `type`
     * predicate got both cases backwards — dropping a column another org was
     * still querying, and leaving an orphan behind.
     *
     * Filtered by handle in SQL, since the signature cannot be expressed
     * there, then compared exactly in PHP.
     */
    private function otherRowsWant(FieldStorage $storage): bool
    {
        $column = $storage->generatedColumnName();

        return FieldStorage::query()
            ->where('is_indexed', true)
            ->where('handle', $storage->handle)
            ->when($storage->exists, fn ($query) => $query->whereKeyNot($storage->getKey()))
            ->get()
            ->contains(fn (FieldStorage $other): bool => $other->generatedColumnName() === $column);
    }

    private function projectionFor(FieldStorage $storage): Projection
    {
        $projection = $this->registry->get($storage->type)->projection(new FieldConfig($storage));

        return $projection ?? throw new RuntimeException(
            "Field type [{$storage->type}] cannot be indexed: it projects to no scalar column."
        );
    }

    /** Refuse before touching the table, with the reason. */
    private function guard(FieldStorage $storage): void
    {
        $type = $this->registry->get($storage->type);

        if ($type->strategy() === StorageStrategy::Promoted) {
            throw new RuntimeException(
                "[{$storage->handle}] is a promoted field and is already a real column — it needs no generated column."
            );
        }

        if ($type->strategy() === StorageStrategy::Relational) {
            throw new RuntimeException(
                "[{$storage->handle}] is relational and is indexed by entry_relations, not by a generated column."
            );
        }

        if (! $type->isIndexable()) {
            throw new RuntimeException("Field type [{$storage->type}] is not indexable.");
        }

        if ($storage->isMultiValue()) {
            // A JSON array cannot project to a scalar. Multi-value fields
            // that need querying want `relation` instead.
            throw new RuntimeException(
                "[{$storage->handle}] has cardinality {$storage->cardinality} and cannot be projected to a scalar column."
            );
        }

        $this->guardWidth($storage);
        $this->guardIdentifiers($storage);
        $this->guardCap($storage);
    }

    /**
     * Engines cap how wide an indexed string may be, so refuse past it.
     *
     * Measured: MySQL's InnoDB key limit is 3,072 bytes and utf8mb4 costs
     * four bytes a character, so `VARCHAR(1000)` fails with `ERROR 1071:
     * Specified key was too long` while `VARCHAR(700)` succeeds. Refusing
     * here beats failing at ALTER TABLE with an engine's own message, and it
     * beats the alternative that was in place — projecting a 1,000-character
     * field through VARCHAR(255) and silently truncating the index, so two
     * distinct values compared equal.
     */
    private function guardWidth(FieldStorage $storage): void
    {
        $projection = $this->projectionFor($storage);

        if ($projection->logical !== LogicalType::String) {
            return;
        }

        if ($projection->precision > Projection::MAX_INDEXED_STRING_WIDTH) {
            throw new RuntimeException(sprintf(
                '[%s] is %d characters wide and cannot be indexed: engines cap an index key at '
                .'%d characters here (MySQL refuses past a 3,072-byte key). Narrow the field, or '
                .'search it with full-text search rather than a scalar projection.',
                $storage->handle,
                $projection->precision,
                Projection::MAX_INDEXED_STRING_WIDTH,
            ));
        }
    }

    /**
     * A truncated identifier is a silent collision, not an error.
     *
     * FieldStorage bounds the handle, but a module may register a field type
     * with a long handle of its own, so the assembled name is re-checked here
     * rather than assumed (ADR-028).
     */
    private function guardIdentifiers(FieldStorage $storage): void
    {
        foreach ([$storage->generatedColumnName(), $storage->generatedIndexName()] as $identifier) {
            if (strlen($identifier) > self::MAX_IDENTIFIER_BYTES) {
                throw new RuntimeException(sprintf(
                    'Identifier [%s] is %d bytes; PostgreSQL truncates at %d and a truncated name '
                    .'silently collides with another field. Shorten the field handle or the field '
                    .'type handle (ADR-028).',
                    $identifier,
                    strlen($identifier),
                    self::MAX_IDENTIFIER_BYTES,
                ));
            }
        }
    }

    private function guardCap(FieldStorage $storage): void
    {
        $existing = $this->generatedColumns();

        // Already present means this adds nothing — the cap is on columns.
        if (in_array($storage->generatedColumnName(), $existing, true)) {
            return;
        }

        if (count($existing) >= self::MAX_GENERATED_COLUMNS) {
            throw new RuntimeException(sprintf(
                'Generated column limit reached (%d on the entries table). Every generated column '
                .'costs write throughput on every entry save, and without a cap one organisation '
                .'can degrade the shared table for everyone. Un-index a field before adding another.',
                self::MAX_GENERATED_COLUMNS,
            ));
        }
    }

    /** @return list<string> */
    private function generatedColumns(): array
    {
        return array_values(array_filter(
            DB::getSchemaBuilder()->getColumnListing('entries'),
            static fn (string $column): bool => str_starts_with($column, self::COLUMN_PREFIX),
        ));
    }

    private function hasColumn(string $column): bool
    {
        return in_array($column, DB::getSchemaBuilder()->getColumnListing('entries'), true);
    }

    private function hasIndex(string $index): bool
    {
        foreach (DB::getSchemaBuilder()->getIndexes('entries') as $existing) {
            // Engines lowercase index names to differing degrees.
            if (strcasecmp((string) $existing['name'], $index) === 0) {
                return true;
            }
        }

        return false;
    }

    private function driver(): SchemaDriver
    {
        return DriverFactory::for(DB::connection());
    }
}
