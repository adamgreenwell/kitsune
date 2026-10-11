<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use PDOException;

/**
 * Each reader's address, for the entitlements page's rows — ADR-037 decisions 1–3, readers named on the owner's page.
 * `AuditActors`' shape: one read for the page's readers, words for anyone it does not find.
 *
 * ⚠️ KEPT NOWHERE. No static property, no cache, no `once()`, no session and no log: what this returns lives in the
 * page's private memo for one request, so an erasure has no copy of an address to miss (`ReaderErasure`).
 *
 * ⚠️ AN IDENTIFIER THAT CAN NEVER BE A KEY IS NEVER ASKED FOR. Each one goes through `userKey()` and back before any SQL,
 * and must come back exactly as stored. On an integer key `userKey()` alone refuses `'007'` and the planted
 * `e2e"onfocus=…`, which `whereKey()` would cast to 7 and 0 — `007` is not reader 7 (ADR-040); on a string key it
 * passes ` 1`, `a b` and `café` through, and the way back (`ReaderGuard::stored()`, printable ASCII) is what refuses
 * them. Such a row names nobody, with no query at all.
 *
 * ⚠️ TWO ORG FENCES, as sign-in has: the query's own `org_id = ?`, against a host model whose scope a provider strips,
 * and the row's written `org_id` read back, against a host query that ignores the first. And a row is named only by the
 * key it is read back as — the exact stored string — so a table that finds `ABC` as `abc` names neither by the other.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderNames
{
    use ReadsWrittenKeys;

    /** The page's readers' addresses are shown. */
    public const SHOWN = 'shown';

    /** The operator has not shown them here (`kitsune:readers addresses`). */
    public const HIDDEN = 'hidden';

    /** This installation's reader model cannot name readers here. */
    public const UNAVAILABLE = 'unavailable';

    /** A reader of this org, named by their address. */
    public const NAMED = 'named';

    /** No account of this org has the identifier — another org's, a deleted one's, or one no reader can have. */
    public const NONE = 'none';

    /** An account of this org whose stored address is no address a reader could have signed up with. */
    public const UNSHOWN = 'unshown';

    /** The readers table refused; the second half is its SQLSTATE alone. */
    public const UNREAD = 'unread';

    /**
     * At most this many keys in one query, so the statement's bindings stay bounded for any caller. The page asks for at
     * most `Entitlements::PER_PAGE` readers, and so reads once.
     */
    private const CHUNK = 100;

    /**
     * Whether this site's page names readers, and if not, why. No query until the last step, and none for a model
     * that cannot name: the model is asked first.
     *
     * ⚠️ THE ACCOUNTS' MODEL, NOT THE GUARD'S. `ReaderAccounts::model()` is null for a token guard over the very same
     * model, which `ReaderGuard::model()` is not (measured); and a model whose auth identifier is not its key would be
     * looked up by one and named by the other.
     *
     * @return self::SHOWN|self::HIDDEN|self::UNAVAILABLE
     */
    public static function state(Site $site): string
    {
        $accounts = app(ReaderAccounts::class);

        if (! $accounts->canName()) {
            return self::UNAVAILABLE;
        }

        return $accounts->addresses($site) ? self::SHOWN : self::HIDDEN;
    }

    /**
     * Each stored identifier's reader, for the org in context.
     *
     * Keyed by the identifier; the second half is the address when named, and the SQLSTATE when unread.
     *
     * @param  list<string>  $ids  `entitlements.reader_id` values, as stored
     * @return array<string, array{0: self::NAMED|self::NONE|self::UNSHOWN|self::UNREAD, 1: ?string}>
     */
    public static function of(#[\SensitiveParameter] array $ids): array
    {
        $model = app(ReaderAccounts::class)->model();
        $orgId = app(Context::class)->orgId();
        $names = [];
        $keys = [];

        // Keyed by the identifier, so one asked for twice is asked about once.
        foreach ($ids as $id) {
            $id = (string) $id;
            $key = $model !== null ? Permissions::userKey($id, $model) : null;

            // The round trip: a key the model accepts, which stores back as exactly this identifier.
            if ($key === null || $orgId === null || ReaderGuard::stored($key) !== $id) {
                $names[$id] = [self::NONE, null];
            } else {
                $keys[$id] = $key;
            }
        }

        if ($model === null || $orgId === null) {
            return $names;
        }

        $instance = new $model;

        foreach (array_chunk($keys, self::CHUNK, true) as $chunk) {
            try {
                $rows = $model::query()
                    ->whereKey(array_values($chunk))
                    ->where($instance->qualifyColumn('org_id'), $orgId)
                    ->get();
            } catch (PDOException $e) {
                $state = ReaderAccounts::sqlstate($e);

                foreach (array_keys($chunk) as $id) {
                    $names[$id] = [self::UNREAD, $state];
                }

                continue;
            }

            /** @var array<string, ReaderAccount> $found */
            $found = [];

            foreach ($rows as $row) {
                $read = $row instanceof ReaderAccount ? self::readBack($row, $model, $orgId) : null;

                if ($read !== null) {
                    $found[$read] = $row;
                }
            }

            foreach (array_keys($chunk) as $id) {
                $names[$id] = isset($found[$id]) ? self::addressOf($found[$id]) : [self::NONE, null];
            }
        }

        return $names;
    }

    /**
     * The identifier a row stands for, exactly as stored, when it is this org's reader and its key is its identifier —
     * or null.
     *
     * @param  class-string<Model>  $model
     */
    private static function readBack(ReaderAccount $row, string $model, int $orgId): ?string
    {
        if (! $row instanceof Model || ! $row instanceof Authenticatable) {
            return null;
        }

        if (self::writtenKey($row->getAttributes()['org_id'] ?? null) !== $orgId) {
            return null;
        }

        $byKey = ReaderGuard::stored(Permissions::userKey($row->getKey(), $model));

        return $byKey !== null && $byKey === ReaderGuard::stored(Permissions::userKey($row->getAuthIdentifier(), $model)) ? $byKey : null;
    }

    /**
     * A found reader's address, through the one grammar a reader's address is stored by — or why it is not shown.
     *
     * @return array{0: self::NAMED|self::UNSHOWN|self::UNREAD, 1: ?string}
     */
    private static function addressOf(ReaderAccount $reader): array
    {
        try {
            $address = EmailAddress::normalise($reader->readerEmail());
        } catch (PDOException $e) {
            // A host's lazy accessor may query; its failure carries nothing but its state.
            return [self::UNREAD, ReaderAccounts::sqlstate($e)];
        }

        return $address !== null ? [self::NAMED, $address] : [self::UNSHOWN, null];
    }
}
