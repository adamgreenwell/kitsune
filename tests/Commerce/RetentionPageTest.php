<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Kitsune\Commerce\Filament\RetentionResource;
use Kitsune\Commerce\Filament\RetentionSettings;
use Kitsune\Commerce\Models\RetentionPolicy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as RetentionPageFx;
use Livewire\Mechanisms\DataStore;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    RetentionPageFx::boot();
    app()->singleton(DataStore::class);
    $this->org = RetentionPageFx::org('retention-admin');
    $this->site = Site::create(['handle' => 'main', 'slug' => 'retention-admin-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = RetentionPageFx::member();
    Module::create(['handle' => 'kitsune/commerce', 'version' => ModuleDiscovery::version('kitsune/commerce'), 'is_enabled' => true, 'installed_at' => now()]);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
});

afterEach(fn () => RetentionPageFx::tearDown());

/** No period, obligation or personal fields are inferred by the product. @return array<string,mixed> */
function retentionPagePolicy(): array
{
    return ['billing_fields' => ['name', 'address.country'], 'legal_basis' => 'Our recorded invoice obligation',
        'reason' => 'Operator selected invoice profile', 'clock' => RetentionPolicySnapshot::CONFIRMATION_DAYS,
        'duration' => '30', 'timezone' => ''];
}

it('rechecks owner and runtime authority for every retention page action', function (string $denial): void {
    expect(RetentionResource::canAccess())->toBeTrue();
    match ($denial) {
        'member' => RetentionPageFx::member(owner: false, email: 'retention-editor@example.test'),
        'no actor' => Auth::guard('web')->logout(),
        'disabled' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['is_enabled' => false]),
        'stale' => Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update(['version' => 'old']),
    };
    Permissions::forget();
    expect(RetentionResource::canAccess())->toBeFalse();
    $page = app(RetentionSettings::class);
    foreach (['mount' => [], 'versions' => [], 'currentVersion' => [], 'createPolicy' => [], 'activatePolicy' => [1],
        'adoptPolicy' => [], 'placeHold' => [], 'releaseHold' => [], 'completeDelivery' => [], 'previewOrder' => [],
        'applyOrder' => [], 'previewBatch' => [], 'applyBatch' => [], 'loadDelivery' => [], 'deliveryDetails' => [],
        'loadHolds' => [], 'holds' => [], 'selectHold' => ['invalid']] as $method => $arguments) {
        expect(fn () => $page->{$method}(...$arguments))->toThrow(HttpException::class);
    }
    expect(RetentionPolicy::query()->count())->toBe(0);
})->with(['member', 'no actor', 'disabled', 'stale']);

it('creates a policy without activation and activates only the explicit selected version', function (): void {
    $page = app(RetentionSettings::class);
    $page->mount();
    expect($page->policy['duration'])->toBe('')->and($page->policy['billing_fields'])->toBe([])->and($page->currentVersion())->toBeNull();
    $page->policy = retentionPagePolicy();
    $page->createPolicy();
    expect($page->getErrorBag()->isEmpty())->toBeTrue()->and($page->versions())->toHaveCount(1)
        ->and($page->currentVersion())->toBeNull();
    $page->activatePolicy(1);
    expect($page->currentVersion())->toBe(1);
    $page->policy = [...retentionPagePolicy(), 'duration' => '60'];
    $page->createPolicy();
    expect($page->versions())->toHaveCount(2)->and($page->currentVersion())->toBe(1);
    $page->activatePolicy(2);
    expect($page->currentVersion())->toBe(2);
});

it('keeps calendar policy timezone explicit and rejects malformed or excess form fields safely', function (): void {
    $page = app(RetentionSettings::class);
    $page->policy = [...retentionPagePolicy(), 'clock' => RetentionPolicySnapshot::PAYMENT_YEAR_END, 'duration' => '7'];
    $page->createPolicy();
    expect($page->getErrorBag()->has('policy'))->toBeTrue()->and(RetentionPolicy::query()->count())->toBe(0);
    $page->policy['timezone'] = 'America/New_York';
    $page->createPolicy();
    expect($page->getErrorBag()->isEmpty())->toBeTrue()->and($page->versions()[0]->timezone)->toBe('America/New_York');
    $page->policy = retentionPagePolicy() + ['private' => 'private-policy-secret'];
    $page->createPolicy();
    expect($page->getErrorBag()->has('policy'))->toBeTrue()->and(RetentionPolicy::query()->count())->toBe(1)
        ->and(json_encode($page->getErrorBag()->toArray()))->not->toContain('private-policy-secret');
});

it('shares policy within one Org but refuses a foreign Org version from the attacker page', function (): void {
    $page = app(RetentionSettings::class);
    $page->policy = retentionPagePolicy();
    $page->createPolicy();
    $sibling = Site::create(['handle' => 'other', 'slug' => 'retention-admin-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($sibling);
    $page->activatePolicy(1);
    expect($page->currentVersion())->toBe(1)->and($page->versions()[0]->orgId)->toBe($this->org->id);

    RetentionPageFx::org('foreign-retention');
    RetentionPageFx::member(email: 'retention-other-owner@example.test');
    $policies = app(RetentionPolicies::class);
    $policies->createAsOwner([], 'Other obligation', 'Other reason', RetentionPolicySnapshot::CONFIRMATION_DAYS, 10);
    $foreign = $policies->createAsOwner([], 'Other obligation', 'Other reason', RetentionPolicySnapshot::CONFIRMATION_DAYS, 20);
    app(Context::class)->setSite($this->site);
    Auth::guard('web')->setUser($this->owner);
    Permissions::forget();
    $page->activatePolicy($foreign->version);
    expect($page->getErrorBag()->has('retention'))->toBeTrue()->and($page->currentVersion())->toBe(1)
        ->and($page->versions())->toHaveCount(1);
});
