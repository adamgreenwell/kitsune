<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** The actual host routes and module provider, at level zero in a disposable native application. @return array<string, mixed> */
function publicCataloguePageProbe(string $scenario, bool $cached = false): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-catalogue-page-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);

    try {
        foreach ($cached ? ['seed', 'cache', 'request'] : ['seed', 'request'] as $step) {
            $probe = new Process([PHP_BINARY, __DIR__.'/Fixtures/PublicCataloguePageProbe.php', $root, $temporary, $step, $scenario], $root);
            $probe->setTimeout(45);
            $probe->mustRun();
        }

        try {
            return json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The catalogue page probe failed: '.substr($probe->getOutput().$probe->getErrorOutput(), -8000), previous: $failure);
        }
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

/** A personalized page and every refusal must carry the same private response boundary. */
function publicCataloguePageHeaders(array $response): void
{
    foreach (['cache-control' => 'no-store, private', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff'] as $name => $value) {
        expect($response['headers'][$name] ?? null)->toBe([$value], $name);
    }

    expect($response['headers']['content-security-policy'][0] ?? '')->toContain("default-src 'none'", "frame-ancestors 'none'", "base-uri 'none'");
}

it('serves a guest through the actual optional provider while reader accounts are off, with private headers', function (bool $cached): void {
    $result = publicCataloguePageProbe('guest', $cached);
    $response = $result['responses'][0];

    expect($result['cached'])->toBe($cached)->and($result['provider'])->toBe(1)
        ->and($response['status'])->toBe(200)
        ->and($response['body'])->toContain('Surf course 1', 'USD 20.00', 'Available')
        ->and($response['body'])->not->toContain('You have access', 'Your account', 'private-reader@example.test', 'commerce.order:private-order', 'course.surf-101');
    publicCataloguePageHeaders($response);
})->with(['uncached' => [false], 'real route cache' => [true]]);

it('presents permanent fixed and unavailable access offers as buyer copy without internal mappings', function (string $scenario, string $label): void {
    $response = publicCataloguePageProbe($scenario)['responses'][0];
    expect($response['status'])->toBe(200)->and($response['body'])->toContain($label)
        ->and($response['body'])->not->toContain('course.surf-101', 'offer_terms', 'not configured');
    publicCataloguePageHeaders($response);
})->with(['permanent' => ['guest', 'Permanent access'], 'fixed' => ['fixed-offer', '30 days from order confirmation'],
    'unknown' => ['unknown-offer', 'Access offer unavailable']]);

it('uses the declared canonical host and prefix rather than the admin slug', function (string $scenario, array $statuses): void {
    $result = publicCataloguePageProbe($scenario, true);

    expect(array_column($result['responses'], 'status'))->toBe($statuses);

    foreach ($result['responses'] as $response) {
        publicCataloguePageHeaders($response);
    }
})->with([
    'host root' => ['root-host', [200, 404]],
    'named host and path' => ['named-host', [200, 404]],
    'four-segment prefix' => ['deep-prefix', [200]],
    'unknown, admin slug and extra segment' => ['missing-site', [404, 404, 404]],
]);

it('keeps ordinary host routes and actual panel routes ahead of the catalogue fallback, cached or not', function (bool $cached): void {
    $responses = publicCataloguePageProbe('host-route', $cached)['responses'];

    expect($responses[0]['status'])->toBe(200)->and($responses[0]['body'])->toBe('The host owns this route.')
        ->and($responses[1]['status'])->toBe(302)
        ->and($responses[1]['headers']['location'][0] ?? '')->toContain('/admin/login');
})->with(['uncached' => [false], 'real route cache' => [true]]);

it('refuses public pages in custom panel URL space and honours panel domains', function (string $scenario, array $statuses): void {
    $result = publicCataloguePageProbe($scenario, true);

    expect(array_column($result['responses'], 'status'))->toBe($statuses);

    foreach ($result['responses'] as $response) {
        publicCataloguePageHeaders($response);
    }
})->with([
    'custom path' => ['panel-path', [404]],
    'panel domain owns only its host' => ['panel-domain', [200, 404]],
    'panel owns the host root' => ['panel-root', [404]],
]);

it('refuses disabled, removed and stale module receipts even though cached routes and product data remain', function (string $scenario, bool $providerExpected): void {
    $result = publicCataloguePageProbe($scenario, true);
    $response = $result['responses'][0];

    expect($result['cached'])->toBeTrue()->and($result['retained_products'])->toBe(1)
        ->and($result['provider'])->toBe($providerExpected ? 1 : 0)
        ->and($response['status'])->toBe(404)->and($response['body'])->not->toContain('Surf course', 'You have access');
    publicCataloguePageHeaders($response);
})->with([
    'disabled after boot' => ['disabled', true],
    'removed after boot' => ['missing-module', true],
    'stale after boot' => ['stale-version', true],
    'disabled before fresh boot' => ['disabled-at-boot', false],
    'removed before fresh boot' => ['missing-at-boot', false],
    'stale before fresh boot' => ['stale-at-boot', false],
    'product type disabled on this site' => ['disabled-type', true],
]);

it('refuses malformed or excessive page inputs with the private refusal headers', function (): void {
    $responses = publicCataloguePageProbe('invalid-page')['responses'];

    expect(array_column($responses, 'status'))->toBe(array_fill(0, 6, 404));

    foreach ($responses as $response) {
        publicCataloguePageHeaders($response);
    }
});

it('drops unknown query data before the session stores a previous URL and builds canonical page links', function (): void {
    $response = publicCataloguePageProbe('unknown-query')['responses'][0];

    expect($response['status'])->toBe(200)
        ->and($response['query'])->toBe([])
        ->and($response['previous'])->toBe('http://localhost/BRAND/catalogue')
        ->and($response['body'])->toContain('href="/brand/catalogue?page=2"')
        ->and($response['body'])->not->toContain('reader=', 'source=', 'private-reader', 'private-order', 'application.example.test', '/BRAND/catalogue?page=2');
    publicCataloguePageHeaders($response);
});

it('shows bounded candidate pages with previous and next links and a truthful empty later page', function (): void {
    $responses = publicCataloguePageProbe('pagination')['responses'];

    expect(array_column($responses, 'status'))->toBe([200, 200, 200])
        ->and(substr_count($responses[0]['body'], '<article>'))->toBe(24)
        ->and($responses[0]['body'])->toContain('href="/brand/catalogue?page=2"')
        ->and($responses[1]['body'])->toContain('Surf course 25', 'href="/brand/catalogue" rel="prev"')
        ->and($responses[1]['body'])->not->toContain('rel="next"')
        ->and($responses[2]['body'])->toContain('No products available on this page', 'href="/brand/catalogue?page=9999"')
        ->and($responses[2]['body'])->not->toContain('No products listed yet');
});

it('renders held access only for the authenticated reader without identifiers, sources or entitlement metadata', function (): void {
    $response = publicCataloguePageProbe('held')['responses'][0];

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('You have access', 'Your account')
        ->and($response['body'])->not->toContain('private-reader@example.test', 'commerce.order:private-order', 'course.surf-101');
    publicCataloguePageHeaders($response);
});

it('answers an entitlement or reader database outage with a generic 503 and no product or access answer', function (string $scenario): void {
    $result = publicCataloguePageProbe($scenario);
    $response = $result['responses'][0];

    expect($response['status'])->toBe(503)
        ->and($response['body'])->toContain('Catalogue unavailable', 'Please try again shortly')
        ->and($response['body'])->not->toContain('Surf course', 'You have access', 'USD 20.00', 'private-reader', 'private-order', 'SQLSTATE', 'select ', 'course.surf-101')
        ->and($result['logs'])->toContain('The public catalogue could not be read.', 'sqlstate')
        ->and($result['logs'])->not->toContain('private-reader', 'private-order', 'course.surf-101', 'select ');
    publicCataloguePageHeaders($response);
})->with(['entitlements table' => ['entitlement-outage'], 'reader load' => ['reader-outage'], 'catalogue query' => ['catalogue-outage']]);

it('maps a setup database failure before the controller with private headers and strips unknown query data', function (string $scenario): void {
    $result = publicCataloguePageProbe($scenario);
    $response = $result['responses'][0];

    expect($response['status'])->toBe(503)
        ->and($response['body'])->toContain('Catalogue unavailable')
        ->and($response['body'])->not->toContain('Surf course', 'You have access', 'private-reader', 'private-order', 'SQLSTATE')
        ->and($response['query'])->toBe([])
        ->and($response['previous'])->toBe('http://localhost/brand/catalogue')
        ->and($result['logs'])->toContain('The public catalogue could not be read.', 'sqlstate')
        ->and($result['logs'])->not->toContain('private-reader', 'private-order', 'select ');
    publicCataloguePageHeaders($response);
})->with(['site resolver query' => ['site-outage'], 'module receipt query' => ['module-outage']]);

it('keeps Arabic site language and direction while marking fallback English catalogue copy as English', function (): void {
    $response = publicCataloguePageProbe('arabic')['responses'][0];

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('<html lang="ar" dir="rtl">', '<main id="catalogue" lang="en" dir="ltr">', 'Browse what is available from this site.');
});

it('escapes product and site titles and renders only plain description text', function (): void {
    $response = publicCataloguePageProbe('escaped')['responses'][0];

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('Brand &amp; &lt;site&gt;', 'Surf &lt;script&gt;', 'Safe &amp; sound')
        ->and($response['body'])->not->toContain('<script>', '<p>Safe', 'alert("description")');
});

it('bounds a rich product description to the public excerpt without copying the full stored document', function (): void {
    $response = publicCataloguePageProbe('bounded-description')['responses'][0];
    $document = new DOMDocument;
    @$document->loadHTML($response['body']);
    $description = (new DOMXPath($document))->query('//p[@class="description"]')->item(0)?->textContent;

    expect($response['status'])->toBe(200)->and($description)->toBeString()
        ->and(mb_strlen($description))->toBe(240)->and($description)->toStartWith('BEGIN')
        ->and($response['body'])->not->toContain('END', str_repeat('a', 241));
});

it('uses the first eligible public image and never renders a private media URL', function (): void {
    $response = publicCataloguePageProbe('image')['responses'][0];

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toContain('src="/storage/media/catalogue-public.png"', 'referrerpolicy="no-referrer"')
        ->and($response['body'])->not->toContain('catalogue-private.png', '/admin/', 'kitsune-private');
});

it('keeps sold-out status and unmapped access honest without implying a purchase or grant', function (string $scenario, string $expected): void {
    $response = publicCataloguePageProbe($scenario)['responses'][0];

    expect($response['status'])->toBe(200)->and($response['body'])->toContain($expected)
        ->and($response['body'])->not->toContain('You have access', 'Buy now', 'Checkout');
})->with(['zero stock' => ['sold-out', 'Sold out'], 'no entitlement mapping' => ['unmapped', 'Available']]);
