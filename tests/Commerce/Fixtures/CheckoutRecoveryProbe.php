<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\CheckoutReconciler;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\StripeCheckout;
use Kitsune\Commerce\Payments\StripeEventInbox;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Credentials\Secret;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Psr\Http\Message\RequestInterface;

// Reuse #197's real level-zero setup and provider protocol fixture, then independently exercise the new recovery path.
$recoveryScenario = $argv[3];
$argv[3] = 'duplicate';
ob_start();
require __DIR__.'/CheckoutWriterProbe.php';
ob_end_clean();
$beforeOrder = (array) DB::table('commerce_orders')->where('id', $order->getKey())->first();
$beforeLines = DB::table('commerce_order_lines')->where('order_id', $order->getKey())->orderBy('id')->get()->all();
$ownerPath = ! str_starts_with($recoveryScenario, 'system-');
$password = 'a private fresh owner password';
$owner = null;
if ($ownerPath && $recoveryScenario !== 'no-owner') {
    $owner = $recoveryScenario === 'member' ? Fx::member('recovery-member@example.test') : Fx::owner('recovery-owner@example.test');
    DB::table('users')->where('id', $owner->getKey())->update(['password' => Hash::make($password)]);
}
$knownId = 'cs_test_FixtureA';
if (in_array($recoveryScenario, ['missing-id', 'missing-id-receipt', 'ambiguous-receipt', 'expired-receipt', 'historical-receipt'], true)) {
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['session_id' => null, 'state' => 'uncertain', 'checkout_url' => null]);
}
if (in_array($recoveryScenario, ['missing-id-receipt', 'ambiguous-receipt', 'expired-receipt', 'historical-receipt'], true)) {
    $event = ['id' => 'evt_RecoveryReceipt', 'type' => 'checkout.session.completed', 'apiVersion' => StripeCheckout::API_VERSION,
        'sessionId' => $knownId, 'orderReference' => $order->reference, 'attemptReference' => $attempt->reference, 'test' => true];
    $inbox = app(StripeEventInbox::class);
    $inbox->capture((int) $org->getKey(), $event, '{"verified_fixture":true}', CarbonImmutable::now('UTC'));
    if ($recoveryScenario === 'ambiguous-receipt') {
        $inbox->capture((int) $org->getKey(), [...$event, 'id' => 'evt_RecoveryAmbiguous', 'sessionId' => 'cs_test_Other'],
            '{"verified_fixture":2}', CarbonImmutable::now('UTC'));
    }
    if ($recoveryScenario === 'expired-receipt') {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDays(8));
        DB::table(StripeEventInbox::TABLE)->update(['raw_payload' => null]);
    }
}
if (in_array($recoveryScenario, ['historical-mode', 'system-mode', 'historical-receipt'], true)) {
    DB::table('org_credential_modes')->updateOrInsert(['org_id' => $org->getKey()], ['mode' => 'live', 'changed_at' => '2026-10-15 12:00:00']);
}
if (in_array($recoveryScenario, ['historical-key', 'system-key'], true) || str_starts_with($recoveryScenario, 'historical-terminal-')) {
    Fx::nobody();
    CredentialConsoleAuthority::run(app(Context::class), 'set', StripeCheckout::SLOT, CredentialMode::Test, null,
        static fn (CredentialConsoleAuthority $authority) => app(CredentialWriter::class)->set(StripeCheckout::SLOT, CredentialMode::Test, 'sk_test_NewFixtureCheckoutKey', console: $authority));
    if ($owner !== null) {
        Auth::guard('web')->setUser($owner);
        Fx::forget();
    }
}
if ($recoveryScenario === 'wrong-password') {
    $password = 'wrong private password';
} elseif ($recoveryScenario === 'busy') {
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['lease_token' => str_repeat('d', 64), 'lease_until' => '2026-10-14 12:01:00']);
} elseif ($recoveryScenario === 'wrong-api') {
    DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['api_version' => '2026-08-27.basil']);
} elseif ($recoveryScenario === 'module-stale') {
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['version' => '0.0.0']);
} elseif ($recoveryScenario === 'module-disabled') {
    DB::table('modules')->where('handle', 'kitsune/commerce')->update(['is_enabled' => false]);
} elseif ($recoveryScenario === 'cross-site') {
    $other = Site::create(['handle' => 'other', 'slug' => 'recovery-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);
} elseif ($recoveryScenario === 'cross-org') {
    $otherOrg = Org::create(['name' => 'Other recovery org', 'slug' => 'recovery-other-org']);
    app(Context::class)->setOrg($otherOrg);
    app(Context::class)->setSite(Site::create(['handle' => 'other', 'slug' => 'recovery-foreign', 'name' => 'Other', 'locale' => 'en']));
}
$originalAttempt = (array) DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->first();
$originalCredentials = DB::table('credentials')->orderBy('id')->get()->all();
$originalMode = DB::table('org_credential_modes')->orderBy('id')->get()->all();
if (str_starts_with($recoveryScenario, 'system-credential-hook-')) {
    Credential::retrieved(static function () use ($recoveryScenario, $order, $attempt): void {
        if ($recoveryScenario === 'system-credential-hook-order') {
            DB::table('commerce_orders')->where('id', $order->getKey())->update(['contact_email' => 'credential-hook@example.test']);
        } else {
            DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['account_id' => 'acct_Hook']);
        }
    });
}
$outbound = [];
$nestedRefusal = null;
$http = static function (RequestInterface $sent) use (&$outbound, &$nestedRefusal, $recoveryScenario, $networkSession, $owner, $order, $attempt): Response {
    $path = $sent->getUri()->getPath();
    $outbound[] = ['method' => $sent->getMethod(), 'path' => $path, 'level' => DB::connection()->transactionLevel(),
        'pdo' => DB::connection()->getPdo()->inTransaction(), 'version' => $sent->getHeaderLine('Stripe-Version')];
    if ($path === '/v1/account') {
        if ($recoveryScenario === 'revoke-after-account') {
            DB::table('role_user')->where('user_id', $owner->getKey())->delete();
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['object' => 'account',
            'id' => $recoveryScenario === 'wrong-account' ? 'acct_Other' : 'acct_FixtureA'], JSON_THROW_ON_ERROR));
    }
    if ($recoveryScenario === 'transport') {
        throw new ConnectException('a private provider exception with a secret', $sent);
    }
    if ($recoveryScenario === 'revoke-owner') {
        DB::table('role_user')->where('user_id', $owner->getKey())->delete();
    } elseif ($recoveryScenario === 'password-during-io') {
        DB::table('users')->where('id', $owner->getKey())->update(['password' => Hash::make('a different private password')]);
    } elseif ($recoveryScenario === 'order-during-io') {
        DB::table('commerce_orders')->where('id', $order->getKey())->update(['contact_email' => 'changed@example.test']);
    } elseif ($recoveryScenario === 'nested-system') {
        try {
            app(CheckoutReconciler::class)->reconcile($order->reference, $attempt->reference, 'cs_test_FixtureA');
        } catch (CheckoutRefused $failure) {
            $nestedRefusal = $failure->reason;
        }
    }
    $session = $networkSession;
    $session['status'] = 'complete';
    $session['payment_status'] = 'paid';
    $session['payment_intent'] = 'pi_Recovery';
    $session['url'] = null;
    match ($recoveryScenario) {
        'wrong-amount' => $session['amount_total'] = '4801', 'wrong-expiry' => $session['expires_at']++,
        'wrong-metadata' => $session['metadata']['kitsune_attempt'] = str_repeat('b', 32), 'wrong-mode' => $session['livemode'] = true,
        'wrong-order' => $session['metadata']['kitsune_order'] = str_repeat('c', 32),
        'wrong-reference' => $session['client_reference_id'] = str_repeat('c', 32),
        'unpaid' => $session['payment_status'] = 'unpaid',
        'expired', 'historical-terminal-expired' => [$session['status'], $session['payment_status'], $session['payment_intent']] = ['expired', 'unpaid', null],
        default => null,
    };

    return new Response(200, ['Content-Type' => 'application/json'], json_encode($session, JSON_THROW_ON_ERROR));
};
$app->instance(StripeCheckout::class, new StripeCheckout(new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 20, $http)))])));
$app->forgetInstance(CheckoutReconciler::class);
AuditLog::creating(static function (AuditLog $audit) use ($recoveryScenario, $order): ?bool {
    if (! in_array($audit->action, ['commerce.checkout_reconciled', 'commerce.checkout_owner_recovered'], true)) {
        return null;
    }
    match ($recoveryScenario) {
        'audit-misfile' => $audit->action = 'commerce.misfiled',
        'audit-attempt' => DB::table(CheckoutAttempts::TABLE)->where('id', $audit->target_id)->update(['account_id' => 'acct_Other']),
        'audit-order' => DB::table('commerce_orders')->where('id', $order->getKey())->update(['contact_email' => 'audit-changed@example.test']),
        default => null,
    };

    return $recoveryScenario === 'audit-cancel' ? false : null;
});
$states = [];
$failure = null;
$safeFailure = true;
$result = null;
try {
    $reconciler = app(CheckoutReconciler::class);
    $result = $ownerPath
        ? $reconciler->recoverAsOwner($order->reference, $attempt->reference, $recoveryScenario === 'missing-id' ? $knownId : '',
            new Secret($recoveryScenario === 'live-key' ? 'sk_live_PrivateRecoveryFixture' : 'sk_test_PrivateRecoveryFixture'), $password)
        : $reconciler->reconcile($order->reference, $attempt->reference, $knownId);
    $states[] = $result['state'];
    if ($recoveryScenario === 'system-complete') {
        $states[] = $reconciler->reconcile($order->reference, $attempt->reference, $knownId)['state'];
    }
    if (str_starts_with($recoveryScenario, 'historical-terminal-')) {
        if ($recoveryScenario === 'historical-terminal-api') {
            DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->update(['api_version' => '2026-08-27.basil']);
        } elseif ($recoveryScenario === 'historical-terminal-site') {
            app(Context::class)->setSite(Site::create(['handle' => 'terminal-other', 'slug' => 'terminal-other', 'name' => 'Other', 'locale' => 'en']));
        } elseif ($recoveryScenario === 'historical-terminal-org') {
            app(Context::class)->setOrg(Org::create(['name' => 'Foreign terminal', 'slug' => 'foreign-terminal']));
            app(Context::class)->setSite(Site::create(['handle' => 'terminal-foreign', 'slug' => 'terminal-foreign', 'name' => 'Foreign', 'locale' => 'en']));
        }
        $states[] = $reconciler->reconcile($order->reference, $attempt->reference,
            $recoveryScenario === 'historical-terminal-id' ? 'cs_test_Other' : $knownId)['state'];
    }
} catch (CheckoutRefused $refused) {
    $failure = $refused->reason;
    $safeFailure = $refused->getPrevious() === null && $refused->getMessage() === 'The test checkout is unavailable.';
}
$finalAttempt = (array) DB::table(CheckoutAttempts::TABLE)->where('id', $attempt->id)->first();
echo json_encode(['states' => $states, 'failure' => $failure, 'safeFailure' => $safeFailure, 'nestedRefusal' => $nestedRefusal,
    'resultHasNoUrl' => $result === null || ! array_key_exists('url', $result), 'state' => $finalAttempt['state'],
    'active' => $finalAttempt['active_order_id'] !== null, 'witnessUnchanged' => $finalAttempt['credential_witness'] === $originalAttempt['credential_witness'],
    'requestUnchanged' => $finalAttempt['request_payload'] === $originalAttempt['request_payload'], 'sessionId' => $finalAttempt['session_id'],
    'credentialsUnchanged' => $originalCredentials == DB::table('credentials')->orderBy('id')->get()->all(),
    'modeUnchanged' => $originalMode == DB::table('org_credential_modes')->orderBy('id')->get()->all(),
    'orderUnchanged' => $beforeOrder === (array) DB::table('commerce_orders')->where('id', $order->getKey())->first(),
    'linesUnchanged' => $beforeLines == DB::table('commerce_order_lines')->where('order_id', $order->getKey())->orderBy('id')->get()->all(),
    'stock' => (string) DB::table('commerce_sellables')->where('id', $product->getKey())->value('stock'), 'grants' => DB::table('entitlements')->count(),
    'confirmed' => DB::table('commerce_orders')->where('id', $order->getKey())->value('confirmed_total_minor'),
    'calls' => $outbound, 'level' => DB::connection()->transactionLevel(), 'pdo' => DB::connection()->getPdo()->inTransaction(),
    'audits' => DB::table('audit_log')->whereIn('action', ['commerce.checkout_reconciled', 'commerce.checkout_owner_recovered'])->pluck('action')->all()], JSON_THROW_ON_ERROR);
