<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Kitsune\Commerce\Models\Cart;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use PDOException;
use RuntimeException;
use Throwable;

/** Bounded operator cleanup, with expiry rechecked under the same org/site locks as cart mutations. @internal */
final class CartPruner
{
    use ReadsWrittenKeys;

    public function prune(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Cart cleanup limit must be an integer from 1 through 1000.');
        }

        try {
            $connection = (new Cart)->getConnection();
            $this->enabled($connection);
            $now = UtcInstant::stored(CarbonImmutable::now('UTC'));
            // This installation-wide maintenance read returns only bounded ownership keys. It runs outside each
            // write transaction, so SQLite carries no candidate snapshot into the write-reservation step below.
            $candidates = $connection->table('commerce_carts')->useWritePdo()->where('expires_at', '<=', $now)
                ->orderBy('org_id')->orderBy('site_id')->orderBy('id')->limit($limit)->get(['id', 'org_id', 'site_id']);
            $removed = 0;

            foreach ($candidates as $candidate) {
                $id = self::key($candidate->id);
                $org = self::key($candidate->org_id);
                $site = self::key($candidate->site_id);
                $removed += TransactionRecovery::run($connection, function () use ($connection, $id, $org, $site, $now): int {
                    if ($connection->getDriverName() === 'sqlite') {
                        $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
                    }

                    $connection->table('orgs')->useWritePdo()->where('id', $org)->lockForUpdate()->first()
                        ?? throw new RuntimeException('The cart cleanup org is missing.');
                    $connection->table('sites')->useWritePdo()->where('id', $site)->where('org_id', $org)->lockForUpdate()->first()
                        ?? throw new RuntimeException('The cart cleanup site is inconsistent.');
                    $this->enabled($connection);
                    $cart = $connection->table('commerce_carts')->useWritePdo()->where('id', $id)->lockForUpdate()->first();

                    if ($cart === null) {
                        return 0;
                    }

                    $this->checkCart($cart, $org, $site);

                    if ($cart->expires_at > $now) {
                        return 0;
                    }

                    $lines = $connection->table('commerce_cart_lines')->useWritePdo()->where('cart_id', $id)
                        ->orderBy('id')->limit(CartWriter::MAX_LINES + 1)->lockForUpdate()->get();

                    if ($lines->count() > CartWriter::MAX_LINES) {
                        throw new RuntimeException('The cart cleanup line custody is inconsistent.');
                    }

                    foreach ($lines as $line) {
                        if (self::key($line->org_id) !== $org || self::key($line->site_id) !== $site
                            || self::key($line->cart_id) !== $id || self::writtenKey($line->quantity) === null
                            || (int) $line->quantity < 1 || (int) $line->quantity > 99
                            || ($line->listing_id !== null && (self::writtenKey($line->listing_id) ?? 0) < 1)) {
                            throw new RuntimeException('The cart cleanup line custody is inconsistent.');
                        }
                    }

                    $deleted = $connection->table('commerce_cart_lines')->where('org_id', $org)->where('site_id', $site)
                        ->where('cart_id', $id)->delete();

                    if ($deleted !== $lines->count() || $connection->table('commerce_carts')->where('id', $id)
                        ->where('org_id', $org)->where('site_id', $site)->where('expires_at', '<=', $now)->delete() !== 1) {
                        throw new RuntimeException('The cart cleanup was cancelled.');
                    }

                    return 1;
                });
            }

            return $removed;
        } catch (CartPruneUnavailable $safe) {
            throw $safe;
        } catch (PDOException $failure) {
            throw new CartPruneUnavailable(ReaderAccounts::sqlstate($failure));
        } catch (Throwable) {
            throw new CartPruneUnavailable;
        }
    }

    private function enabled(Connection $connection): void
    {
        $version = ModuleDiscovery::version('kitsune/commerce');

        if ($version === null || ! $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')
            ->where('is_enabled', true)->where('version', $version)->exists()) {
            throw new CartPruneUnavailable;
        }
    }

    private static function key(mixed $value): int
    {
        $key = self::writtenKey($value);

        return $key !== null && $key > 0 ? $key : throw new RuntimeException('The cart cleanup identity is malformed.');
    }

    private function checkCart(#[\SensitiveParameter] object $cart, int $org, int $site): void
    {
        $reader = $cart->reader_id;

        if (self::key($cart->org_id) !== $org || self::key($cart->site_id) !== $site
            || ! is_string($cart->owner_key) || preg_match('/\A[a-f0-9]{64}\z/D', $cart->owner_key) !== 1
            || ($reader !== null && (! is_string($reader) || ReaderGuard::stored($reader) !== $reader || CartOwner::readerKey($reader) !== $cart->owner_key))) {
            throw new RuntimeException('The cart cleanup ownership is inconsistent.');
        }

        $expiry = $cart->expires_at;
        $instant = is_string($expiry) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $expiry) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiry, new DateTimeZone('UTC')) : false;

        if ($instant === false || $instant->format('Y-m-d H:i:s') !== $expiry) {
            throw new RuntimeException('The cart cleanup expiry is malformed.');
        }
    }
}
