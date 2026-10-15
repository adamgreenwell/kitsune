<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Kitsune\Commerce\Payments\StripeCheckout;
use Symfony\Component\Process\Process;

/** Real primary commits on disposable SQLite; only provider HTTP transport is fake. @return array<string,mixed> */
function checkoutRecoveryProbe(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir().'/kitsune-checkout-recovery-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    touch($directory.'/writer.sqlite');
    try {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/CheckoutRecoveryProbe.php', $root, $directory, $scenario], $root);
        $process->setTimeout(60);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['level'])->toBe(0)->and($result['pdo'])->toBeFalse()->and($result['safeFailure'])->toBeTrue()
            ->and($result['linesUnchanged'])->toBeTrue()->and($result['credentialsUnchanged'])->toBeTrue()->and($result['modeUnchanged'])->toBeTrue()
            ->and($result['witnessUnchanged'])->toBeTrue()->and($result['requestUnchanged'])->toBeTrue()->and($result['resultHasNoUrl'])->toBeTrue()
            ->and($result['stock'])->toBe(in_array('completed', $result['states'], true) ? '3' : '5')->and($result['grants'])->toBe(0)
            ->and($result['confirmed'] === null ? null : (string) $result['confirmed'])->toBe(in_array('completed', $result['states'], true) ? '4800' : null)
            ->and($result['paymentReceipts'])->toBe(in_array('completed', $result['states'], true) ? 1 : 0)
            ->and($result['orderState'])->toBe(in_array('completed', $result['states'], true) ? 'paid' : 'prepared');
        foreach ($result['calls'] as $call) {
            expect($call['level'])->toBe(0)->and($call['pdo'])->toBeFalse()->and($call['version'])->toBe(StripeCheckout::API_VERSION)
                ->and($call['method'])->toBe('GET');
        }

        return $result;
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
}

it('explicitly recovers the same original test account after a mode or key change without replacing any credential witness', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['states'])->toBe(['completed'])->and($result['failure'])->toBeNull()->and($result['calls'])->toHaveCount(2)
        ->and($result['sessionId'])->toBe('cs_test_FixtureA')->and($result['active'])->toBeFalse()->and($result['orderHistoryUnchanged'])->toBeTrue()
        ->and($result['audits'])->toBe(['commerce.checkout_owner_recovered']);
})->with(['historical-mode', 'historical-key', 'missing-id', 'missing-id-receipt', 'expired-receipt', 'historical-receipt']);

it('refuses to choose between two verified receipts for different Sessions on an unresolved attempt', function (): void {
    $result = checkoutRecoveryProbe('ambiguous-receipt');
    expect($result['states'])->toBe([])->and($result['failure'])->toBe('changed')->and($result['calls'])->toBe([])
        ->and($result['state'])->toBe('uncertain')->and($result['active'])->toBeTrue()->and($result['audits'])->toBe([])
        ->and($result['orderHistoryUnchanged'])->toBeTrue();
});

it('requires a current owner and fresh local password before any historical provider call', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['failure'])->not->toBeNull()->and($result['calls'])->toBe([])->and($result['state'])->toBe('open')
        ->and($result['active'])->toBeTrue()->and($result['audits'])->toBe([])->and($result['orderHistoryUnchanged'])->toBeTrue();
})->with(['no-owner', 'member', 'wrong-password', 'cross-site', 'cross-org', 'busy', 'wrong-api', 'module-stale', 'module-disabled', 'live-key']);

it('keeps automated reconciliation behind the original current-mode and credential witness', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['failure'])->not->toBeNull()->and($result['calls'])->toBe([])->and($result['state'])->toBe('open')
        ->and($result['audits'])->toBe([])->and($result['orderHistoryUnchanged'])->toBeTrue();
})->with(['system-mode', 'system-key', 'system-credential-hook-order', 'system-credential-hook-attempt']);

it('requires exact original account session money references expiry and test mode', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['failure'])->not->toBeNull()->and($result['calls'])->toHaveCount($scenario === 'wrong-account' ? 1 : 2)
        ->and($result['state'])->toBe('open')->and($result['active'])->toBeTrue()->and($result['audits'])->toBe([])
        ->and($result['orderHistoryUnchanged'])->toBeTrue();
})->with(['wrong-account', 'wrong-amount', 'wrong-expiry', 'wrong-metadata', 'wrong-order', 'wrong-reference', 'wrong-mode', 'transport']);

it('rechecks owner authority and the immutable graph after provider I/O', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['failure'])->not->toBeNull()->and($result['calls'])->toHaveCount($scenario === 'revoke-after-account' ? 1 : 2)->and($result['state'])->toBe('open')
        ->and($result['active'])->toBeTrue()->and($result['audits'])->toBe([]);
})->with(['revoke-owner', 'revoke-after-account', 'password-during-io', 'order-during-io']);

it('rolls back cancelled misfiled or graph-mutating recovery audits', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['failure'])->not->toBeNull()->and($result['calls'])->toHaveCount(2)->and($result['state'])->toBe('open')
        ->and($result['active'])->toBeTrue()->and($result['audits'])->toBe([])->and($result['orderHistoryUnchanged'])->toBeTrue();
})->with(['audit-cancel', 'audit-misfile', 'audit-attempt', 'audit-order']);

it('reconciles a verified event once and keeps duplicate terminal events idempotent', function (): void {
    $result = checkoutRecoveryProbe('system-complete');
    expect($result['states'])->toBe(['completed', 'completed'])->and($result['calls'])->toHaveCount(2)
        ->and($result['audits'])->toBe(['commerce.checkout_reconciled'])->and($result['active'])->toBeFalse()
        ->and($result['orderHistoryUnchanged'])->toBeTrue();
});

it('finishes local terminal receipt bookkeeping after explicit recovery with a changed credential without another provider call', function (string $scenario, string $state): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['states'])->toBe([$state, $state])->and($result['failure'])->toBeNull()->and($result['calls'])->toHaveCount(2)
        ->and($result['audits'])->toBe(['commerce.checkout_owner_recovered'])->and($result['active'])->toBeFalse()
        ->and($result['orderHistoryUnchanged'])->toBeTrue();
})->with(['completed' => ['historical-terminal-completed', 'completed'], 'expired' => ['historical-terminal-expired', 'expired']]);

it('still refuses terminal bookkeeping for a different Session scope or API version', function (string $scenario): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['states'])->toBe(['completed'])->and($result['failure'])->not->toBeNull()->and($result['calls'])->toHaveCount(2)
        ->and($result['audits'])->toBe(['commerce.checkout_owner_recovered'])->and($result['active'])->toBeFalse()
        ->and($result['orderHistoryUnchanged'])->toBeTrue();
})->with(['historical-terminal-id', 'historical-terminal-site', 'historical-terminal-org', 'historical-terminal-api']);

it('records expiry while keeping unconfirmed completion unresolved and fences concurrent processing', function (string $scenario, string $state): void {
    $result = checkoutRecoveryProbe($scenario);
    expect($result['states'])->toBe([$state])->and($result['state'])->toBe($state)->and($result['orderHistoryUnchanged'])->toBeTrue();
    if ($scenario === 'nested-system') {
        expect($result['nestedRefusal'])->toBe('busy');
    }
})->with(['expiry' => ['expired', 'expired'], 'unpaid completion' => ['unpaid', 'uncertain'], 'concurrent' => ['nested-system', 'completed']]);
