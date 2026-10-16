<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\Http\Controllers\OrderExportController;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as ExportOwnerFx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    ExportOwnerFx::boot();
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->site = $this->world['sites']['golfdom'];
    app(Context::class)->setSite($this->site);
    $this->owner = ExportOwnerFx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    $this->order = OrderFixture::plant($this->site);
    $this->second = OrderFixture::plant($this->site);
    $this->sibling = OrderFixture::plant($this->world['sites']['golfdom-fr']);
    $this->foreign = OrderFixture::plant($this->world['sites']['rival']);
});

afterEach(fn () => ExportOwnerFx::tearDown());

function ownerExportRequest(array $body = [], array $query = [], bool $token = true, bool $json = false, bool $header = false): Response
{
    $session = app('session.store');
    $session->regenerateToken();
    if (($query['_token'] ?? null) === 'valid') {
        $query['_token'] = $session->token();
    }
    $request = Request::create('https://localhost/admin/golfdom/storefront/orders/export?'.http_build_query($query), 'POST',
        ($token ? ['_token' => $session->token()] : []) + $body, [], [],
        ($json ? ['CONTENT_TYPE' => 'application/json'] : []) + ($header ? ['HTTP_X_CSRF_TOKEN' => $session->token()] : []));
    $request->setLaravelSession($session);

    return app(OrderExportController::class)($request);
}

it('downloads a complete private financial page only for the selected Site in either format', function (string $format): void {
    $response = ownerExportRequest(['format' => $format]);
    expect($response->getStatusCode())->toBe(200)->and($response->headers->get('Content-Disposition'))->toContain('attachment;', '.'.$format)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store', 'private')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toContain($this->order->reference, $this->second->reference)
        ->not->toContain($this->sibling->reference, $this->foreign->reference, 'Historical Buyer', 'historical-buyer@example.test', '1 Saved Street', 'owner_key', 'token_hash');
})->with(['jsonl', 'csv']);

it('requires explicit billing opt-in and only exports the recorded policy selection', function (): void {
    $this->site->update(['base_url' => 'https://exports.example.test']);
    $attempt = PaidOrderFixture::attempt($this->site, stock: null, quantity: 1);
    $paid = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $paid, $attempt, PaidOrderFixture::session($attempt)));
    $policy = app(RetentionPolicies::class)->createAsOwner(['name', 'address.country'], 'Recorded invoice obligation',
        'Explicit export fixture policy', RetentionPolicySnapshot::CONFIRMATION_DAYS, 30);
    app(OrderPrivacy::class)->adoptAsOwner($paid->reference, $policy->version, 'Explicit historical export adoption', true);
    expect(ownerExportRequest()->getContent())->not->toContain('Historical Buyer');
    $response = ownerExportRequest(['include_billing' => '1']);
    expect($response->getStatusCode())->toBe(200)->and($response->getContent())->toContain('Historical Buyer')
        ->not->toContain('historical-buyer@example.test', '1 Saved Street', 'Explicit export fixture policy');
    expect(ownerExportRequest(query: ['include_billing' => '1'])->getContent())->not->toContain('Historical Buyer');
});

it('continues only with a cursor for the same selected Site and export profile', function (): void {
    $first = ownerExportRequest(['limit' => '1']);
    $cursor = $first->headers->get('X-Kitsune-Next-Cursor');
    expect($first->getStatusCode())->toBe(200)->and($cursor)->toBeString()->not->toBe('');
    $next = ownerExportRequest(['limit' => '1', 'cursor' => $cursor]);
    expect($next->getStatusCode())->toBe(200)->and($next->getContent())->toContain($this->second->reference)->not->toContain($this->order->reference);
    $csv = ownerExportRequest(['format' => 'csv', 'limit' => '1', 'cursor' => $cursor]);
    expect($csv->getStatusCode())->toBe(200)->and($csv->getContent())->toContain($this->second->reference)->not->toContain($this->order->reference);
    expect(ownerExportRequest(['include_billing' => '1', 'cursor' => $cursor])->getStatusCode())->toBe(503);
    app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
    expect(ownerExportRequest(['cursor' => $cursor])->getStatusCode())->toBe(503);
});

it('refuses absent query-only header-only or JSON CSRF without producing an attachment', function (string $kind): void {
    $response = ownerExportRequest(query: $kind === 'query' ? ['_token' => 'valid'] : [], token: $kind === 'json', json: $kind === 'json', header: $kind === 'header');
    expect($response->getStatusCode())->toBe(419)->and($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and($response->getContent())->not->toContain('query-private-token', $this->order->reference);
})->with(['missing', 'query', 'header', 'json']);

it('refuses malformed native options without reflecting submitted values or an attachment', function (array $body): void {
    $response = ownerExportRequest($body);
    expect($response->getStatusCode())->toBe(422)->and($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and($response->getContent())->not->toContain('private-submitted-value', $this->order->reference);
})->with([
    'format' => [['format' => 'private-submitted-value']], 'zero' => [['limit' => '0']], 'over bound' => [['limit' => '101']],
    'nested cursor' => [['cursor' => ['private-submitted-value']]], 'billing array' => [['include_billing' => ['1']]],
    'forged Site' => [['site' => 'private-submitted-value']], 'forged Org' => [['org' => 'private-submitted-value']],
]);

it('rechecks owner runtime and Org admission at download time', function (string $denial): void {
    match ($denial) {
        'editor' => ExportOwnerFx::member(owner: false, email: 'export-editor@example.test'),
        'anonymous' => Auth::guard('web')->logout(),
        'disabled' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]),
        'stale' => DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => 'old']),
        'foreign Org' => app(Context::class)->setSite($this->world['sites']['rival']),
        'no Site' => app(Context::class)->forget()->setOrg($this->world['golfdom']),
    };
    Permissions::forget();
    $response = ownerExportRequest();
    expect($response->getStatusCode())->toBe(403)->and($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and($response->getContent())->not->toContain($this->order->reference, $this->foreign->reference);
})->with(['editor', 'anonymous', 'disabled', 'stale', 'foreign Org', 'no Site']);

it('returns no partial attachment when a later retained graph is malformed', function (): void {
    DB::table('commerce_order_lines')->where('order_id', $this->second->id)->update(['net_minor' => '1']);
    $response = ownerExportRequest();
    expect($response->getStatusCode())->toBe(503)->and($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and($response->getContent())->not->toContain($this->order->reference, 'Historical Buyer');
});

it('redacts admission exceptions and refuses a host callback changing the selected Site', function (string $attack): void {
    $dispatcher = Module::getEventDispatcher();
    Module::setEventDispatcher(clone $dispatcher);
    $sibling = $this->world['sites']['golfdom-fr'];
    Module::retrieved(static function () use ($attack, $sibling): void {
        if ($attack === 'exception') {
            throw new RuntimeException('Private admission failure about historical-buyer@example.test');
        }
        app(Context::class)->setSite($sibling);
    });
    try {
        $response = ownerExportRequest();
        expect($response->getStatusCode())->toBe(503)->and($response->headers->has('Content-Disposition'))->toBeFalse()
            ->and($response->getContent())->not->toContain('Private admission failure', $this->order->reference, $this->sibling->reference);
    } finally {
        Module::setEventDispatcher($dispatcher);
        app(Context::class)->setSite($this->site);
    }
})->with(['exception', 'Site']);
