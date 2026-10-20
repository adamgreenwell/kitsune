<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Carts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Kitsune\Commerce\Schema\RequiredColumns;
use Kitsune\Core\Readers\Contracts\ModuleReaderData;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use RuntimeException;

/** Reader-owned carts across their org, including retained expired carts and a disabled module. @internal */
final class CartReaderData implements ModuleReaderData
{
    use ReadsWrittenKeys;

    private const MIGRATION = '0001_01_01_000203_create_commerce_carts';

    public function export(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): array
    {
        if (! $this->schemaAvailable($connection)) {
            return ['carts' => []];
        }

        $sites = $connection->table('sites')->useWritePdo()->where('org_id', $orgId)->orderBy('id')->pluck('handle', 'id')->all();
        $records = [];

        foreach ($this->carts($connection, $orgId, $reader)->orderBy('site_id')->orderBy('id')->get() as $cart) {
            $this->checkCart($cart, $orgId, $reader, $sites);
            $lines = [];
            $retained = $connection->table('commerce_cart_lines')->useWritePdo()->where('cart_id', $cart->id)
                ->orderBy('id')->limit(CartWriter::MAX_LINES + 1)->get();

            if ($retained->count() > CartWriter::MAX_LINES) {
                throw new RuntimeException('The retained cart line custody is inconsistent.');
            }

            foreach ($retained as $line) {
                $this->checkLine($line, $cart);
                $lines[] = ['listing' => $line->listing_id === null ? null : (string) $line->listing_id, 'quantity' => (int) $line->quantity,
                    'created_at' => self::instant($line->created_at), 'updated_at' => self::instant($line->updated_at)];
            }

            $records[] = ['site' => $sites[$cart->site_id], 'version' => (int) $cart->version,
                'expires_at' => self::instant($cart->expires_at), 'created_at' => self::instant($cart->created_at),
                'updated_at' => self::instant($cart->updated_at), 'lines' => $lines];
        }

        return ['carts' => $records];
    }

    public function erase(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): int
    {
        if (! $this->schemaAvailable($connection)) {
            return 0;
        }

        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('Cart privacy erasure requires its enclosing reader transaction.');
        }

        // Agree with cart mutations and entitlement erasure: org before sites, then carts/lines in stable order. Raw
        // queries are deliberate: privacy spans sites, includes a soft-deleted org, and must not need module boot.
        $connection->table('orgs')->useWritePdo()->where('id', $orgId)->sharedLock()->first()
            ?? throw new RuntimeException('The cart privacy org is unavailable.');
        $sites = $connection->table('sites')->useWritePdo()->where('org_id', $orgId)->orderBy('id')->lockForUpdate()->pluck('handle', 'id')->all();
        $carts = $this->carts($connection, $orgId, $reader)->orderBy('site_id')->orderBy('id')->lockForUpdate()->get();
        $lineCounts = [];

        foreach ($carts as $cart) {
            $this->checkCart($cart, $orgId, $reader, $sites);

            $lines = $connection->table('commerce_cart_lines')->useWritePdo()->where('cart_id', $cart->id)
                ->orderBy('id')->limit(CartWriter::MAX_LINES + 1)->lockForUpdate()->get();

            if ($lines->count() > CartWriter::MAX_LINES) {
                throw new RuntimeException('The retained cart line custody is inconsistent.');
            }

            $lineCounts[$cart->id] = $lines->count();

            foreach ($lines as $line) {
                $this->checkLine($line, $cart);
            }
        }

        foreach ($carts as $cart) {
            // Explicit child deletion keeps erasure complete even if an operator lost the cascade while repairing a
            // schema. Every child was checked before any deletion; the normal FK cascade remains the backstop.
            $deleted = $connection->table('commerce_cart_lines')->where('org_id', $orgId)->where('site_id', $cart->site_id)->where('cart_id', $cart->id)->delete();

            if ($deleted !== $lineCounts[$cart->id] || $connection->table('commerce_carts')->where('org_id', $orgId)
                ->where('site_id', $cart->site_id)->where('id', $cart->id)->where('reader_id', $reader)
                ->where('owner_key', CartOwner::readerKey($reader))->delete() !== 1) {
                throw new RuntimeException('The retained cart erasure was cancelled.');
            }
        }

        return $carts->count();
    }

    private function schemaAvailable(Connection $connection): bool
    {
        $schema = $connection->getSchemaBuilder();
        $carts = $schema->hasTable('commerce_carts');
        $lines = $schema->hasTable('commerce_cart_lines');

        if (! $carts && ! $lines) {
            if ($connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
                throw new RuntimeException('The recorded cart data store is missing.');
            }

            return false;
        }

        if (! $carts || ! $lines
            || ! RequiredColumns::present($connection, 'commerce_carts', ['id', 'org_id', 'site_id', 'reader_id', 'owner_key', 'version', 'expires_at', 'created_at', 'updated_at'])
            || ! RequiredColumns::present($connection, 'commerce_cart_lines', ['id', 'org_id', 'site_id', 'cart_id', 'listing_id', 'quantity', 'created_at', 'updated_at'])) {
            throw new RuntimeException('The cart privacy schema is incomplete.');
        }

        return true;
    }

    private function carts(Connection $connection, int $orgId, #[\SensitiveParameter] string $reader): Builder
    {
        return $connection->table('commerce_carts')->useWritePdo()->where('org_id', $orgId)
            ->where(fn (Builder $owned) => $owned->where('reader_id', $reader)->orWhere('owner_key', CartOwner::readerKey($reader)));
    }

    /** @param array<int|string, mixed> $sites */
    private function checkCart(#[\SensitiveParameter] object $cart, int $orgId, #[\SensitiveParameter] string $reader, array $sites): void
    {
        if (self::writtenKey($cart->id) === null || self::writtenKey($cart->org_id) !== $orgId
            || self::writtenKey($cart->site_id) === null || ! array_key_exists($cart->site_id, $sites)
            || ! is_string($cart->reader_id) || $cart->reader_id !== $reader
            || ! is_string($cart->owner_key) || $cart->owner_key !== CartOwner::readerKey($reader)
            || self::writtenKey($cart->version) === null || (int) $cart->version < 0) {
            throw new RuntimeException('The retained cart ownership is inconsistent.');
        }
    }

    private function checkLine(#[\SensitiveParameter] object $line, #[\SensitiveParameter] object $cart): void
    {
        if (self::writtenKey($line->id) === null || self::writtenKey($line->org_id) !== self::writtenKey($cart->org_id)
            || self::writtenKey($line->site_id) !== self::writtenKey($cart->site_id)
            || self::writtenKey($line->cart_id) !== self::writtenKey($cart->id)
            || ($line->listing_id !== null && (self::writtenKey($line->listing_id) ?? 0) < 1)
            || self::writtenKey($line->quantity) === null || (int) $line->quantity < 1 || (int) $line->quantity > 99) {
            throw new RuntimeException('The retained cart line ownership is inconsistent.');
        }
    }

    private static function instant(#[\SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) !== 1) {
            throw new RuntimeException('The retained cart instant is malformed.');
        }

        $instant = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');

        if (! $instant instanceof CarbonImmutable || $instant->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('The retained cart instant is malformed.');
        }

        return $instant->format('Y-m-d\TH:i:s\Z');
    }
}
