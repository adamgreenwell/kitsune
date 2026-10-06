<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Filament\Facades\Filament;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;

/**
 * Who the reader signed in to this request is — ADR-037's core half: the host declares the guard, and core never
 * guesses.
 *
 * ⚠️ NOTHING DECLARED, NO READER. `kitsune.readers.guard` has no default. Until the host names a guard that is not a
 * panel's, whose model is scoped to an organisation on its own `org_id`, `current()`, `key()` and `canonical()` answer
 * null, so every entitlement check answers no and every grant is refused.
 *
 * ⚠️ IT NEVER ASKS ANYONE ELSE. Not `Permissions::currentUser()`, not the default guard, not `auth.providers.users`:
 * on a public route each of those answers with the STAFF user from the shared `web` session, and that person would be
 * read as a reader. `ReaderGuardTest` sweeps this file for each of them.
 *
 * ⚠️ NO MEMO. `fault()` is array reads and one model construction, which is what boots the model and registers its
 * scopes; a memo would hide a provider change in a test or a long-lived worker.
 *
 * ⚠️ IT MAPS NO DATABASE ERROR ITSELF. `current()` and `canonical()` may each run one query that binds a reader's
 * identifier, and every caller is a door that runs them inside its own mapping `try`, so a `QueryException` — whose
 * message interpolates its bindings — never leaves core.
 *
 * ⚠️ THE HOST'S CONTRACT: a route that asks runs `ResolveSiteFromRequest` before anything asks this guard, its `auth`
 * middleware included. Otherwise the reader model's org scope has no org and loads nobody: closed, but wrong.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderGuard
{
    use ReadsWrittenKeys;

    public const CONFIG = 'kitsune.readers.guard';

    /** A guard's name, as `config/auth.php` keys one: no dot, so it can never reach into another config path. */
    private const NAME = '/^[A-Za-z0-9_-]{1,64}$/D';

    /** A stored reader key: printable ASCII, no space, at most 255 bytes — what `entitlements.reader_id` holds. */
    private const KEY = '/^[\x21-\x7E]{1,255}$/D';

    public function __construct(private readonly Context $context) {}

    /** Why no reader can be resolved here, or null when the declared guard is usable. */
    public function fault(): ?ReaderGuardFault
    {
        return $this->inspect()[0];
    }

    /** The fault in words, or null when there is none. */
    public function faultSentence(): ?string
    {
        [$fault, , $model] = $this->inspect();
        $declared = config(self::CONFIG);

        return $fault?->sentence(is_string($declared) ? $declared : '', $model);
    }

    /** The guard's name, when it is usable. */
    public function name(): ?string
    {
        [$fault, $name] = $this->inspect();

        return $fault === null ? $name : null;
    }

    /**
     * The guard's model, when it is usable.
     *
     * @return class-string<Model&Authenticatable>|null
     */
    public function model(): ?string
    {
        [$fault, , $model] = $this->inspect();

        return $fault === null ? $model : null;
    }

    /**
     * The key of the reader signed in to this request through the declared guard, for the site in context — or null.
     *
     * May query the host's reader table, through the guard's own user load.
     */
    public function current(): ?string
    {
        $orgId = $this->context->orgId();

        if ($this->context->siteId() === null || $orgId === null) {
            return null;
        }

        [$fault, $name, $model] = $this->inspect();

        if ($fault !== null || $name === null || $model === null) {
            return null;
        }

        $user = Auth::guard($name)->user();

        if (! $user instanceof $model) {
            return null;
        }

        /*
         * ⚠️ A SECOND FENCE, on the org the row says it belongs to: a host provider that strips the org scope, as
         * `OrgAwareUserProvider` strips membership, would otherwise hand back another organisation's reader.
         */
        if (self::writtenKey($user->getAttributes()['org_id'] ?? null) !== $orgId) {
            return null;
        }

        return $this->key($user->getAuthIdentifier());
    }

    /**
     * Whether anyone at all is signed in on the declared guard — the question authority asks before it trusts a caller
     * with nobody on `web` as the system.
     *
     * ⚠️ STRICTER THAN `current()`, ON PURPOSE. It needs no site, so an erasure or an export run with the org alone in
     * context still sees a reader's session riding along (review); it needs no usable model, so a guard core cannot use
     * for readers still counts; and it fences no org, so a reader of another org is still a reader. A panel's guard
     * is left to the panel's own question, since whoever it holds is staff. May query the host's table, through the
     * guard's own user load.
     */
    public function signedIn(): bool
    {
        [$fault, $name] = $this->inspect();

        if ($name === null || in_array($fault, [ReaderGuardFault::NotDeclared, ReaderGuardFault::UnknownGuard, ReaderGuardFault::PanelGuard], true)) {
            return false;
        }

        return Auth::guard($name)->user() !== null;
    }

    /**
     * The caller's identifier as the guard's model keys it, and as `entitlements.reader_id` would store it — or null.
     *
     * Through `Permissions::userKey()`: an integer model refuses `'007'`, `'5abc'`, an overflow and `''`. Then
     * printable ASCII of at most 255 bytes, or nothing.
     */
    public function key(#[\SensitiveParameter] int|string $id): ?string
    {
        $model = $this->model();

        return $model !== null ? self::stored(Permissions::userKey($id, $model)) : null;
    }

    /**
     * The identifier of the reader the caller names, as the HOST spells it, if that reader belongs to the org in
     * context — or null. One query on the host's reader table.
     *
     * ⚠️ THE HOST'S SPELLING, read back from the row: on a host whose table compares case-insensitively, `ABC` finds
     * `abc`, and `abc` is what is stored. And under org B's context, org A's reader does not exist.
     */
    public function canonical(#[\SensitiveParameter] int|string $id): ?string
    {
        $model = $this->model();
        $orgId = $this->context->orgId();

        if ($model === null || $orgId === null || $this->key($id) === null) {
            return null;
        }

        $instance = new $model;
        $found = $instance->newQuery()
            ->whereKey(Permissions::userKey($id, $model))
            ->where($instance->qualifyColumn('org_id'), $orgId)
            ->first();

        return $found instanceof Authenticatable ? $this->key($found->getAuthIdentifier()) : null;
    }

    /** A key as stored, or null when it is none: the printable-ASCII rule, the one encoding of it. */
    public static function stored(mixed $key): ?string
    {
        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $key = (string) $key;

        return preg_match(self::KEY, $key) === 1 ? $key : null;
    }

    /**
     * The fault, the guard's name and its model, asked in the enum's order.
     *
     * ⚠️ `Auth::guard()` IS NEVER CALLED ON AN UNDECLARED OR UNKNOWN NAME: it throws on one. It is asked only once the
     * name is a guard with a provider, to prove its driver can be built. The provider is built from its own
     * configuration, which is what the guard would build.
     *
     * @return array{0: ?ReaderGuardFault, 1: ?string, 2: ?class-string<Model&Authenticatable>}
     */
    private function inspect(): array
    {
        $name = config(self::CONFIG);

        if (! is_string($name) || $name === '') {
            return [ReaderGuardFault::NotDeclared, null, null];
        }

        $guard = preg_match(self::NAME, $name) === 1 ? config("auth.guards.{$name}") : null;
        $provider = is_array($guard) ? ($guard['provider'] ?? null) : null;
        $driver = is_array($guard) ? ($guard['driver'] ?? null) : null;

        if (! is_string($provider) || $provider === '' || ! is_string($driver) || $driver === '') {
            return [ReaderGuardFault::UnknownGuard, $name, null];
        }

        // Only when Filament is installed: core's own suite never registers it (`Permissions::currentUser()`'s reason).
        if (app()->bound('filament')) {
            foreach (Filament::getPanels() as $panel) {
                if ($panel->getAuthGuard() === $name) {
                    return [ReaderGuardFault::PanelGuard, $name, null];
                }
            }
        }

        $model = $this->providerModel($provider);

        if ($model === null) {
            return [ReaderGuardFault::NotEloquent, $name, null];
        }

        /*
         * ⚠️ AND A DRIVER LARAVEL CAN BUILD, or every later `Auth::guard()` throws (review): a guard declared with a
         * driver whose package is not installed read as usable, every check failed with a raw exception rather than
         * answering no, and the console's status called it usable. Asked once the provider is known to build, since
         * building the guard builds it too; building a session or token guard runs no query.
         */
        try {
            app(AuthManager::class)->guard($name);
        } catch (InvalidArgumentException) {
            return [ReaderGuardFault::UnknownGuard, $name, null];
        }

        // Constructing one is what boots the model and registers its scopes: the REGISTERED scopes, never the attribute.
        $scopes = (new $model)->getGlobalScopes();

        if (array_key_exists(OrgMembershipScope::class, $scopes)) {
            return [ReaderGuardFault::PanelShaped, $name, $model];
        }

        if (! array_key_exists(OrgScope::class, $scopes)) {
            return [ReaderGuardFault::NotOrgScoped, $name, $model];
        }

        return [null, $name, $model];
    }

    /** @return class-string<Model&Authenticatable>|null */
    private function providerModel(string $provider): ?string
    {
        try {
            $built = app(AuthManager::class)->createUserProvider($provider);
        } catch (InvalidArgumentException) {
            // A provider driver nobody defined.
            return null;
        }

        if ($built === null || ! method_exists($built, 'getModel')) {
            return null;
        }

        $model = $built->getModel();

        return is_string($model) && is_subclass_of($model, Model::class) && is_subclass_of($model, Authenticatable::class)
            ? $model
            : null;
    }
}
