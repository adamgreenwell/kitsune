<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use ReflectionClass;
use SensitiveParameter;
use Throwable;
use WeakMap;

/**
 * Fresh local-password authentication for one credential write, inside its Org-first transaction.
 * No password, time allowance or proof is stored in a session. Password-required calls fail closed on passwordless
 * hosts; other writes still prove current ownership. Non-Eloquent hosts always fail closed.
 * Membership and owner rows are read separately with current primary locks: an EXISTS subquery or a memo can retain
 * an older MySQL/MariaDB outer snapshot. The declared pivot supplies its names; core does not guess a host schema.
 *
 * @internal
 *
 * @phpstan-type Identity array{class:class-string<Model&Authenticatable>,key:int|string,table:string,connection:Connection}
 */
final class FreshCredentialAuthentication
{
    public const MAX_PASSWORD_BYTES = 4096;

    /** @var WeakMap<CredentialAuthentication, CredentialAuthenticationState>|null */
    private static ?WeakMap $proofs = null;

    public function __construct(private readonly Context $context) {}

    public function confirm(#[SensitiveParameter] ?string $password, bool $requirePassword = true): CredentialAuthentication
    {
        try {
            if ($requirePassword && ($password === null || $password === '' || strlen($password) > self::MAX_PASSWORD_BYTES)) {
                throw new CredentialAuthenticationRefused;
            }
            $evidence = $this->current($requirePassword, $password);
            $proof = new CredentialAuthentication;
            self::$proofs ??= new WeakMap;
            self::$proofs[$proof] = $evidence;

            return $proof;
        } catch (Throwable) {
            // Hasher, host accessors and database exceptions may contain input. No previous exception is retained.
            throw new CredentialAuthenticationRefused;
        } finally {
            unset($password);
        }
    }

    /** Called only by the opaque proof; no caller-supplied identity or password can register one here. */
    public function assertCurrent(CredentialAuthentication $proof): void
    {
        try {
            $expected = self::$proofs[$proof] ?? throw new CredentialAuthenticationRefused;
            $current = $this->current($expected->passwordRequired);
            if (! $current->sameAs($expected)) {
                throw new CredentialAuthenticationRefused;
            }
        } catch (Throwable) {
            throw new CredentialAuthenticationRefused;
        }
    }

    private function current(bool $requirePassword, #[SensitiveParameter] ?string $password = null): CredentialAuthenticationState
    {
        $org = $this->context->orgId() ?? throw new CredentialAuthenticationRefused;
        $site = $this->context->siteId();
        $connection = (new Credential)->getConnection();
        $transaction = $this->transaction($connection);
        $identity = $this->identity(Permissions::currentUser());
        if ($identity['connection'] !== $connection
            || Org::query()->useWritePdo()->whereKey($org)->lockForUpdate()->first(['id']) === null) {
            throw new CredentialAuthenticationRefused;
        }

        $prototype = new $identity['class'];
        $attributes = (new ReflectionClass($prototype))->getAttributes(OrgScopedThroughPivot::class);
        if (count($attributes) !== 1 || ! array_key_exists(OrgMembershipScope::class, $prototype->getGlobalScopes())
            || ! Permissions::assignmentsAreAbout($identity['class'])) {
            throw new CredentialAuthenticationRefused;
        }
        $through = $attributes[0]->newInstance();
        // Only the authenticated primary key is read; its independently locked membership replaces this old-snapshot EXISTS.
        $fresh = $prototype->newQuery()->withoutGlobalScope(OrgMembershipScope::class)->useWritePdo()
            ->whereKey($identity['key'])->lockForUpdate()->first();
        if (! $fresh instanceof Authenticatable || $this->identity($fresh) !== $identity
            || $connection->table($through->table)->useWritePdo()->where($through->orgKey, $org)
                ->where($through->foreignKey, $identity['key'])->lockForUpdate()->first() === null) {
            throw new CredentialAuthenticationRefused;
        }
        $roles = $connection->table('role_user')->useWritePdo()->where('user_id', $identity['key'])
            ->lockForUpdate()->pluck('role_id')->all();
        if ($roles === [] || Role::query()->useWritePdo()->where('org_id', $org)->whereIn('id', $roles)
            ->where('is_owner', true)->lockForUpdate()->first(['id']) === null) {
            throw new CredentialAuthenticationRefused;
        }
        $column = $fresh->getAuthPasswordName();
        $hash = $fresh->getRawOriginal()[$column] ?? null;
        if ($column === '' || ($hash !== null && ! is_string($hash))
            || ($requirePassword && (! is_string($hash) || $hash === ''
                || password_get_info($hash)['algoName'] === 'unknown' || $fresh->getAuthPassword() !== $hash))
            || $this->context->orgId() !== $org || $this->context->siteId() !== $site
            || $this->identity(Permissions::currentUser()) !== $identity) {
            throw new CredentialAuthenticationRefused;
        }
        if ($requirePassword && $password !== null && ! Hash::check($password, $hash)) {
            throw new CredentialAuthenticationRefused;
        }

        return new CredentialAuthenticationState($org, $site, $identity['class'], $identity['key'], $identity['table'],
            $connection, $transaction, $connection->getPdo(), $column, self::digest($hash), $requirePassword);
    }

    private static function digest(#[SensitiveParameter] ?string $hash): string
    {
        return hash('sha256', $hash === null ? "\0" : "\1".$hash);
    }

    /** @return Identity */
    private function identity(?Authenticatable $actor): array
    {
        if (! $actor instanceof Model || ! $actor->exists || $actor->getAuthIdentifierName() !== $actor->getKeyName()) {
            throw new CredentialAuthenticationRefused;
        }
        $key = Permissions::userKey($actor->getAuthIdentifier(), $actor::class);
        if ($key === null || (string) $actor->getRawOriginal($actor->getKeyName()) !== (string) $key) {
            throw new CredentialAuthenticationRefused;
        }

        return ['class' => $actor::class, 'key' => $key, 'table' => $actor->getTable(), 'connection' => $actor->getConnection()];
    }

    private function transaction(Connection $connection): DatabaseTransactionRecord
    {
        if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
            throw new CredentialAuthenticationRefused;
        }
        $manager = app('db.transactions');
        $record = $manager->getPendingTransactions()->last(fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection->getName() && $record->level === $connection->transactionLevel());

        return $record ?? throw new CredentialAuthenticationRefused;
    }
}
