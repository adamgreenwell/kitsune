<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Separate production transactions and a real process kill; provider traffic alone is mocked. */
function stripeReplayProbe(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-stripe-replay-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    touch($directory.'/writer.sqlite');
    $process = null;
    try {
        $arguments = [PHP_BINARY, __DIR__.'/Fixtures/StripeReplayProbe.php', $root, $directory, $scenario];
        $process = new Process($arguments, $root);
        $process->setTimeout(60);
        if ($scenario === 'command') {
            $arguments[4] = 'command-seed';
            $seed = new Process($arguments, $root);
            $seed->setTimeout(60)->mustRun();
            $arguments[4] = 'resume-command';
            $process = new Process($arguments, $root);
            $process->setTimeout(60);
        }
        if ($scenario === 'kill') {
            $process->start();
            $deadline = microtime(true) + 20;
            while (! is_file($directory.'/processing-ready') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            expect(is_file($directory.'/processing-ready'))->toBeTrue($process->getErrorOutput());
            $process->stop(0, 9);
            $pdo = new PDO('sqlite:'.$directory.'/writer.sqlite');
            expect($pdo->query('select state from commerce_stripe_events')->fetchColumn())->toBe('processing');
            unset($pdo);
            $arguments[4] = 'resume';
            $process = new Process($arguments, $root);
            $process->setTimeout(60);
        }
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['orderHistoryUnchanged'])->toBeTrue()->and($result['linesUnchanged'])->toBeTrue()
            ->and($result['stock'])->toBe($scenario === 'fairness' ? '5' : '3')->and($result['grants'])->toBe(0)
            ->and($result['confirmed'] === null ? null : (string) $result['confirmed'])->toBe($scenario === 'fairness' ? null : '4800')
            ->and($result['paymentReceipts'])->toBe(1)->and($result['orderState'])->toBe($scenario === 'fairness' ? 'prepared' : 'paid')
            ->and($result['level'])->toBe(0)->and($result['pdo'])->toBeFalse()->and($result['attemptState'])->toBe($scenario === 'fairness' ? 'open' : 'completed');
        foreach ($result['calls'] as $call) {
            expect($call['method'])->toBe('GET')->and($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse();
        }

        return $result;
    } finally {
        if ($process?->isRunning()) {
            $process->stop(0, 9);
        }
        (new Filesystem)->deleteDirectory($directory);
    }
}

it('replays committed minimal receipts idempotently through the production reconciler', function (string $scenario): void {
    $result = stripeReplayProbe($scenario);
    expect($result['initial'])->toBe('pending')->and($result['receiptStates'])->each->toBe('processed')
        ->and($result['calls'])->toHaveCount(2);
    if ($scenario === 'nested') {
        expect($result['nested'])->toBe('processing');
    }
    if ($scenario === 'expired-raw') {
        expect($result['rawCleared'])->toBeTrue();
    }
    if ($scenario === 'command') {
        expect($result['commandStatus'])->toBe(0);
    }
})->with(['duplicate', 'different-events', 'expiry-first', 'nested', 'expired-raw', 'command']);

it('keeps transient provider failure visible and retries the same Session without another create', function (): void {
    $result = stripeReplayProbe('provider-failure');
    expect($result['states'])->toBe(['retryable', 'processed'])->and($result['calls'])->toHaveCount(4);
});

it('recovers a real killed process after both committed leases expire', function (): void {
    $result = stripeReplayProbe('kill');
    expect($result['initial'])->toBe('processing')->and($result['states'])->toBe(['processed'])->and($result['calls'])->toHaveCount(2);
});

it('reaches a later valid receipt across bounded runs while an older rotated-key receipt stays blocked', function (): void {
    $result = stripeReplayProbe('fairness');
    expect($result['states'])->toBe(['blocked', 'processed'])->and($result['receiptStates'])->toBe(['blocked', 'processed'])
        ->and($result['calls'])->toHaveCount(2);
});
