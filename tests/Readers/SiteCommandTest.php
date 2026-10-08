<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Console\SiteCommand;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * `kitsune:site address` — a site's public address from the console (ADR-021, amended 2026-10-08). Here because the
 * point of an address is a reader page that answers at it, which `ReaderTestCase` can request.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    $this->blog = ReaderFixture::site($this->world['golfdom'], 'blog', 'Blog', null);
    ReaderFixture::forget();
});

afterEach(function (): void {
    expect(app(Context::class)->orgId())->toBeNull();
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function siteRun(array $parameters): array
{
    $status = app(ConsoleKernel::class)->handle(new ArrayInput(['command' => 'kitsune:site', ...$parameters]), $output = new BufferedOutput);

    return [$status, trim($output->fetch())];
}

/** @return array{0: string|null, 1: string|null, 2: string|null} */
function siteClaim(string $handle, string $org = 'golfdom'): array
{
    $site = Site::query()->withoutGlobalScopes()->where('handle', $handle)->whereHas('org', static fn ($query) => $query->where('slug', $org))->sole();

    return [$site->base_url, $site->canonical_host, $site->path_prefix];
}

function siteAudits(string $action): int
{
    return DB::table('audit_log')->where('action', $action)->count();
}

const SITE_R4 = 'Refusing: an address starts with https:// or http://, names its host, and gives any port as a number up to 65535 — e.g. https://acme.example or https://acme.example/blog; a bare acme.example would be read as a path on every host. Nothing was read or written.';
const SITE_R5 = 'Refusing: an address is a host with an optional port and path — no user name, password, ? or #. The value is not repeated. Nothing was read or written.';

it('gives an admin-only site its first address at once, and its reader pages answer there', function (): void {
    ReaderFixture::mode($this->blog, 'sign-in');
    ReaderFixture::forget();

    $this->readerGet('https://blog.example/account/sign-in')->assertNotFound();

    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([0, 'Site [blog] now has the address [https://blog.example], and answers at [blog.example]. Before: no address.'])
        ->and(app(Context::class)->orgId())->toBeNull();

    $this->readerGet('https://blog.example/account/sign-in')->assertOk()->assertSee('<title>Sign in — Blog</title>', false);
    // The request resolved its own site; the command's context was checked above.
    ReaderFixture::forget();

    $blog = Site::query()->withoutGlobalScopes()->findOrFail($this->blog->getKey());

    expect([$blog->base_url, $blog->canonical_host, $blog->path_prefix, $blog->url_strategy])->toBe(['https://blog.example', 'blog.example', '', 'path'])
        ->and(siteClaim('golfdom'))->toBe(['/golfdom', '', '/golfdom'])
        ->and(siteClaim('golfdom-fr'))->toBe(['/golfdom-fr', '', '/golfdom-fr'])
        ->and(DB::table('audit_log')->where('action', SiteCommand::ADDRESS_SET)->get(['org_id', 'site_id', 'actor_id', 'target_id'])->map(fn ($row): array => (array) $row)->all())
        ->toBe([['org_id' => $this->world['golfdom']->getKey(), 'site_id' => null, 'actor_id' => null, 'target_id' => (string) $this->blog->getKey()]]);
});

it('refuses anything but an absolute http or https address, before reading anything', function (string $url): void {
    expect(siteRun(['action' => 'address', 'url' => $url, '--org' => 'nowhere', '--site' => 'blog']))->toBe([1, SITE_R4])
        ->and(siteClaim('blog')[0])->toBeNull();
})->with([
    'a bare host' => 'blog.example',
    'a bare path' => '/blog',
    'no scheme' => '//blog.example',
    'another scheme' => 'ftp://blog.example',
    'one slash' => 'https:/blog.example',
    'no host' => 'http://',
    'mail' => 'mailto:x@y',
    'a bad port' => 'https://blog.example:99999',
]);

it('refuses a user name, password, query or fragment, never repeats it, and reads nothing first', function (string $url): void {
    [$status, $output] = siteRun(['action' => 'address', 'url' => $url, '--org' => 'nowhere', '--site' => 'blog']);

    expect([$status, $output])->toBe([1, SITE_R5])
        ->and($output)->not->toContain('secret')
        ->and($output)->not->toContain('a=1')
        ->and(siteClaim('blog')[0])->toBeNull();
})->with([
    'credentials' => 'https://user:secret@blog.example',
    'an empty user' => 'https://@blog.example',
    'a query' => 'https://blog.example/?a=1',
    'a fragment' => 'https://blog.example/#top',
]);

it('passes the model\'s own refusals through, escaped, before reading anything', function (): void {
    [$status, $deep] = siteRun(['action' => 'address', 'url' => 'https://blog.example/a/b/c/d/e', '--org' => 'nowhere', '--site' => 'blog']);

    expect($status)->toBe(1)
        ->and($deep)->toContain('at most 4')
        ->and($deep)->toEndWith('Nothing was read or written.')
        ->and($deep)->not->toContain('nowhere');

    [, $dots] = siteRun(['action' => 'address', 'url' => 'https://blog.example/a/../b', '--org' => 'nowhere', '--site' => 'blog']);

    expect($dots)->toStartWith('Refusing')
        ->and($dots)->toContain('..')
        ->and($dots)->toEndWith('Nothing was read or written.');

    // A typed tag is shown as typed, not read as a style.
    [, $tagged] = siteRun(['action' => 'address', 'url' => 'https://blog.example/<info>x', '--org' => 'nowhere', '--site' => 'blog']);

    expect($tagged)->toContain('<info>x')
        ->and(siteClaim('blog')[0])->toBeNull();
});

it('needs its one action, an address, --org and --site, and names an organisation it cannot find', function (): void {
    expect(siteRun(['action' => 'delete', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'Refusing: kitsune:site has one action, address. Nothing was read or written.'])
        ->and(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--site' => 'blog']))
        ->toBe([1, 'Refusing: address needs --org=<slug> and --site=<handle>. Nothing was read or written.'])
        ->and(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom']))
        ->toBe([1, 'Refusing: address needs --org=<slug> and --site=<handle>. Nothing was read or written.'])
        ->and(siteRun(['action' => 'address', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'Refusing: address needs the site\'s public address, e.g. https://acme.example. Nothing was read or written.'])
        ->and(siteRun(['action' => 'address', 'url' => '   ', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'Refusing: address needs the site\'s public address, e.g. https://acme.example. Nothing was read or written.'])
        ->and(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'nowhere', '--site' => 'blog']))
        ->toBe([1, 'Refusing: no organisation has the slug [nowhere]. Nothing was read or written.']);
});

it('finds the site only inside --org, from the other organisation\'s side', function (): void {
    expect(siteRun(['action' => 'address', 'url' => 'https://shop.example', '--org' => 'golfdom', '--site' => 'shop', '--force' => true]))
        ->toBe([1, 'Refusing: [golfdom] has no site with the handle [shop]. Nothing was written.'])
        ->and(siteClaim('shop', 'rival'))->toBe(['https://rival.test/shop', 'rival.test', '/shop']);
});

it('cannot claim an address another organisation holds or overlaps, and never prints what that one stored', function (): void {
    // ⚠️ A write that is not this command's — `tinker`, a seeder — keeps a user name and password the derivation ignores.
    app(Context::class)->forget()->setOrg($this->world['rival']);
    $shop = Site::query()->where('handle', 'shop')->sole();
    $shop->base_url = 'https://admin:hunter2@rival.test/shop';
    $shop->save();
    ReaderFixture::forget();

    [$status, $output] = siteRun(['action' => 'address', 'url' => 'https://rival.test', '--org' => 'golfdom', '--site' => 'blog']);

    expect($status)->toBe(1)
        ->and($output)->toStartWith('Refusing [https://rival.test]: another org already holds [rival.test/shop] on the same host')
        ->and($output)->toEndWith('Nothing was written.')
        ->and($output)->not->toContain('hunter2')
        ->and(siteClaim('blog')[0])->toBeNull()
        ->and(siteClaim('shop', 'rival'))->toBe(['https://admin:hunter2@rival.test/shop', 'rival.test', '/shop'])
        ->and(siteAudits(SiteCommand::ADDRESS_SET))->toBe(0);
});

it('answers a duplicate in its own organisation in words, with no SQL', function (): void {
    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog'])[0])->toBe(0);

    [$status, $output] = siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'golfdom-fr', '--force' => true]);

    expect([$status, $output])->toBe([1, 'Refusing: another site of [golfdom] already answers at exactly that address. Nothing was written.'])
        ->and(siteClaim('golfdom-fr'))->toBe(['/golfdom-fr', '', '/golfdom-fr'])
        ->and(siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(0);
});

it('will not move a live address without --force, and the old one is free at once', function (): void {
    $move = ['action' => 'address', 'url' => 'https://golfdom.example', '--org' => 'golfdom', '--site' => 'golfdom'];

    expect(siteRun($move))
        ->toBe([1, 'Refusing: site [golfdom] answers at [/golfdom] on any host now, and moving it breaks every link to it. Run it again with --force. Nothing was written.'])
        ->and(siteClaim('golfdom'))->toBe(['/golfdom', '', '/golfdom'])
        ->and(siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(0);

    expect(siteRun([...$move, '--force' => true]))
        ->toBe([0, 'Site [golfdom] now has the address [https://golfdom.example], and answers at [golfdom.example]. Before: [/golfdom] on any host.'])
        ->and(siteClaim('golfdom'))->toBe(['https://golfdom.example', 'golfdom.example', ''])
        ->and(siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(1);

    expect(ReaderFixture::site($this->world['rival'], 'taken', 'Taken', '/golfdom')->path_prefix)->toBe('/golfdom');
    ReaderFixture::forget();
});

it('respells an address without --force, and writes nothing for the address it already has', function (): void {
    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog'])[0])->toBe(0);

    expect(siteRun(['action' => 'address', 'url' => 'https://BLOG.example/', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([0, 'Site [blog] now has the address [https://BLOG.example/], and answers at [blog.example]. Before: [blog.example].'])
        ->and(siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(1);

    $stamped = Site::query()->withoutGlobalScopes()->findOrFail($this->blog->getKey())->updated_at;
    $this->travel(5)->minutes();

    expect(siteRun(['action' => 'address', 'url' => 'https://BLOG.example/', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([0, 'Site [blog] already has the address [https://BLOG.example/]. Nothing was written.'])
        ->and(Site::query()->withoutGlobalScopes()->findOrFail($this->blog->getKey())->updated_at->equalTo($stamped))->toBeTrue()
        ->and(siteAudits(SiteCommand::ADDRESS_SET) + siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(2);
});

it('answers a database failure with its SQLSTATE alone', function (): void {
    Site::saving(static function (): never {
        throw new QueryException('sqlite', 'update "sites" set "base_url" = ?', ['https://blog.example'], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
    });

    [$status, $output] = siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog']);

    expect([$status, $output])->toBe([1, 'Refusing: the database failed (SQLSTATE HY000), and its message is not repeated. Running it again is safe.'])
        ->and($output)->not->toContain('update')
        ->and($output)->not->toContain('blog.example')
        ->and(siteClaim('blog')[0])->toBeNull()
        ->and(siteAudits(SiteCommand::ADDRESS_SET))->toBe(0);
});

it('will not move a live address to another host or another path without --force', function (string $url): void {
    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog'])[0])->toBe(0);

    expect(siteRun(['action' => 'address', 'url' => $url, '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'Refusing: site [blog] answers at [blog.example] now, and moving it breaks every link to it. Run it again with --force. Nothing was written.'])
        ->and(siteClaim('blog'))->toBe(['https://blog.example', 'blog.example', ''])
        ->and(siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(0);
})->with([
    'another host, the same path' => 'https://news.example',
    'the same host, another path' => 'https://blog.example/news',
]);

it('reads the scheme without regard to case, and the address without its surrounding spaces', function (): void {
    expect(siteRun(['action' => 'address', 'url' => ' HTTPS://blog.example ', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([0, 'Site [blog] now has the address [HTTPS://blog.example], and answers at [blog.example]. Before: no address.'])
        ->and(siteClaim('blog'))->toBe(['HTTPS://blog.example', 'blog.example', '']);
});

it('refuses a deleted organisation by name, whose sites answer no request', function (): void {
    $this->world['rival']->delete();

    expect(siteRun(['action' => 'address', 'url' => 'https://shop.example', '--org' => 'rival', '--site' => 'shop', '--force' => true]))
        ->toBe([1, 'Refusing: [rival] is a deleted organisation, and its sites are given no address. Nothing was written.'])
        ->and(Site::query()->withoutGlobalScopes()->findOrFail($this->world['sites']['shop']->getKey())->base_url)->toBe('https://rival.test/shop')
        ->and(siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(0);
});

it('keeps no address it could not record', function (): void {
    // ⚠️ Recorded inside the save's transaction, so an audit store that refuses takes the address with it (ADR-020).
    AuditLog::creating(static fn () => throw new RuntimeException('audit down'));

    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'audit down Nothing was written.'])
        ->and(siteClaim('blog'))->toBe([null, null, null]);
});

it('refuses a save a listener cancelled, and reports no success', function (): void {
    Site::saving(static fn (): bool => false);

    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'Refusing: a listener cancelled the save. Nothing was written.'])
        ->and(siteClaim('blog'))->toBe([null, null, null])
        ->and(siteAudits(SiteCommand::ADDRESS_SET))->toBe(0);
});

it('answers a driver\'s own SQLSTATE, not its error code', function (): void {
    $driver = new PDOException('no state here');
    $driver->errorInfo = ['40001', 1213, 'deadlock'];

    Site::saving(static function () use ($driver): never {
        throw new QueryException('mysql', 'update `sites` set `base_url` = ?', ['https://blog.example'], $driver);
    });

    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, 'Refusing: the database failed (SQLSTATE 40001), and its message is not repeated. Running it again is safe.']);
});

it('will not replace what another run wrote after this one read the site', function (string $race, string $expected): void {
    /*
     * ⚠️ THE INTERLEAVING TWO OPERATORS PRODUCE, made deterministic: the other run commits between this one's read and
     * its save. Under PostgreSQL or MySQL this run then queues for the host's mutex and goes on once the other commits,
     * and `Site::save()`'s stale-origin check passes a row moved to the very host it locks — by design. So without its
     * own check this run moved a live address without --force, audited as set, printed "Before: no address", and on a
     * deleted row reported success and audited a site that no longer existed. Measured by review.
     */
    $id = $this->blog->getKey();
    $done = false;

    Site::retrieved(static function (Site $site) use ($id, $race, &$done): void {
        if ($done || $site->getKey() !== $id) {
            return;
        }

        $done = true;

        $race === 'moved'
            ? DB::table('sites')->where('id', $id)->update(['base_url' => 'https://blog.example', 'canonical_host' => 'blog.example', 'path_prefix' => ''])
            : DB::table('sites')->where('id', $id)->delete();
    });

    expect(siteRun(['action' => 'address', 'url' => 'https://blog.example/x', '--org' => 'golfdom', '--site' => 'blog']))
        ->toBe([1, $expected])
        ->and(siteAudits(SiteCommand::ADDRESS_SET) + siteAudits(SiteCommand::ADDRESS_REPLACED))->toBe(0)
        ->and(DB::table('sites')->where('id', $id)->value('path_prefix'))->toBe($race === 'moved' ? '' : null);
})->with([
    'another run gave it an address' => ['moved', 'Refusing: site [blog] changed while this ran, so what it was about to replace is not what it read. Run it again. Nothing was written.'],
    'another run deleted it' => ['deleted', 'Refusing: site [blog] changed while this ran, so what it was about to replace is not what it read. Run it again. Nothing was written.'],
]);

it('is internal', function (): void {
    expect((string) (new ReflectionClass(SiteCommand::class))->getDocComment())
        ->toContain('@internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.');
});
