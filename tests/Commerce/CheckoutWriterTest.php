<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Actual level-zero commits on disposable SQLite; no RefreshDatabase wrapper or provider traffic. @return array<string,mixed> */
function checkoutWriterProbe(string $scenario): array
{
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-checkout-writer-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    touch($temporary.'/writer.sqlite');
    try {
        $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/CheckoutWriterProbe.php', $root, $temporary, $scenario], $root);
        $process->setTimeout(60);
        $process->mustRun();
        try {
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The checkout subprocess did not return its evidence: '.$process->getOutput(), previous: $failure);
        }
        expect($result['allOutside'])->toBeTrue()->and($result['allPinned'])->toBeTrue()
            ->and($result['level'])->toBe(0)->and($result['pdoTransaction'])->toBeFalse()
            ->and($result['orderUnchanged'])->toBeTrue()->and($result['linesUnchanged'])->toBeTrue()
            ->and($result['stock'])->toBe('5')->and($result['grants'])->toBe(0)->and($result['confirmed'])->toBeNull();

        return $result;
    } finally {
        (new Filesystem)->deleteDirectory($temporary);
    }
}

it('commits one hosted attempt and reuses it for a duplicate submit without another create or renewed review', function (): void {
    $result = checkoutWriterProbe('duplicate');
    expect($result['states'])->toBe(['open', 'open'])->and($result['attempts'])->toBe(1)
        ->and($result['creates'])->toBe(1)->and($result['gets'])->toBe(1)->and($result['active'])->toBe(1)
        ->and($result['audits'])->toBe(['commerce.checkout_started', 'commerce.checkout_open', 'commerce.checkout_open']);
});

it('keeps the committed lease exclusive while the first provider create is in flight', function (): void {
    $result = checkoutWriterProbe('lease-busy');
    expect($result['states'])->toBe(['open'])->and($result['nestedRefusals'])->toBe(['busy'])
        ->and($result['attempts'])->toBe(1)->and($result['creates'])->toBe(1);
});

it('persists an approved long or query-bearing hosted URL without losing the mapped Session', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe(['open'])->and($result['storedUrlMatches'])->toBeTrue()
        ->and($result['storedUrlLength'])->toBeGreaterThan(2048)->and($result['storedUrlLength'])->toBeLessThanOrEqual(4096)
        ->and($result['attempts'])->toBe(1)->and($result['creates'])->toBe(1);
})->with(['long-url', 'query-url']);

it('rechecks price after account discovery before it commits a provider-create request', function (): void {
    $result = checkoutWriterProbe('price-during-account');
    expect($result['states'])->toBe([])->and($result['refusals'])->toHaveCount(1)
        ->and($result['attempts'])->toBe(0)->and($result['creates'])->toBe(0)->and($result['network'])->toBe(1);
});

it('keeps the attempt unresolved when the real credential writer replaces a key during provider I/O', function (): void {
    $result = checkoutWriterProbe('credential-during-io');
    expect($result['states'])->toBe([])->and($result['refusals'])->toHaveCount(1)
        ->and($result['retainedStates'])->toBe(['creating'])->and($result['active'])->toBe(1)
        ->and($result['creates'])->toBe(1);
});

it('retries a timed-out create or rolled-back local response transaction with the exact durable payload and key', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['firstState'])->toBe($scenario === 'timeout' ? 'uncertain' : 'creating')
        ->and($result['states'])->toBe($scenario === 'timeout' ? ['uncertain', 'open'] : ['open'])
        ->and($result['creates'])->toBe(2)->and($result['sameRequests'])->toBeTrue()
        ->and($result['attempts'])->toBe(1)->and($result['retainedStates'])->toBe(['open'])
        ->and($result['active'])->toBe(1);
})->with(['timeout', 'local-commit']);

it('retrieves and explicitly expires the same mapped remote Session without purchasing anything', function (): void {
    $result = checkoutWriterProbe('expire');
    expect($result['states'])->toBe(['open', 'expired'])->and($result['gets'])->toBe(1)
        ->and($result['expires'])->toBe(1)->and($result['creates'])->toBe(1)
        ->and($result['retainedStates'])->toBe(['expired'])->and($result['active'])->toBe(0);
});

it('keeps a completed test Session separate from paid order confirmation and never creates a replacement', function (): void {
    $result = checkoutWriterProbe('completed');
    expect($result['states'])->toBe(['completed', 'completed'])->and($result['creates'])->toBe(1)
        ->and($result['retainedStates'])->toBe(['completed'])->and($result['active'])->toBe(0)
        ->and($result['providerAfterSecond'])->toBe(0);
});

it('does not grant a different browser control through known public order and attempt references', function (): void {
    $result = checkoutWriterProbe('other-browser');
    expect($result['states'])->toBe(['open'])->and($result['refusals'])->toHaveCount(1)
        ->and($result['providerAfterSecond'])->toBe(0)->and($result['active'])->toBe(1);
});

it('distinguishes a first proven nonexecution from a later rejection after an uncertain create', function (): void {
    $rejected = checkoutWriterProbe('rejected');
    expect($rejected['states'])->toBe(['rejected', 'open'])->and($rejected['retainedStates'])->toBe(['rejected', 'open'])
        ->and($rejected['attempts'])->toBe(2)->and($rejected['newRequestAfterRejection'])->toBeTrue()
        ->and($rejected['active'])->toBe(1);
    $unknown = checkoutWriterProbe('retry-rejected');
    expect($unknown['states'])->toBe(['uncertain', 'uncertain'])->and($unknown['retainedStates'])->toBe(['uncertain'])
        ->and($unknown['attempts'])->toBe(1)->and($unknown['sameRequests'])->toBeTrue()->and($unknown['active'])->toBe(1);
});

it('keeps free lines in an exact positive checkout without dropping tax or paid shipping', function (string $scenario, array $amounts, string $total, int $shippingCalls): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe(['open'])->and($result['refusals'])->toBe([])
        ->and($result['outboundAmounts'])->toBe($amounts)->and($result['outboundQuantities'])->toBe(['1', '1'])
        ->and($result['quotedTotal'])->toBe($total)->and($result['shippingCalls'])->toBe($shippingCalls)
        ->and($result['shippingOutside'])->toBeTrue()->and($result['creates'])->toBe(1)->and($result['active'])->toBe(1);
})->with([
    'mixed free and paid' => ['mixed-free-paid', ['4800', '0'], '4800', 0],
    'free physical product with paid taxed shipping' => ['free-paid-shipping', ['0', '600'], '600', 2],
]);

it('preserves a small positive reviewed total when Stripe refuses its minimum without holding a never-created Session', function (): void {
    $result = checkoutWriterProbe('below-minimum');
    expect($result['states'])->toBe(['rejected'])->and($result['refusals'])->toBe([])
        ->and($result['outboundAmounts'])->toBe(['5'])->and($result['outboundQuantities'])->toBe(['1'])
        ->and($result['quotedTotal'])->toBe('5')->and($result['retainedStates'])->toBe(['rejected'])
        ->and($result['attempts'])->toBe(1)->and($result['creates'])->toBe(1)->and($result['active'])->toBe(0);
});

it('retains uncertainty for mismatched provider identity mode money expiry and unconfirmed completion', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe(['uncertain'])->and($result['retainedStates'])->toBe(['uncertain'])
        ->and($result['attempts'])->toBe(1)->and($result['active'])->toBe(1)
        ->and($result['creates'])->toBe(1);
})->with(['wrong-metadata', 'wrong-order', 'wrong-reference', 'wrong-mode', 'wrong-amount', 'wrong-currency',
    'wrong-expiry', 'no-payment-required', 'complete-unpaid', 'paid-without-intent']);

it('refuses to use a changed credential account or API-version witness for an existing attempt', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe($scenario === 'wrong-account' ? ['open', 'uncertain'] : ['open'])
        ->and($result['refusals'])->toHaveCount($scenario === 'wrong-account' ? 0 : 1)
        ->and($result['creates'])->toBe(1)->and($result['gets'])->toBe(0)->and($result['active'])->toBe(1);
})->with(['wrong-witness', 'wrong-account', 'misfiled-account', 'wrong-api-version']);

it('requires an explicit signed-in owner to reconcile a lost known Session after the automatic retry window', function (string $scenario, bool $owner): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['firstState'])->toBe('uncertain')->and($result['creates'])->toBe(1)
        ->and($result['states'])->toBe($owner ? ['uncertain', 'expired'] : ['uncertain'])
        ->and($result['gets'])->toBe($owner ? 1 : 0)->and($result['active'])->toBe($owner ? 0 : 1);
})->with(['owner' => ['owner-known', true], 'member' => ['owner-member', false], 'no actor' => ['owner-none', false]]);

it('refuses a missing unreadable live-mode or zero-total checkout and an ambient host transaction before provider traffic', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe([])->and($result['refusals'])->toHaveCount(1)
        ->and($result['attempts'])->toBe(0)->and($result['network'])->toBe(0);
})->with(['missing-key', 'unreadable-key', 'live-mode', 'zero', 'ambient']);

it('rolls back cancelled misfiled or transplanted start audits before any Session create', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe([])->and($result['refusals'])->toHaveCount(1)
        ->and($result['attempts'])->toBe(0)->and($result['creates'])->toBe(0)->and($result['audits'])->toBe([]);
})->with(['audit-start-cancel', 'audit-start-misfile', 'audit-start-row']);

it('keeps the original unresolved attempt after a response audit mutates custody order history or scope', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe([])->and($result['refusals'])->toHaveCount(1)
        ->and($result['attempts'])->toBe(1)->and($result['retainedStates'])->toBe(['creating'])
        ->and($result['active'])->toBe(1)->and($result['creates'])->toBe(1)
        ->and($result['audits'])->toBe(['commerce.checkout_started']);
})->with(['audit-open-row', 'audit-open-order', 'audit-open-context']);

it('refuses cancellation when its audit changes a valid terminal checkout state or provider account', function (string $scenario): void {
    $result = checkoutWriterProbe($scenario);
    expect($result['states'])->toBe(['open', 'expired'])->and($result['refusals'])->toHaveCount(1)
        ->and($result['retainedStates'])->toBe(['expired'])->and($result['attemptsUnchangedAfterCancellation'])->toBeTrue()
        ->and($result['cancelAudits'])->toBe(0);
})->with(['cancel-attempt-state', 'cancel-attempt-account']);

it('serializes two independent SQLite processes that finish account discovery before claiming the same order', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir().'/kitsune-checkout-race-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    touch($temporary.'/writer.sqlite');
    $workers = [];
    try {
        $make = static function (string $role) use ($root, $temporary): Process {
            $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/CheckoutWriterProbe.php', $root, $temporary, 'race', $role], $root);
            $process->setTimeout(60);

            return $process;
        };
        $init = $make('init');
        $init->mustRun();
        expect(json_decode($init->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['initialized' => true, 'level' => 0, 'pdo' => false]);
        foreach (['A', 'B'] as $role) {
            $workers[$role] = $make($role);
            $workers[$role]->start();
        }
        $deadline = microtime(true) + 15;
        while (! is_file($temporary.'/account-A.json') || ! is_file($temporary.'/account-B.json')) {
            foreach ($workers as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Checkout race worker exited before the barrier: '.$process->getOutput().$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Checkout race workers did not reach account discovery.');
            }
            usleep(5000);
        }
        $a = json_decode((string) file_get_contents($temporary.'/account-A.json'), true, flags: JSON_THROW_ON_ERROR);
        $b = json_decode((string) file_get_contents($temporary.'/account-B.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($a['pid'])->not->toBe($b['pid'])->and($a['level'])->toBe(0)->and($b['level'])->toBe(0)
            ->and($a['pdo'])->toBeFalse()->and($b['pdo'])->toBeFalse();
        touch($temporary.'/release-A');
        touch($temporary.'/release-B');
        $results = [];
        foreach ($workers as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['allOutside'])->toBeTrue()->and($result['allPinned'])->toBeTrue()
                ->and($result['level'])->toBe(0)->and($result['pdoTransaction'])->toBeFalse()
                ->and($result['orderUnchanged'])->toBeTrue()->and($result['linesUnchanged'])->toBeTrue();
            $results[] = $result;
        }
        $identities = array_merge(...array_column($results, 'createIdentities'));
        expect(array_sum(array_column($results, 'creates')))->toBe(1)->and($identities)->toHaveCount(1);
        $database = new PDO('sqlite:'.$temporary.'/writer.sqlite');
        $attempts = $database->query('SELECT * FROM commerce_checkout_attempts')->fetchAll(PDO::FETCH_ASSOC);
        expect($attempts)->toHaveCount(1)->and($attempts[0]['state'])->toBe('open')
            ->and($attempts[0]['active_order_id'])->toBe($attempts[0]['order_id'])
            ->and($attempts[0]['session_id'])->toBe('cs_test_FixtureA')
            ->and($identities[0]['key'])->toBe('kitsune-checkout-'.$attempts[0]['org_id'].'-'.$attempts[0]['site_id'].'-'.$attempts[0]['reference'])
            ->and($database->query('SELECT stock FROM commerce_sellables')->fetchColumn())->toBe(5)
            ->and($database->query('SELECT COUNT(*) FROM entitlements')->fetchColumn())->toBe(0)
            ->and($database->query('SELECT confirmed_total_minor FROM commerce_orders')->fetchColumn())->toBeNull();
        unset($database);
    } finally {
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        (new Filesystem)->deleteDirectory($temporary);
    }
});
