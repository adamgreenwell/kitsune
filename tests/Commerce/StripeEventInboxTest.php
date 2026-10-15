<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Console\StripePruneCommand;
use Kitsune\Commerce\Console\StripeReplayCommand;
use Kitsune\Commerce\Models\CheckoutRoutingReceipt;
use Kitsune\Commerce\Models\StripeEvent;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture as InboxFx;

beforeEach(function (): void {
    Fx::boot();
    InboxFx::enable();
    $this->org = Org::create(['slug' => 'stripe-inbox', 'name' => 'Inbox', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'ingress', 'slug' => 'inbox-ingress', 'name' => 'Ingress', 'base_url' => 'https://inbox.example.test']);
    $this->selling = Site::create(['handle' => 'selling', 'slug' => 'inbox-selling', 'name' => 'Selling', 'base_url' => 'https://selling.example.test']);
    $this->foreign = Org::create(['slug' => 'stripe-foreign', 'name' => 'Foreign', 'currency' => 'USD']);
    app(Context::class)->setOrg($this->foreign);
    $this->foreignSite = Site::create(['handle' => 'foreign', 'slug' => 'inbox-foreign', 'name' => 'Foreign', 'base_url' => 'https://foreign.example.test']);
    app(Context::class)->setSite($this->site);
    $this->attempt = InboxFx::attempt($this->selling);
    $this->event = InboxFx::event($this->attempt);
    $this->raw = '{"private_customer_email":"private@example.test","event":"'.$this->event['id'].'"}';
    $this->inbox = app(StripeEventInbox::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

it('records exact verified bytes once and routes only within the ingress org to the recorded selling site', function (): void {
    $receipt = $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $again = $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $stored = DB::table(StripeEventInbox::TABLE)->sole();
    expect($receipt['duplicate'])->toBeFalse()->and($again)->toBe([...$receipt, 'duplicate' => true])
        ->and((int) $stored->replay_count)->toBe(0)->and($stored->raw_payload)->toBe($this->raw)->and($stored->payload_hash)->toBe(hash('sha256', $this->raw))
        ->and((int) $stored->site_id)->toBe($this->selling->getKey())->and((int) $stored->attempt_id)->toBe((int) $this->attempt->id)
        ->and($stored->account_id)->toBe($this->attempt->account_id)->and(app(Context::class)->siteId())->toBe($this->site->getKey())
        ->and(CheckoutAttempts::instant($stored->raw_expires_at)->diffInSeconds(CheckoutAttempts::instant($stored->received_at), true))->toBe(604800.0);
});

it('refuses a conflicting duplicate without replacing bytes timestamps state or identity', function (): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $before = (array) DB::table(StripeEventInbox::TABLE)->sole();
    expect(fn () => $this->inbox->capture($this->org->getKey(), $this->event, $this->raw.' ', CarbonImmutable::now('UTC')))->toThrow(CheckoutRefused::class);
    expect((array) DB::table(StripeEventInbox::TABLE)->sole())->toBe($before);
});

it('rejects unknown foreign and ambiguous routing before accepting any raw customer data', function (string $case): void {
    $event = $this->event;
    if ($case === 'unknown') {
        $event['attemptReference'] = str_repeat('0', 32);
    } elseif ($case === 'foreign') {
        $foreign = InboxFx::attempt($this->foreignSite);
        $event = InboxFx::event($foreign);
    } elseif ($case === 'ambiguous') {
        $route = (array) DB::table(StripeEventInbox::ROUTING)->sole();
        unset($route['id']);
        $other = InboxFx::attempt($this->site, route: false);
        $route['attempt_id'] = $other->id;
        DB::table(StripeEventInbox::ROUTING)->insert($route);
    } else {
        DB::table(StripeEventInbox::ROUTING)->update([$case => match ($case) {
            'account_id' => 'acct_other', 'site_id' => $this->site->getKey(), 'attempt_reference' => str_repeat('1', 32), default => 'live',
        }]);
    }
    expect(fn () => $this->inbox->capture($this->org->getKey(), $event, $this->raw, CarbonImmutable::now('UTC')))->toThrow(CheckoutRefused::class);
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(0);
})->with(['unknown', 'foreign', 'ambiguous', 'account_id', 'site_id', 'mode', 'attempt_reference']);

it('declares enforced org scope and refuses every ORM mutation on both private custody models', function (string $class): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    expect((new ReflectionClass($class))->getAttributes(OrgScoped::class))->toHaveCount(1)
        ->and(class_uses_recursive($class))->toContain(EnforcesScope::class);
    $row = $class::query()->sole();
    expect(fn () => $row->save())->toThrow(RuntimeException::class)->and(fn () => $row->saveQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class)->and(fn () => $row->deleteQuietly())->toThrow(RuntimeException::class)
        ->and(fn () => $row->replicate()->save())->toThrow(RuntimeException::class)
        ->and(fn () => $class::query()->update(['mode' => 'live']))->toThrow(RuntimeException::class)
        ->and(fn () => $class::query()->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $class::query()->insert([]))->toThrow(RuntimeException::class);
    app(Context::class)->setSite($this->foreignSite);
    expect($class::query()->count())->toBe(0);
    app(Context::class)->forget();
    expect($class::query()->count())->toBe(0);
})->with([StripeEvent::class, CheckoutRoutingReceipt::class]);

it('exports only safe receipt status without raw bytes provider IDs or leases', function (): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    $projection = $this->inbox->projection(DB::connection(), $this->org->getKey(), $order);
    expect($projection)->toHaveCount(1)->and(array_keys($projection[0]))->toBe(['state', 'event_type', 'category', 'received_at', 'raw_expires_at', 'processed_at']);
    $serialized = json_encode(StripeEvent::query()->sole()->toArray(), JSON_THROW_ON_ERROR);
    foreach (['private@example.test', $this->event['sessionId'], $this->event['id'], $this->event['attemptReference']] as $private) {
        expect($serialized)->not->toContain($private);
    }
    app(Context::class)->setSite($this->foreignSite);
    expect(fn () => $this->inbox->projection(DB::connection(), $this->foreign->getKey(), $order))->toThrow(CheckoutRefused::class);
});

it('physically prunes expired bytes without reading them or altering the immutable replay envelope', function (): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $before = (array) DB::table(StripeEventInbox::TABLE)->sole();
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(7));
    DB::connection()->enableQueryLog();
    expect($this->inbox->prune(1))->toBe(1)->and($this->inbox->prune(1))->toBe(0);
    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();
    foreach ($queries as $query) {
        if (str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], StripeEventInbox::TABLE)) {
            expect(explode(' from ', $query['query'])[0])->not->toContain('"raw_payload"')->not->toContain('select *');
        }
    }
    expect((array) DB::table(StripeEventInbox::TABLE)->sole())->toBe([...$before, 'raw_payload' => null]);
    $again = $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    expect($again['duplicate'])->toBeTrue()->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull()
        ->and(DB::table(StripeEventInbox::TABLE)->value('raw_expires_at'))->toBe($before['raw_expires_at']);
});

it('retains only a dedup tombstone when a terminal test order is erased and cannot restore its graph on redelivery', function (): void {
    $receipt = $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    DB::table(CheckoutAttempts::TABLE)->where('id', $this->attempt->id)->update(['state' => 'completed', 'active_order_id' => null, 'checkout_url' => null]);
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    app(Context::class)->setSite($this->selling);
    DB::transaction(fn () => app(OrderReaderData::class)->eraseRecord(DB::connection(), $order));
    $tombstone = DB::table(StripeEventInbox::TABLE)->sole();
    expect($tombstone->state)->toBe('erased')->and(DB::table(StripeEventInbox::ROUTING)->count())->toBe(0)
        ->and(DB::table('commerce_orders')->count())->toBe(0)->and(DB::table(CheckoutAttempts::TABLE)->count())->toBe(0);
    foreach (['raw_payload', 'site_id', 'order_id', 'attempt_id', 'order_reference', 'attempt_reference', 'session_id', 'lease_token', 'lease_until'] as $column) {
        expect($tombstone->{$column})->toBeNull();
    }
    app(Context::class)->setSite($this->site);
    expect($this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC')))->toBe(['id' => $receipt['id'], 'duplicate' => true, 'state' => 'erased'])
        ->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull();
});

it('keeps pending attempts and restrictive routing from being silently erased', function (): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    app(Context::class)->setSite($this->selling);
    expect(fn () => DB::transaction(fn () => app(OrderReaderData::class)->eraseRecord(DB::connection(), $order)))->toThrow(CheckoutRefused::class)
        ->and(fn () => DB::transaction(fn () => DB::table(CheckoutAttempts::TABLE)->where('id', $this->attempt->id)->delete()))->toThrow(QueryException::class);
    expect(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBe($this->raw);
});

it('fails closed for missing recorded or partially damaged inbox schema even when it holds no rows', function (string $table): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-only transactional DDL corruption probe.');
    }
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    Schema::table($table, fn ($blueprint) => $blueprint->dropColumn($table === StripeEventInbox::TABLE ? 'category' : 'created_at'));
    expect(fn () => $this->inbox->projection(DB::connection(), $this->org->getKey(), $order))->toThrow(CheckoutRefused::class);
})->with([StripeEventInbox::TABLE, StripeEventInbox::ROUTING]);

it('retrieves one original Session hint after raw expiry and refuses ambiguous historical recovery', function (): void {
    $unknown = InboxFx::attempt($this->selling, state: 'creating');
    $event = InboxFx::event($unknown);
    $this->inbox->capture($this->org->getKey(), $event, '{"private":"first"}', CarbonImmutable::now('UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
    $this->inbox->prune();
    $order = DB::table('commerce_orders')->where('id', $unknown->order_id)->sole();
    expect(DB::transaction(fn () => $this->inbox->sessionForAttempt(DB::connection(), $this->org->getKey(), $order, $unknown)))->toBe($event['sessionId']);
    $otherEvent = [...$event, 'id' => 'evt_different', 'sessionId' => 'cs_test_otherOriginal'];
    $this->inbox->capture($this->org->getKey(), $otherEvent, '{"private":"second"}', CarbonImmutable::now('UTC'));
    expect(fn () => DB::transaction(fn () => $this->inbox->sessionForAttempt(DB::connection(), $this->org->getKey(), $order, $unknown)))->toThrow(CheckoutRefused::class);
});

it('detects misfiled or damaged receipt envelopes from the parent side before export or erasure', function (string $column, mixed $value): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    DB::table(StripeEventInbox::TABLE)->update([$column => $value === 'foreign-site' ? $this->foreignSite->getKey() : ($value === 'foreign-org' ? $this->foreign->getKey() : $value)]);
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    expect(fn () => $this->inbox->projection(DB::connection(), $this->org->getKey(), $order))->toThrow(CheckoutRefused::class);
})->with([
    ['org_id', 'foreign-org'], ['site_id', 'foreign-site'], ['account_id', 'acct_other'],
    ['attempt_reference', '0123456789abcdef0123456789abcdef'], ['session_id', 'cs_test_other'],
    ['raw_expires_at', '2030-01-01 00:00:00'], ['state', 'invented'], ['category', 'a raw private error'],
    ['lease_token', str_repeat('a', 64)],
]);

it('physically removes expired test payloads after the org switches to live without performing payment I/O', function (): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    DB::table('org_credential_modes')->insert(['org_id' => $this->org->getKey(), 'mode' => 'live', 'changed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')]);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
    expect($this->inbox->prune())->toBe(1)->and(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBeNull();
});

it('hides misfiled raw parent graphs through ordinary org model reads and the explicit scope hatch', function (string $class, string $column): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $table = (new $class)->getTable();
    $foreign = InboxFx::attempt($this->foreignSite);
    DB::table($table)->where('org_id', $this->org->getKey())->update([$column => match ($column) {
        'site_id' => $this->foreignSite->getKey(), 'order_id' => $foreign->order_id, 'attempt_id' => $foreign->id,
    }]);
    expect($class::query()->count())->toBe(0)
        ->and($class::withoutScopeBecause('Prove the independent Stripe parent coherence fence.', fn ($query) => $query->where('org_id', $this->org->getKey())->count()))->toBe(0);
})->with([
    [StripeEvent::class, 'site_id'], [StripeEvent::class, 'order_id'], [StripeEvent::class, 'attempt_id'],
    [CheckoutRoutingReceipt::class, 'site_id'], [CheckoutRoutingReceipt::class, 'order_id'], [CheckoutRoutingReceipt::class, 'attempt_id'],
]);

it('compiles distinct scope-leading indexes and restrictive keys with the largest supported MySQL prefix', function (bool $prefixIndexes): void {
    $prefix = str_repeat('p', 37).'_';
    $connection = new MySqlConnection(new PDO('sqlite::memory:'), 'inbox_names', $prefix, ['driver' => 'mysql', 'prefix_indexes' => $prefixIndexes]);
    $oldSchema = Schema::getFacadeRoot();
    $oldDatabase = DB::getFacadeRoot();
    try {
        Schema::swap($connection->getSchemaBuilder());
        DB::swap($connection);
        $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000207_create_commerce_stripe_inbox.php';
        $queries = $connection->pretend(fn () => $migration->up());
    } finally {
        Schema::swap($oldSchema);
        DB::swap($oldDatabase);
    }
    $sql = implode("\n", array_column($queries, 'query'));
    preg_match_all('/(?:constraint|unique|index) `([^`]+)`/i', $sql, $matches);
    expect($matches[1])->toHaveCount(17)->and(array_unique($matches[1]))->toHaveCount(17);
    foreach ($matches[1] as $name) {
        expect(strlen($name))->toBeLessThanOrEqual(64)->and($name)->toStartWith($prefix);
    }
    foreach (['commerce_checkout_routing', 'commerce_stripe_events'] as $table) {
        $indexes = DB::connection()->getSchemaBuilder()->getIndexes($table);
        foreach ($indexes as $index) {
            if (count($index['columns']) > 1) {
                expect($index['columns'][0])->toBe('org_id');
            }
        }
    }
})->with([false, true]);

it('backfills existing immutable checkout hints without changing their original rows or payload identity', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
    $connection->statement('PRAGMA foreign_keys = ON');
    $schema = $connection->getSchemaBuilder();
    $schema->create('orgs', fn ($table) => $table->id());
    $schema->create('sites', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('org_id');
    });
    $schema->create('commerce_orders', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('org_id');
        $table->unsignedBigInteger('site_id');
        $table->string('reference', 32);
    });
    $schema->create('commerce_checkout_attempts', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('org_id');
        $table->unsignedBigInteger('site_id');
        $table->unsignedBigInteger('order_id');
        $table->string('reference', 32);
        $table->string('account_id', 100);
        $table->string('mode', 4);
        $table->dateTime('created_at');
        $table->text('request_payload');
    });
    $connection->table('orgs')->insert(['id' => 1]);
    $connection->table('sites')->insert(['id' => 2, 'org_id' => 1]);
    $connection->table('commerce_orders')->insert(['id' => 3, 'org_id' => 1, 'site_id' => 2, 'reference' => str_repeat('a', 32)]);
    $before = ['id' => 4, 'org_id' => 1, 'site_id' => 2, 'order_id' => 3, 'reference' => str_repeat('b', 32), 'account_id' => 'acct_old', 'mode' => 'test', 'created_at' => '2026-10-14 12:00:00', 'request_payload' => '{"immutable":"old request"}'];
    $connection->table('commerce_checkout_attempts')->insert($before);
    $oldSchema = Schema::getFacadeRoot();
    $oldDatabase = DB::getFacadeRoot();
    try {
        Schema::swap($schema);
        DB::swap($connection);
        $migration = require __DIR__.'/../../packages/commerce/database/migrations/0001_01_01_000207_create_commerce_stripe_inbox.php';
        $migration->up();
    } finally {
        Schema::swap($oldSchema);
        DB::swap($oldDatabase);
    }
    $route = $connection->table(StripeEventInbox::ROUTING)->sole();
    expect((array) $connection->table(CheckoutAttempts::TABLE)->sole())->toBe($before)
        ->and((int) $route->attempt_id)->toBe(4)->and((int) $route->org_id)->toBe(1)->and((int) $route->site_id)->toBe(2)
        ->and($route->order_reference)->toBe(str_repeat('a', 32))->and($route->attempt_reference)->toBe(str_repeat('b', 32));
});

it('runs bounded payload cleanup for one explicit org and restores the prior site context', function (): void {
    Artisan::registerCommand(app(StripePruneCommand::class));
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $foreignAttempt = InboxFx::attempt($this->foreignSite);
    app(Context::class)->setSite($this->foreignSite);
    $this->inbox->capture($this->foreign->getKey(), InboxFx::event($foreignAttempt), '{"private":"foreign"}', CarbonImmutable::now('UTC'));
    app(Context::class)->setSite($this->site);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
    $this->artisan('kitsune:stripe-prune', ['--org' => $this->org->slug, '--limit' => '1'])->expectsOutput('Cleared 1 expired Stripe payloads; verified receipts retained.')->assertSuccessful();
    expect(app(Context::class)->siteId())->toBe($this->site->getKey())
        ->and(DB::table(StripeEventInbox::TABLE)->where('org_id', $this->org->getKey())->value('raw_payload'))->toBeNull()
        ->and(DB::table(StripeEventInbox::TABLE)->where('org_id', $this->foreign->getKey())->value('raw_payload'))->toBe('{"private":"foreign"}');
});

it('refuses missing org malformed bounds or event IDs without leaking private input or changing context', function (string $command, array $options): void {
    Artisan::registerCommand(app(StripePruneCommand::class));
    Artisan::registerCommand(app(StripeReplayCommand::class));
    $this->artisan($command, $options)->assertFailed();
    expect(app(Context::class)->siteId())->toBe($this->site->getKey())->and(DB::table(StripeEventInbox::TABLE)->count())->toBe(0);
})->with([
    ['kitsune:stripe-prune', []], ['kitsune:stripe-prune', ['--org' => 'stripe-inbox', '--limit' => '0']],
    ['kitsune:stripe-prune', ['--org' => 'stripe-inbox', '--limit' => '1001']], ['kitsune:stripe-prune', ['--org' => 'does-not-exist']],
    ['kitsune:stripe-replay', ['--org' => 'stripe-inbox', '--event' => '1.0']],
    ['kitsune:stripe-replay', ['--org' => 'stripe-inbox', '--seconds' => '0']],
    ['kitsune:stripe-replay', ['--org' => 'stripe-inbox', '--seconds' => '301']],
]);

it('independently refuses tombstoning a pending attempt through the custody erasure door', function (): void {
    $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    expect(fn () => DB::transaction(fn () => $this->inbox->forgetOrder(DB::connection(), $this->org->getKey(), $order)))->toThrow(CheckoutRefused::class);
    expect(DB::table(StripeEventInbox::TABLE)->value('raw_payload'))->toBe($this->raw)->and(DB::table(StripeEventInbox::ROUTING)->count())->toBe(1);
});

it('admits the thousandth event and refuses the next without breaking history or duplicate acknowledgement', function (): void {
    $original = $this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC'));
    $seed = (array) DB::table(StripeEventInbox::TABLE)->sole();
    unset($seed['id']);
    $batch = [];
    for ($index = 1; $index < StripeEventInbox::MAX_EVENTS_PER_ORDER - 1; $index++) {
        $id = 'evt_capacity_'.$index;
        $raw = '{"event":"'.$id.'","private":"bounded fixture"}';
        $batch[] = [...$seed, 'event_id' => $id, 'raw_payload' => $raw, 'payload_hash' => hash('sha256', $raw)];
    }
    foreach (array_chunk($batch, 100) as $chunk) {
        DB::table(StripeEventInbox::TABLE)->insert($chunk);
    }
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(999);
    $last = [...$this->event, 'id' => 'evt_capacity_last'];
    $this->inbox->capture($this->org->getKey(), $last, '{"event":"evt_capacity_last"}', CarbonImmutable::now('UTC'));
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(1000);
    $over = [...$this->event, 'id' => 'evt_capacity_over'];
    expect(fn () => $this->inbox->capture($this->org->getKey(), $over, '{"event":"evt_capacity_over"}', CarbonImmutable::now('UTC')))->toThrow(CheckoutRefused::class);
    expect(DB::table(StripeEventInbox::TABLE)->count())->toBe(1000)
        ->and($this->inbox->capture($this->org->getKey(), $this->event, $this->raw, CarbonImmutable::now('UTC')))->toBe([...$original, 'duplicate' => true]);
    $order = DB::table('commerce_orders')->where('id', $this->attempt->order_id)->sole();
    expect($this->inbox->projection(DB::connection(), $this->org->getKey(), $order))->toHaveCount(1000);
});
