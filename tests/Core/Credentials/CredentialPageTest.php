<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialStatus;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Filament\Pages\Credentials;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Http\Controllers\CredentialSetController;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The credentials page — ADR-040, its admin half: who may reach it, what each line says, the form a value is typed into,
 * and the two actions that carry no value. The value's own path is `CredentialSetControllerTest`'s; what a browser
 * receives is `e2e/credentials.spec.js`'s.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    $this->site = Site::create(['handle' => 'main', 'slug' => 'acme-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->owner = Fx::member(email: 'owner@acme.test');
    $this->owner->forceFill(['name' => 'Olive Owner'])->save();
    PanelTenancy::enter($this->site);

    Route::get('/test-credentials/{tenant:slug}', static fn (): string => '')->name('filament.admin.pages.credentials');
    Route::post('/test-credentials/{tenant:slug}/set', CredentialSetController::class)->name('filament.admin.credentials.set');
    app('router')->getRoutes()->refreshNameLookups();

    session()->forget('filament.notifications');
});

afterEach(fn () => Fx::tearDown());

/** @return list<array<string, mixed>> */
function credentialNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/** The line for one credential at one mode, as the page builds it. */
function credentialLine(string $slot, ?CredentialMode $mode): array
{
    foreach (Credentials::lines() as $line) {
        if ($line['slot']->name === $slot && $line['mode'] === $mode) {
            return $line;
        }
    }

    throw new RuntimeException("no line for {$slot}");
}

/** A second member of the org in context, signed in, not an owner. */
function credentialMember(string $email = 'member@acme.test'): TestUser
{
    $user = Fx::member(owner: false, email: $email);
    Permissions::forget();

    return $user;
}

function credentialSignIn(?TestUser $user): void
{
    if ($user === null) {
        Auth::guard('web')->logout();
    } else {
        Auth::guard('web')->setUser($user);
    }

    Permissions::forget();
}

/* 1. Who may. */
it('opens to an owner of the org in context and to nobody else', function (): void {
    expect(Credentials::canAccess())->toBeTrue();

    $member = credentialMember();
    expect(Credentials::canAccess())->toBeFalse();

    credentialSignIn(null);
    expect(Credentials::canAccess())->toBeFalse();

    // An owner of another org, with this org in context: refused; in their own, allowed.
    $other = Org::create(['slug' => 'other', 'name' => 'Other']);
    app(Context::class)->setOrg($other);
    $otherSite = Site::create(['handle' => 'main', 'slug' => 'other-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($otherSite);
    $rival = Fx::member(email: 'owner@other.test');
    PanelTenancy::moveTo($this->site);
    credentialSignIn($rival);

    expect(Credentials::canAccess())->toBeFalse();

    PanelTenancy::moveTo($otherSite);
    Permissions::forget();

    expect(Credentials::canAccess())->toBeTrue()
        ->and($member->getKey())->not->toBe($rival->getKey());
});

/** The labels of the panel's own sidebar, as `KitsunePanel` builds it for the signed-in user. @return list<string> */
function credentialSidebar(): array
{
    $builder = Closure::bind(static fn (): NavigationBuilder => self::navigation(new NavigationBuilder), null, KitsunePanel::class)();

    return collect($builder->getNavigation())
        ->flatMap(static fn (NavigationGroup $group): array => $group->getItems())
        ->map(static fn (NavigationItem $item): string => (string) $item->getLabel())
        ->values()
        ->all();
}

/* 2. The link — asked of the sidebar the panel builds, not of the helper alone (review). */
it('puts the link in an owner\'s sidebar once a module declares a credential, and in nobody else\'s', function (): void {
    expect(Credentials::belongsInNavigation())->toBeTrue()
        ->and(credentialSidebar())->toContain('Credentials');

    app(CredentialSlots::class)->flush();
    expect(Credentials::belongsInNavigation())->toBeFalse()
        ->and(credentialSidebar())->not->toContain('Credentials')
        ->and(credentialSidebar())->toContain('Roles');

    Fx::boot();
    credentialMember();
    expect(Credentials::belongsInNavigation())->toBeFalse()
        ->and(credentialSidebar())->not->toContain('Credentials');
});

/* 3. Wiring. */
it('is a page of the panel, and its POST is registered for a signed-in site alone', function (): void {
    $panel = KitsunePanel::apply(Panel::make())->id('admin');

    expect($panel->getPages())->toContain(Credentials::class)
        ->and(Credentials::getSlug())->toBe('credentials')
        ->and(Credentials::shouldRegisterNavigation())->toBeFalse();

    $router = new Router(app(Dispatcher::class), app());
    Route::swap($router);

    try {
        foreach ($panel->getAuthenticatedTenantRoutes() as $routes) {
            $routes($panel);
        }
    } finally {
        Route::swap(app('router'));
    }

    $byName = [];

    foreach ($router->getRoutes()->getRoutes() as $route) {
        $byName[(string) $route->getName()] = $route->methods();
    }

    expect($byName[CredentialSetController::ROUTE] ?? null)->toBe(['POST'])
        ->and($byName[MediaDelivery::ROUTE] ?? null)->toBe(['GET', 'HEAD'])
        ->and($panel->getTenantRoutes())->toBe([])
        ->and($panel->getRoutes())->toBe([]);
});

/* 4. The lines. */
it('reads every line\'s own state at its own mode, and marks the one in use', function (): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    Fx::writer()->set(Fx::HOOK, CredentialMode::Test, Fx::value('fxhook_', 30));
    Fx::writer()->remove(Fx::HOOK, CredentialMode::Test);

    $lines = Credentials::lines();

    expect(array_map(static fn (array $line): string => $line['slot']->name.'/'.($line['mode']->value ?? 'none'), $lines))
        ->toBe([Fx::PAYMENT.'/test', Fx::PAYMENT.'/live', Fx::HOOK.'/test', Fx::HOOK.'/live', Fx::SHARED.'/none'])
        ->and(array_map(static fn (array $line): CredentialStatus => $line['state']->status, $lines))
        ->toBe([CredentialStatus::Set, CredentialStatus::NotSet, CredentialStatus::Removed, CredentialStatus::NotSet, CredentialStatus::Set])
        ->and(array_map(static fn (array $line): bool => $line['inUse'], $lines))->toBe([true, false, true, false, false]);

    Fx::writer()->switchTo(CredentialMode::Live);

    expect(array_map(static fn (array $line): bool => $line['inUse'], Credentials::lines()))->toBe([false, true, false, true, false]);

    app(CredentialSlots::class)->flush();

    expect(Credentials::lines())->toBe([]);
});

it('says each status in its own words and colour, and never calls a stored value good', function (): void {
    expect(array_map(static fn (CredentialStatus $status): array => Credentials::badgeOf($status), CredentialStatus::cases()))->toBe([
        ['Not set', 'gray', null],
        ['Removed', 'gray', null],
        ['Set', 'info', null],
        ['Set under a previous app key', 'warning', 'It still works. Replacing it (pasting the same key again is enough) moves it to the current app key.'],
        ['Unreadable', 'danger', 'It was stored under an app key this installation no longer has, or what is stored is damaged, so nothing can use it. Paste it again from where it was issued. If the app key was changed by mistake, whoever runs this installation can put it back, which brings back every credential at once.'],
    ]);
});

it('tells a value under a previous key and one under no key apart from a current one', function (): void {
    $k1 = (string) config('app.key');
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    config(['app.key' => Fx::appKey(), 'app.previous_keys' => [$k1]]);
    Fx::forget();
    expect(credentialLine(Fx::SHARED, null)['state']->status)->toBe(CredentialStatus::SetUnderPreviousKey);

    config(['app.previous_keys' => []]);
    Fx::forget();
    expect(credentialLine(Fx::SHARED, null)['state']->status)->toBe(CredentialStatus::Unreadable);
});

it('costs a fixed count of queries, whatever is stored', function (): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));
    Fx::writer()->set(Fx::HOOK, CredentialMode::Test, Fx::value('fxhook_', 30));
    Fx::writer()->set(Fx::HOOK, CredentialMode::Live, Fx::value('fxhook_', 30));
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    DB::flushQueryLog();
    DB::enableQueryLog();
    Credentials::lines();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The mode, one per line, the audit log's two, and the actors' one.
    expect($count)->toBe(9);
});

/* 5. Decrypts nothing. */
it('reads a damaged value as set, because it never opens one', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    DB::table('credentials')->update(['ciphertext' => base64_encode('{"iv":"x","value":"damaged","mac":"","tag":"x"}')]);

    expect(credentialLine(Fx::SHARED, null)['state']->status)->toBe(CredentialStatus::Set);
});

/* 6. By whom. */
it('names who last changed a line through the org\'s own members, and nobody else', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    expect(credentialLine(Fx::SHARED, null)['by'])->toBe('Olive Owner');

    // Replaced by a second owner: the latest wins.
    $second = Fx::member(email: 'second@acme.test');
    $second->forceFill(['name' => 'Sam Second'])->save();
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    expect(credentialLine(Fx::SHARED, null)['by'])->toBe('Sam Second');

    // The second owner leaves the org: not found, so not named.
    DB::table('org_user')->where('user_id', $second->getKey())->delete();
    Permissions::forget();
    credentialSignIn($this->owner);
    expect(credentialLine(Fx::SHARED, null)['by'])->toBe('someone no longer in this organisation');

    // Written with nobody signed in.
    credentialSignIn(null);
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Test, Fx::value('fx_test_'));
    credentialSignIn($this->owner);
    expect(credentialLine(Fx::PAYMENT, CredentialMode::Test)['by'])->toBe('the system');
});

it('names an actor of another kind as one, building no class a row names', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    $forged = 'Kitsune\\Nowhere\\ForgedActor';
    DB::table('audit_log')->where('action', 'like', 'credential.%')->update(['actor_type' => $forged]);

    expect(credentialLine(Fx::SHARED, null)['by'])->toBe('an account of another kind')
        ->and(class_exists($forged, false))->toBeFalse();
});

/* 7. No browser endpoint. */
it('adds no public property and no public instance method a browser could call', function (): void {
    $class = new ReflectionClass(Credentials::class);

    $properties = array_values(array_filter(
        $class->getProperties(ReflectionProperty::IS_PUBLIC),
        static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === Credentials::class,
    ));
    $instance = array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), array_filter(
        $class->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => ! $method->isStatic() && $method->getDeclaringClass()->getName() === Credentials::class,
    )));
    sort($instance);

    // Two overrides of Filament's own public methods, which the page already had, and the upload restriction's flag,
    // which every core component carries (`UploadSurfaceTest`) and which answers a boolean and nothing else.
    expect($properties)->toBe([])
        ->and($instance)->toBe(['content', 'getTitle', 'shouldRestrictFileUploadsToSchemaComponents']);
});

/* 8. The set action. */
it('builds Set as a modal holding a plain form, with no field, no handler and no argument read', function (): void {
    foreach (Credentials::lines() as $line) {
        $action = Credentials::setActionFor($line['slot'], $line['mode'], $line['state'])
            ->arguments(['slot' => Fx::SHARED, 'mode' => 'production']);

        $content = (string) $action->getModalContent()?->toHtml();

        expect($action->hasFormWrapper())->toBeFalse()
            ->and($action->getSchema(Schema::make(app(Credentials::class))))->toBeNull()
            ->and($action->getActionFunction())->toBeNull()
            ->and($action->getModalSubmitAction())->toBeNull()
            ->and($action->isAuthorized())->toBeTrue()
            ->and($content)->toContain('name="slot" value="'.$line['slot']->name.'"');

        if ($line['mode'] !== null) {
            expect($content)->toContain('name="mode" value="'.$line['mode']->value.'"');
        } else {
            expect($content)->not->toContain('name="mode"');
        }
    }

    credentialMember();
    $line = credentialLine(Fx::SHARED, null);
    expect(Credentials::setActionFor($line['slot'], null, $line['state'])->isAuthorized())->toBeFalse();

    credentialSignIn(null);
    expect(Credentials::setActionFor($line['slot'], null, $line['state'])->isAuthorized())->toBeFalse();
});

/* 9. The form. */
it('renders a form that posts, names its field password, and carries no value or length', function (): void {
    $slot = app(CredentialSlots::class)->find(Fx::PAYMENT);
    $html = (string) Credentials::valueForm('/x/set', 'tok', $slot, CredentialMode::Live);
    $input = preg_match('/<input id="[^"]+" class="fi-input" type="password"[^>]*>/', $html, $match) === 1 ? $match[0] : '';

    expect($html)->toStartWith('<form method="post" action="/x/set" autocomplete="off"')
        ->toContain('<input type="hidden" name="_token" value="tok">')
        ->toContain('wire:key="credential-form-fx__payment-key-live"')
        ->toContain('<label for="credential-value-fx__payment-key-live"')
        ->toContain('aria-describedby="credential-value-fx__payment-key-live-format"')
        ->toContain('<p id="credential-value-fx__payment-key-live-format"')
        ->not->toContain('wire:model')
        ->not->toContain('wire:ignore')
        ->toContain('x-on:submit="$el.querySelector(\'[type=submit]\').disabled = true"')
        ->and(preg_match('/<button type="submit"[^>]*>/', $html, $button))->toBe(1)
        ->and($button[0])->not->toContain('name=')
        ->and($input)->toContain('name="password"')
        ->toContain('autocomplete="off"')
        ->not->toContain('value=')
        ->not->toContain('maxlength')
        ->not->toContain('minlength')
        ->and(CredentialSetController::FIELD)->toBe('password');

    // Keys apart for every line, and for two names one hyphen-for-dot apart.
    $keys = array_map(static fn (array $line): string => Credentials::keyOf($line['slot'], $line['mode']), Credentials::lines());
    app(CredentialSlots::class)->register(new CredentialSlot('fx-payment.key', 'Look-alike', 'Help', true, ['test' => ['la_test_'], 'live' => ['la_live_']], minLength: 32));
    $lookAlike = Credentials::keyOf(app(CredentialSlots::class)->find('fx-payment.key'), CredentialMode::Live);

    expect(array_unique($keys))->toHaveCount(5)
        ->and($lookAlike)->not->toBe(Credentials::keyOf($slot, CredentialMode::Live));
});

it('escapes what a module declares in every notice: nothing removed, removed, and refused', function (): void {
    app(CredentialSlots::class)->register(new CredentialSlot('fx.marked-up', '<b>Marked</b>', 'Help', false, minLength: 32));
    $slot = app(CredentialSlots::class)->find('fx.marked-up');

    Credentials::removeLine($slot, null);

    Fx::writer()->set('fx.marked-up', null, Fx::value('', 40));
    Credential::saving(static fn (): bool => false);

    try {
        Credentials::removeLine($slot, null);
    } finally {
        Credential::flushEventListeners();
        Credential::clearBootedModels();
    }

    Credentials::removeLine($slot, null);

    $notices = credentialNotices();
    expect($notices)->toHaveCount(3)
        ->and(array_map(static fn (array $notice): string => (string) $notice['title'], $notices))->toBe([
            '&lt;b&gt;Marked&lt;/b&gt; was not set, so nothing was removed.',
            'Not removed: &lt;b&gt;Marked&lt;/b&gt;',
            'Removed: &lt;b&gt;Marked&lt;/b&gt;',
        ])
        ->and((string) $notices[1]['body'])->toContain('&lt;b&gt;Marked&lt;/b&gt; was not removed')->not->toContain('<b>');
});

it('escapes every value it interpolates into the form', function (): void {
    $html = (string) Credentials::valueForm('/x/set?a="b"&c=<d>', 'to"k<en>', app(CredentialSlots::class)->find(Fx::SHARED), null);

    expect($html)->toContain('action="/x/set?a=&quot;b&quot;&amp;c=&lt;d&gt;"')
        ->toContain('name="_token" value="to&quot;k&lt;en&gt;"')
        ->not->toContain('<d>')
        ->not->toContain('<en>');
});

/* 10. Remove. */
it('removes a set line, recording it once, and says so', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    $line = credentialLine(Fx::SHARED, null);
    // Built with every action made transactional, as a host's `databaseTransactions()` panel makes them.
    $action = Action::configureUsing(
        static fn (Action $action) => $action->databaseTransaction(),
        during: static fn (): Action => Credentials::removeActionFor($line['slot'], null, $line['state'], true),
    );

    expect($action->isVisible())->toBeTrue()
        ->and($action->isConfirmationRequired())->toBeTrue()
        ->and($action->hasDatabaseTransactions())->toBeFalse()
        ->and((string) $action->getModalDescription())->toContain('It is in use right now');

    Credentials::removeLine($line['slot'], null);

    expect(credentialLine(Fx::SHARED, null)['state']->status)->toBe(CredentialStatus::Removed)
        ->and(AuditLog::query()->where('action', CredentialWriter::REMOVED)->count())->toBe(1)
        ->and(credentialNotices()[0]['title'])->toBe('Removed: Fixture shared secret')
        ->and(Credentials::removeActionFor($line['slot'], null, credentialLine(Fx::SHARED, null)['state'], true)->isVisible())->toBeFalse();
});

it('claims no removal when nothing was there, and records nothing', function (): void {
    $line = credentialLine(Fx::SHARED, null);

    Credentials::removeLine($line['slot'], null);

    expect(credentialNotices()[0]['title'])->toBe('Fixture shared secret was not set, so nothing was removed.')
        ->and(credentialNotices()[0]['status'])->toBe('info')
        ->and(AuditLog::query()->where('action', 'like', 'credential.%')->count())->toBe(0);
});

it('shows a refused removal in the store\'s words, escaped, and keeps the value', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    Credential::saving(static fn (): bool => false);

    try {
        Credentials::removeLine(app(CredentialSlots::class)->find(Fx::SHARED), null);
    } finally {
        Credential::flushEventListeners();
        Credential::clearBootedModels();
    }

    $notice = credentialNotices()[0];

    expect($notice['title'])->toBe('Not removed: Fixture shared secret')
        ->and($notice['status'])->toBe('danger')
        ->and($notice['duration'])->toBe('persistent')
        ->and((string) $notice['body'])->toContain('a listener cancelled')
        ->and(credentialLine(Fx::SHARED, null)['state']->status)->toBe(CredentialStatus::Set);
});

it('refuses a removal with nobody signed in, which the writer would have taken as the system\'s', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    credentialSignIn(null);

    expect(fn () => Credentials::removeLine(app(CredentialSlots::class)->find(Fx::SHARED), null))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));

    credentialSignIn($this->owner);
    expect(credentialLine(Fx::SHARED, null)['state']->status)->toBe(CredentialStatus::Set);
});

/* 11. Switch. */
it('switches to each fixed mode once, and says when it already was', function (): void {
    $live = Action::configureUsing(
        static fn (Action $action) => $action->databaseTransaction(),
        during: static fn (): Action => Credentials::switchActionTo(CredentialMode::Live),
    );

    expect($live->getName())->toBe('switchToLive')
        ->and($live->isConfirmationRequired())->toBeTrue()
        ->and($live->hasDatabaseTransactions())->toBeFalse();

    Credentials::switchOrgTo(CredentialMode::Live);
    expect(Fx::states()->mode())->toBe(CredentialMode::Live)
        ->and(credentialNotices()[0]['title'])->toBe('Now in live mode');

    session()->forget('filament.notifications');
    Credentials::switchOrgTo(CredentialMode::Live);
    expect(Fx::states()->mode())->toBe(CredentialMode::Live)
        ->and(credentialNotices()[0]['title'])->toBe('Already in live mode. Nothing was changed.')
        ->and(credentialNotices()[0]['status'])->toBe('info')
        ->and(AuditLog::query()->where('action', CredentialWriter::MODE_LIVE)->count())->toBe(1);

    session()->forget('filament.notifications');
    Credentials::switchOrgTo(CredentialMode::Test);
    expect(Fx::states()->mode())->toBe(CredentialMode::Test)
        ->and(credentialNotices()[0]['title'])->toBe('Now in test mode');
});

it('lists, before going live, what has no usable live value', function (): void {
    Fx::writer()->set(Fx::PAYMENT, CredentialMode::Live, Fx::value('fx_live_'));

    expect(Credentials::missingLive())->toBe('These have no usable live value yet, and will refuse to work until one is set: Fixture webhook secret.');

    Fx::writer()->set(Fx::HOOK, CredentialMode::Live, Fx::value('fxhook_', 30));

    expect(Credentials::missingLive())->toBe('');

    // Removed counts as missing; and so does a value no key this installation has can open.
    Fx::writer()->remove(Fx::PAYMENT, CredentialMode::Live);
    expect(Credentials::missingLive())->toBe('These have no usable live value yet, and will refuse to work until one is set: Fixture payment key.');

    config(['app.key' => Fx::appKey(), 'app.previous_keys' => []]);
    Fx::forget();
    expect(Credentials::missingLive())->toBe('These have no usable live value yet, and will refuse to work until one is set: Fixture payment key, Fixture webhook secret.');
});

it('offers no switch where nothing is kept per mode', function (): void {
    app(CredentialSlots::class)->flush();
    app(CredentialSlots::class)->register(new CredentialSlot('fx.alone', 'Alone', 'Help', false, minLength: 32));
    $page = app(Credentials::class);

    expect((fn (): array => $this->getHeaderActions())->call($page))->toBe([]);
});

it('refuses a switch with nobody signed in', function (): void {
    credentialSignIn(null);

    expect(fn () => Credentials::switchOrgTo(CredentialMode::Live))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));

    expect(Fx::states()->mode())->toBe(CredentialMode::Test);
});

/* 12. The pinned gap. */
it('goes live on a confirmation alone — re-entering a password is owed by the payments slice (ADR-040, answer 4)', function (): void {
    $live = Credentials::switchActionTo(CredentialMode::Live);

    expect($live->isConfirmationRequired())->toBeTrue()
        ->and($live->getSchema(Schema::make(app(Credentials::class))))->toBeNull();

    Credentials::switchOrgTo(CredentialMode::Live);

    expect(Fx::states()->mode())->toBe(CredentialMode::Live);
});
