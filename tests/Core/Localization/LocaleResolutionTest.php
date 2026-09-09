<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Http\Middleware\SetKitsuneContext;
use Kitsune\Core\Http\Middleware\SetSiteLocale;
use Kitsune\Core\Http\Middleware\SetUiLocale;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Localization\LocaleResolver;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * A stand-in for the application's user, carrying only the contract core reads.
 *
 * ⚠️ Deliberately NOT the skeleton's User model. Core must work against any
 * application's auth schema (ADR-002), so the test asserts against the contract
 * rather than against a particular table — if this needed a `locale` column to
 * pass, core would have grown a dependency the ADR forbids.
 */
final class ViewerWithPreference implements HasLocalePreference
{
    public function __construct(private readonly ?string $locale) {}

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}

/** A viewer that does not implement the contract at all, which is allowed. */
final class ViewerWithoutPreference {}

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);

    $this->english = Site::create([
        'org_id' => $this->org->id, 'handle' => 'en', 'slug' => 'en',
        'name' => 'English', 'locale' => 'en',
    ]);

    $this->arabic = Site::create([
        'org_id' => $this->org->id, 'handle' => 'ar', 'slug' => 'ar',
        'name' => 'Arabic', 'locale' => 'ar',
    ]);

    $this->resolver = new LocaleResolver;
});

afterEach(fn () => app(Context::class)->forget());

describe('the site decides the content locale', function (): void {
    it('takes the locale from the site being served', function (): void {
        expect($this->resolver->forSite($this->arabic))->toBe('ar')
            ->and($this->resolver->forSite($this->english))->toBe('en');
    });

    it('falls back to the application default with no site', function (): void {
        expect($this->resolver->forSite(null, 'en'))->toBe('en');
    });

    /*
     * ⚠️ THE STRUCTURAL CLAIM, and the reason this is a middleware rather than a
     * boot step. `setLocale()` is process state: if the locale were resolved once
     * per process rather than once per request, a multi-site install would serve
     * whichever site warmed the worker — under PHP-FPM for the life of that
     * process, under Octane until it restarts.
     *
     * Two sites resolved back to back in ONE process is the smallest thing that
     * can fail if that ever regresses.
     */
    it('resolves two sites differently within a single process', function (): void {
        $first = $this->resolver->forSite($this->arabic);
        $second = $this->resolver->forSite($this->english);

        expect($first)->toBe('ar')
            ->and($second)->toBe('en')
            // And direction follows, which is what a reader actually notices.
            ->and(Kitsune::textDirection($first))->toBe('rtl')
            ->and(Kitsune::textDirection($second))->toBe('ltr');
    });
});

describe('the viewer decides the UI locale', function (): void {
    /*
     * ⚠️ THE DISAGREEMENT CASE, which is the whole point of ADR-018 rule 2 and
     * the one the issue asks for by name. A test where the two agree would pass
     * against an implementation that ignored the viewer entirely.
     */
    it('prefers the viewer over the site they are administering', function (): void {
        $french = new ViewerWithPreference('fr');

        expect($this->resolver->forViewer($french, $this->arabic))->toBe('fr')
            // The site's own locale is untouched by the viewer's preference: the
            // two axes are independent, not a precedence chain that overwrites.
            ->and($this->resolver->forSite($this->arabic))->toBe('ar');
    });

    it('falls through to the site when the viewer has no preference', function (): void {
        expect($this->resolver->forViewer(new ViewerWithPreference(null), $this->arabic))->toBe('ar');
    });

    it('accepts a viewer that does not implement the contract', function (): void {
        // ⚠️ Not an error. Core cannot require an application's user model to
        // implement anything (ADR-002), so "no preference" is the right answer.
        expect($this->resolver->forViewer(new ViewerWithoutPreference, $this->arabic))->toBe('ar')
            ->and($this->resolver->forViewer(null, $this->arabic))->toBe('ar');
    });

    it('falls all the way to the default with neither viewer nor site', function (): void {
        expect($this->resolver->forViewer(null, null, 'en'))->toBe('en');
    });

    /*
     * ⚠️ Two viewers in ONE process, for the same reason two sites are resolved
     * above — with an extra edge the site case does not have. Editors with
     * different preferences hit the same worker back to back, and whichever
     * signed in first would otherwise pick the language for both.
     */
    it('resolves two viewers differently within a single process', function (): void {
        expect($this->resolver->forViewer(new ViewerWithPreference('de'), $this->english))->toBe('de')
            ->and($this->resolver->forViewer(new ViewerWithPreference('it'), $this->english))->toBe('it');
    });
});

describe('a stored locale is not trusted', function (): void {
    /*
     * ⚠️ A locale reaches `setLocale()` from stored data, and Laravel resolves
     * translation files by treating it as a PATH SEGMENT. A preference of
     * `../../../etc` is a value concatenated into a filesystem path, and both
     * sources here are editable — the UI locale by the user themselves
     * (AGENTS.md invariant 6), the site locale by an operator.
     *
     * Rejected values fall THROUGH rather than throwing: a stored preference
     * that stops matching should not take the admin down for that user, and the
     * honest degradation is the next locale in the chain.
     */
    it('ignores a locale that is not shaped like one', function (): void {
        foreach (['../../../etc/passwd', '../en', 'en/../..', 'en;rm -rf', '', 'e', str_repeat('a', 40)] as $hostile) {
            expect($this->resolver->forViewer(new ViewerWithPreference($hostile), $this->arabic))
                ->toBe('ar', "[{$hostile}] was accepted as a locale");
        }
    });

    it('still accepts the shapes Laravel and Filament actually ship', function (): void {
        // A guard that rejected these would be worse than none: it would silently
        // drop working configurations rather than a hostile one.
        foreach (['en', 'ar', 'ckb', 'pt_BR', 'zh-Hant', 'ku-Latn'] as $real) {
            expect($this->resolver->forViewer(new ViewerWithPreference($real), $this->arabic))
                ->toBe($real, "[{$real}] was rejected as a locale");
        }
    });
});

describe('the middlewares apply it to the request', function (): void {
    /*
     * ⚠️ THE RESOLVER PASSING PROVES NOTHING ABOUT WHETHER ANYTHING CALLS IT.
     * Every test above exercises the resolver directly, so all of them would stay
     * green with both middlewares deleted and the panel left unwired — which is
     * the shape of "a guard in a model event is a guard on one path" applied to a
     * request boundary. These drive the middleware itself.
     */
    it('sets the app locale from the site in context', function (): void {
        app()->setLocale('en');
        app(Context::class)->setSite($this->arabic);

        $response = (new SetSiteLocale(new LocaleResolver))
            ->handle(Request::create('/'), fn () => response('ok'));

        expect(app()->getLocale())->toBe('ar')
            ->and($response->getContent())->toBe('ok')
            ->and(Kitsune::textDirection())->toBe('rtl');
    });

    it('sets the app locale from the viewer, over the site', function (): void {
        app()->setLocale('en');
        app(Context::class)->setSite($this->arabic);

        $request = Request::create('/admin');
        $request->setUserResolver(fn () => new ViewerWithPreference('fr'));

        (new SetUiLocale(new LocaleResolver))->handle($request, fn () => response('ok'));

        // The site is Arabic and the admin is French: the disagreement case,
        // asserted through the middleware rather than only through the resolver.
        expect(app()->getLocale())->toBe('fr');
    });

    it('leaves a request with no viewer on the site locale', function (): void {
        app()->setLocale('en');
        app(Context::class)->setSite($this->arabic);

        (new SetUiLocale(new LocaleResolver))->handle(Request::create('/admin'), fn () => response('ok'));

        expect(app()->getLocale())->toBe('ar');
    });

    /*
     * ⚠️ Registered, not merely written. A middleware that exists and is not in
     * the stack is the same as one that does not exist, and nothing else in this
     * file would notice.
     */
    it('is registered on the panel, after the context it depends on', function (): void {
        $stack = KitsunePanel::apply(Panel::make()->id('locale-probe')->path('locale-probe'))
            ->getTenantMiddleware();

        expect($stack)->toContain(SetUiLocale::class)
            // Order is load-bearing: SetUiLocale falls back to the site's locale,
            // so it needs a site in Context to fall back to.
            ->and(array_search(SetUiLocale::class, $stack, true))
            ->toBeGreaterThan(array_search(SetKitsuneContext::class, $stack, true));
    });
});
