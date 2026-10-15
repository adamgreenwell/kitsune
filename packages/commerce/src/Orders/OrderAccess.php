<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kitsune\Commerce\Carts\CartOwner;
use Kitsune\Commerce\Models\Order;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/** Browser ownership or a one-use guest mail proof, never address-based reader attachment. @internal */
final class OrderAccess
{
    public const LINK_MINUTES = 30;

    public const GRANT_MINUTES = 30;

    public const MAX_SLOTS = 20;

    private const PENDING = ReaderSessions::EPHEMERAL.'.order_links';

    private const GRANTS = ReaderSessions::EPHEMERAL.'.order_grants';

    public function __construct(private readonly Context $context, private readonly OrderReaderData $privacy) {}

    /** @phpstan-impure A fresh primary read is required every time, including after account erasure. */
    public function owned(Request $request, mixed $reference): ?Order
    {
        $scope = $this->scope($request, $reference);
        if ($scope === null) {
            return null;
        }
        [$org, $site, $key, $reference] = $scope;

        try {
            $connection = (new Order)->getConnection();
            $lock = $connection->transactionLevel() > 0;
            if ($lock) {
                $this->lock($connection, $org, $site);
            }
            $row = $this->privacy->find($connection, $org, $site, $reference, lock: $lock);
            if ($row === null) {
                return null;
            }
            $this->privacy->project($connection, $org, $row, lock: $lock);
            $claim = app(OrderClaims::class)->forOrder($connection, $row, $lock);
            if ($claim !== null) {
                try {
                    $reader = app(OrderClaims::class)->freshReader($request, $connection);
                } catch (OrderRefused) {
                    return null;
                }

                return app(ReaderGuard::class)->key($reader->getAuthIdentifier()) === $claim->reader_id
                    ? (new Order)->newFromBuilder((array) $row, $connection->getName()) : null;
            }
            $owner = CartOwner::current($request);
            $allowed = $owner !== null && $owner->orgId === $org && $owner->siteId === $site
                && is_string($row->owner_key) && hash_equals($owner->key, $row->owner_key)
                && ($owner->reader === null ? $row->reader_id === null : ($row->reader_id === $owner->reader && $this->liveReader($owner->reader, $org, $lock)));
            if (! $allowed && $row->reader_id === null) {
                $grant = $this->slots($request, self::GRANTS, self::GRANT_MINUTES)[$key] ?? null;
                $allowed = $grant !== null && $this->sameScope($grant, $org, $site, $reference)
                    && is_string($grant['owner_key'] ?? null) && hash_equals($row->owner_key, $grant['owner_key']);
            }
            if (! $allowed) {
                return null;
            }

            // Hydrate only the checked raw row; scope authority did not depend on permissive model casts.
            return (new Order)->newFromBuilder((array) $row, $connection->getName());
        } catch (Throwable) {
            throw new RuntimeException('The order access data is unavailable.');
        }
    }

    /** Exact guest proof remains usable through a legitimate reader login, never after logout or a claim.
     * A matching address is deliberately never queried. Caller supplies a checked, current primary order row.
     */
    public function guestProof(Request $request, object $order): ?string
    {
        $scope = $this->scope($request, $order->reference ?? null);
        if ($scope === null || $order->reader_id !== null || (int) $order->org_id !== $scope[0] || (int) $order->site_id !== $scope[1]) {
            return null;
        }
        $connection = (new Order)->getConnection();
        if (app(OrderClaims::class)->forOrder($connection, $order, $connection->transactionLevel() > 0) !== null) {
            return null;
        }
        CartOwner::restore($request, $request->session());
        $browser = CartOwner::anonymous($request->session(), $scope[0], $scope[1]);
        if ($browser !== null && is_string($order->owner_key) && hash_equals($order->owner_key, $browser->key)) {
            return 'browser';
        }
        $grant = $this->slots($request, self::GRANTS, self::GRANT_MINUTES)[$scope[2]] ?? null;
        if ($grant !== null && $this->sameScope($grant, $scope[0], $scope[1], $scope[3])
            && is_string($grant['owner_key'] ?? null) && is_string($order->owner_key) && hash_equals($order->owner_key, $grant['owner_key'])) {
            return 'email';
        }

        return null;
    }

    /** GET only stashes a hash under a fresh session ID. It never consumes or even queries a link. */
    public function capture(Request $request, mixed $reference, #[\SensitiveParameter] mixed $secret): bool
    {
        $scope = $this->scope($request, $reference);
        if ($scope === null) {
            return false;
        }
        [$org, $site, $key, $reference] = $scope;
        $pending = $this->slots($request, self::PENDING, self::LINK_MINUTES);
        unset($pending[$key]);
        if (! is_string($secret) || ! self::hex($secret, 64)) {
            $request->session()->put(self::PENDING, $pending);

            return false;
        }
        $request->session()->migrate(true);
        $pending[$key] = ['org' => $org, 'site' => $site, 'reference' => $reference,
            'hash' => hash('sha256', $secret), 'expires' => self::now()->addMinutes(self::LINK_MINUTES)->timestamp];
        $request->session()->put(self::PENDING, array_slice($pending, -self::MAX_SLOTS, null, true));

        return true;
    }

    /** A clean confirmation page may test that a hash is waiting without consuming it or revealing it. */
    public function pending(Request $request, mixed $reference): bool
    {
        $scope = $this->scope($request, $reference);
        if ($scope === null) {
            return false;
        }
        [$org, $site, $key, $reference] = $scope;
        $pending = $this->slots($request, self::PENDING, self::LINK_MINUTES)[$key] ?? null;

        return $pending !== null && $this->sameScope($pending, $org, $site, $reference)
            && self::hex($pending['hash'] ?? null, 64);
    }

    /** Caller enforces the actual CSRF POST. Claim and subsequent grant are tied to the committing transaction. */
    public function consume(Request $request, mixed $reference): bool
    {
        $scope = $this->scope($request, $reference);
        if ($scope === null) {
            return false;
        }
        [$org, $site, $key, $reference] = $scope;
        $pending = $this->slots($request, self::PENDING, self::LINK_MINUTES)[$key] ?? null;
        if ($pending === null || ! $this->sameScope($pending, $org, $site, $reference) || ! self::hex($pending['hash'] ?? null, 64)) {
            return false;
        }
        try {
            $connection = (new Order)->getConnection();

            return TransactionRecovery::run($connection, function () use ($request, $reference, $org, $site, $key, $pending, $connection): bool {
                $this->lock($connection, $org, $site);
                $row = $this->privacy->find($connection, $org, $site, $reference, lock: true);
                if ($row === null || $row->reader_id !== null || app(OrderClaims::class)->forOrder($connection, $row, true) !== null) {
                    return false;
                }
                $this->privacy->project($connection, $org, $row, lock: true);
                $now = self::storedNow();
                $token = $connection->table('commerce_order_access_tokens')->useWritePdo()
                    ->where('org_id', $org)->where('site_id', $site)->where('order_id', $row->id)
                    ->where('token_hash', $pending['hash'])->whereNull('consumed_at')->where('expires_at', '>', $now)
                    ->lockForUpdate()->first();
                if ($token === null || ! is_string($token->token_hash) || ! hash_equals($pending['hash'], $token->token_hash)) {
                    return false;
                }
                if ($connection->table('commerce_order_access_tokens')->where('org_id', $org)->where('site_id', $site)
                    ->where('order_id', $row->id)->where('id', $token->id)->where('token_hash', $pending['hash'])
                    ->whereNull('consumed_at')->where('expires_at', '>', $now)->update(['consumed_at' => $now]) !== 1) {
                    return false;
                }
                $connection->afterCommit(function () use ($request, $reference, $org, $site, $key, $row): void {
                    $pending = $this->slots($request, self::PENDING, self::LINK_MINUTES);
                    unset($pending[$key]);
                    $request->session()->put(self::PENDING, $pending);
                    $grants = $this->slots($request, self::GRANTS, self::GRANT_MINUTES);
                    unset($grants[$key]);
                    $grants[$key] = ['org' => $org, 'site' => $site, 'reference' => $reference, 'owner_key' => $row->owner_key,
                        'expires' => self::now()->addMinutes(self::GRANT_MINUTES)->timestamp];
                    $request->session()->put(self::GRANTS, array_slice($grants, -self::MAX_SLOTS, null, true));
                });

                return true;
            });
        } catch (Throwable) {
            throw new RuntimeException('The order access link is unavailable.');
        }
    }

    /** Uniform result: an unknown/reader-owned/throttled order receives no mail and no different answer. */
    public function requestLink(Request $request, mixed $reference): void
    {
        try {
            $this->scheduleLink($request, $reference);
        } catch (RuntimeException|InvalidArgumentException) {
            Log::warning('An order access email could not be scheduled; no address, order, link or error is logged.');
        }
    }

    private function scheduleLink(Request $request, mixed $reference): void
    {
        $scope = $this->scope($request, $reference);
        if ($scope === null) {
            return;
        }
        [$org, $site, , $reference] = $scope;
        $connection = (new Order)->getConnection();
        $receipt = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->first();
        if ($receipt === null) {
            return;
        }
        $capturedReceipt = [$receipt->id, $receipt->version];
        $row = $this->privacy->find($connection, $org, $site, $reference);
        if ($row === null || $row->reader_id !== null || app(OrderClaims::class)->forOrder($connection, $row) !== null) {
            return;
        }
        $this->privacy->project($connection, $org, $row);
        $address = $row->contact_email;
        if (! ReaderThrottle::mayMail($org, $address)) {
            return;
        }
        $siteRow = Site::query()->useWritePdo()->where('org_id', $org)->whereKey($site)->first();
        $origin = $siteRow !== null ? ReaderLinks::origin($siteRow) : null;
        if ($siteRow === null || $origin === null) {
            return;
        }
        $capturedSite = [$siteRow->name, $siteRow->base_url, $siteRow->canonical_host, $siteRow->path_prefix];
        $captured = [$row->id, $row->owner_key, $row->snapshot_hash, $address];
        $path = ($siteRow->path_prefix ?? '').'/cart/orders/'.$reference.'/access';
        $name = $siteRow->name;
        $connection->afterCommit(function () use ($connection, $org, $site, $reference, $address, $origin, $path, $name, $capturedSite, $captured, $capturedReceipt): void {
            try {
                ReaderMail::defer($address, function () use ($connection, $org, $site, $reference, $origin, $path, $name, $capturedSite, $captured, $capturedReceipt): ?ReaderMail {
                    if ($this->context->orgId() !== $org || $this->context->siteId() !== $site) {
                        return null;
                    }

                    return TransactionRecovery::run($connection, function () use ($connection, $org, $site, $reference, $origin, $path, $name, $capturedSite, $captured, $capturedReceipt): ?ReaderMail {
                        $this->lock($connection, $org, $site);
                        $receipt = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')
                            ->where('is_enabled', true)->lockForUpdate()->first();
                        if ($receipt === null || [$receipt->id, $receipt->version] !== $capturedReceipt) {
                            return null;
                        }
                        $siteRow = $connection->table('sites')->useWritePdo()->where('org_id', $org)->where('id', $site)->first();
                        $row = $this->privacy->find($connection, $org, $site, $reference, lock: true);
                        if ($siteRow === null || [$siteRow->name, $siteRow->base_url, $siteRow->canonical_host, $siteRow->path_prefix] !== $capturedSite
                            || $row === null || $row->reader_id !== null || app(OrderClaims::class)->forOrder($connection, $row, true) !== null || [$row->id, $row->owner_key, $row->snapshot_hash, $row->contact_email] !== $captured) {
                            return null;
                        }
                        $this->privacy->project($connection, $org, $row, lock: true);
                        // A resend replaces the previous link, bounding storage even after repeated expired/consumed links.
                        $tokens = $connection->table('commerce_order_access_tokens')->where('org_id', $org)->where('site_id', $site)->where('order_id', $row->id);
                        $expected = (clone $tokens)->useWritePdo()->count();
                        if ($tokens->delete() !== $expected) {
                            throw new RuntimeException('The previous order access links could not be replaced.');
                        }
                        $secret = bin2hex(random_bytes(32));
                        $hash = hash('sha256', $secret);
                        $created = self::storedNow();
                        $expires = self::now()->addMinutes(self::LINK_MINUTES)->format('Y-m-d H:i:s');
                        $connection->table('commerce_order_access_tokens')->insert(['org_id' => $org, 'site_id' => $site,
                            'order_id' => $row->id, 'token_hash' => $hash, 'created_at' => $created,
                            'expires_at' => $expires, 'consumed_at' => null]);
                        $kept = $connection->table('commerce_order_access_tokens')->useWritePdo()->where('order_id', $row->id)->get();
                        $fresh = $this->privacy->find($connection, $org, $site, $reference, lock: true);
                        if ($kept->count() !== 1 || $kept[0]->token_hash !== $hash || $kept[0]->created_at !== $created
                            || $kept[0]->expires_at !== $expires || $kept[0]->consumed_at !== null || $fresh === null
                            || [$fresh->id, $fresh->owner_key, $fresh->snapshot_hash, $fresh->contact_email] !== $captured) {
                            throw new RuntimeException('The order access link could not be retained.');
                        }
                        $this->privacy->project($connection, $org, $fresh, lock: true);

                        return new ReaderMail('View your '.$name.' order', implode("\n", [
                            'Someone requested access to your order on '.$name.'.', '',
                            'Open this link, then confirm access. It works once for '.self::LINK_MINUTES.' minutes:', '',
                            $origin.$path.'?token='.$secret, '', 'If you did not ask, you can ignore this email.', '',
                        ]), $name);
                    });
                });
            } catch (RuntimeException|InvalidArgumentException) {
                // This callback may run after the preparation transaction has committed.
                Log::warning('An order access email could not be scheduled; no address, order, link or error is logged.');
            }
        });
    }

    /** Forget only this exact reference's proofs after committed erasure. Reader logout clears the whole namespace. */
    public function forget(Request $request, mixed $reference): void
    {
        $scope = $this->scope($request, $reference);
        if ($scope === null) {
            return;
        }
        $key = $scope[2];
        foreach ([self::PENDING, self::GRANTS] as $slot) {
            $held = $request->session()->get($slot);
            if (is_array($held)) {
                unset($held[$key]);
                $request->session()->put($slot, $held);
            }
        }
    }

    /** @return array{int,int,string,string}|null */
    private function scope(Request $request, mixed $reference): ?array
    {
        $org = $this->context->orgId();
        $site = $this->context->siteId();
        if ($org === null || $site === null || ! $request->hasSession() || ! is_string($reference) || ! self::hex($reference, 32)) {
            return null;
        }

        return [$org, $site, hash('sha256', $org."\0".$site."\0".$reference), $reference];
    }

    /** @return array<string,array<string,mixed>> */
    private function slots(Request $request, string $key, int $minutes): array
    {
        $held = $request->session()->get($key);
        $valid = [];
        if (is_array($held)) {
            foreach (array_slice($held, -self::MAX_SLOTS, null, true) as $slot => $value) {
                if (is_string($slot) && self::hex($slot, 64) && is_array($value)
                    && is_int($value['expires'] ?? null) && $value['expires'] > self::now()->timestamp
                    && $value['expires'] <= self::now()->addMinutes($minutes)->timestamp) {
                    $valid[$slot] = $value;
                }
            }
        }
        $request->session()->put($key, $valid);

        return $valid;
    }

    /** @param array<string,mixed> $held */
    private function sameScope(array $held, int $org, int $site, string $reference): bool
    {
        return ($held['org'] ?? null) === $org && ($held['site'] ?? null) === $site && ($held['reference'] ?? null) === $reference;
    }

    private function lock(Connection $connection, int $org, int $site): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
        }
        $connection->table('orgs')->useWritePdo()->where('id', $org)->lockForUpdate()->first()
            ?? throw new RuntimeException('The order access org is unavailable.');
        $connection->table('sites')->useWritePdo()->where('org_id', $org)->where('id', $site)->lockForUpdate()->first()
            ?? throw new RuntimeException('The order access site is unavailable.');
    }

    /** A cached guard object is not evidence that the host reader still exists on the primary. */
    private function liveReader(#[\SensitiveParameter] string $reader, int $org, bool $lock): bool
    {
        $guard = app(ReaderGuard::class);
        $model = $guard->model();
        if ($model === null || $guard->current() !== $reader) {
            return false;
        }
        $query = $model::query()->useWritePdo()->whereKey(Permissions::userKey($reader, $model))->where('org_id', $org);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->first();

        return $fresh instanceof Authenticatable && $guard->key($fresh->getAuthIdentifier()) === $reader;
    }

    private static function hex(#[\SensitiveParameter] mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private static function storedNow(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }
}
