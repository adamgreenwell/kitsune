<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Native HTTP and encrypted browser sessions on disposable SQLite; never provider traffic. @return array<string,mixed> */
function orderClaimPageProbe(string $scenario, bool $cached = false): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-order-claim-page-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    try {
        foreach ($cached ? ['seed', 'cache', 'request'] : ['seed', 'request'] as $step) {
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/OrderClaimPageProbe.php', $root, $temporary, $step, $scenario], $root);
            $process->setTimeout(60);
            $process->mustRun();
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

function orderClaimPageHeaders(array $response): void
{
    foreach (['cache-control' => 'no-store, private', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff'] as $name => $value) {
        expect($response['headers'][$name] ?? null)->toBe([$value], $name);
    }
    expect($response['headers']['content-security-policy'][0] ?? '')->toContain("frame-ancestors 'none'", "form-action 'self'");
    expect($response['oldInput'])->toBeNull()->and($response['input'])->toBe([])->and($response['query'])->toBe([])->and($response['rawBody'])->toBe('');
}

it('claims an exact guest order after native sign-in with immutable invoice and immediate verified access', function (string $scenario, bool $cached): void {
    $result = orderClaimPageProbe($scenario, $cached);
    expect($result['cached'])->toBe($cached)
        ->and(array_column($result['responses'], 'status'))->toBe([200, 303, 200, 200, 303, 200, 200, 200, 419, 200, 303, 200, 419, 303, 200, 200, 303, 404, 303, 404])
        ->and($result['responses'][6]['body'])->toContain('Sign in to save this order')
        ->and($result['responses'][10]['headers']['location'][0])->toBe($result['claimPath'])
        ->and($result['responses'][11]['body'])->toContain('claim-reader@example.test', 'Previous guest access links')
        ->and($result['responses'][14]['body'])->toContain('Purchased access has been checked')
        ->and($result['responses'][15]['body'])->toContain(basename($result['orderPath']))
        ->and($result['evidence'])->toBe(['grantsBeforeClaim' => 0, 'claimsAfterGets' => 0, 'originalUnchanged' => true, 'linesUnchanged' => true,
            'grants' => 1, 'claimAudits' => 1, 'claims' => 1, 'work' => 'processed', 'tokens' => 0, 'transactionLevel' => 0, 'pdoTransaction' => false]);
    foreach ([6, 8, 11, 12, 13, 14, 15, 16, 17, 19] as $index) {
        orderClaimPageHeaders($result['responses'][$index]);
        expect($result['responses'][$index]['body'])->not->toContain('private-claim-query', 'private-claim-body', 'ignored-reader');
        expect($result['responses'][$index]['previous'] ?? '')->not->toContain('private-claim-query');
    }
    expect($result['log'])->not->toContain('private-claim-query', 'private-claim-body', 'Private Invoice Lane');
})->with(['plain' => ['claim', false], 'cached' => ['claim', true], 'nested cached' => ['nested-flow', true]]);
