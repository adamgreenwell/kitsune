<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests;

use App\Providers\AppServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reader accounts as the skeleton ships them (ADR-037, as built) — its own `App\Models\Reader`, its own guard
 * declaration (`AppServiceProvider::readerConfig()`), its own `readers` migration (every host here has it), and its own
 * `routes/web.php`, so the one-line wiring and the catch-all after it are the skeleton's, not a copy.
 *
 * ⚠️ bcrypt AT COST 4. A sign-in hashes once on every path, and the suite signs in hundreds of times; the timing tests
 * that care about the cost set their own.
 */
abstract class ReaderTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set(AppServiceProvider::readerConfig());
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        // Mail kept in memory, so a test reads what was sent; `fault()` allows it only because this is `testing`.
        $app['config']->set('mail.default', 'array');
        // The session's cookie is encrypted, and the binding and the throttles' keys are HMACs under it.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /** @param  Router  $router */
    protected function defineWebRoutes($router): void
    {
        require __DIR__.'/../../skeleton/routes/web.php';
    }

    /**
     * A GET as a new request makes it: nothing in context, no guard built, the default guard `web` again.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function readerGet(string $path, array $headers = []): TestResponse
    {
        ReaderFixture::forget();

        return $this->get($path, $headers);
    }

    /**
     * A form's POST: the session's token in the body, as `@csrf` puts it, unless the test says otherwise.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function readerPost(string $path, array $data = [], bool $token = true, array $headers = []): TestResponse
    {
        ReaderFixture::forget();

        return $this->post($path, ($token ? ['_token' => ReaderFixture::token()] : []) + $data, $headers);
    }
}
