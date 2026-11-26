<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\SelfHostNativeRuntime;

beforeEach(function (): void {
    require_once dirname(__DIR__, 3).'/bin/release/SelfHostNativeRuntime.php';
    $this->root = realpath(sys_get_temp_dir()).'/kitsune-native-witness-'.bin2hex(random_bytes(10));
    $this->applicationPath = $this->root.'/app';
    $this->state = $this->root.'/state';
    foreach (['app/public', 'app/vendor', 'app/bootstrap/cache', 'state/storage/app/public'] as $path) {
        mkdir($this->root.'/'.$path, 0700, true);
    }

    foreach (['public/index.php', 'artisan', 'vendor/autoload.php', 'composer.lock'] as $file) {
        file_put_contents($this->applicationPath.'/'.$file, '');
    }
    foreach (['.env' => '.env', 'storage' => 'storage', 'public/storage' => 'storage/app/public'] as $link => $target) {
        symlink($this->state.'/'.$target, $this->applicationPath.'/'.$link);
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('binds native code files and their modes while ignoring the expected persisted data and generated caches', function (): void {
    $before = SelfHostNativeRuntime::fingerprint($this->applicationPath, $this->state);
    file_put_contents($this->state.'/.env', 'synthetic keys');
    file_put_contents($this->state.'/storage/app/public/media.png', 'data');
    file_put_contents($this->applicationPath.'/bootstrap/cache/config.php', 'generated cache');
    expect(SelfHostNativeRuntime::fingerprint($this->applicationPath, $this->state))->toBe($before);

    file_put_contents($this->applicationPath.'/public/index.php', 'custom host code');
    expect(SelfHostNativeRuntime::fingerprint($this->applicationPath, $this->state))->not->toBe($before);
    file_put_contents($this->applicationPath.'/public/index.php', '');
    chmod($this->applicationPath.'/artisan', 0700);
    expect(SelfHostNativeRuntime::fingerprint($this->applicationPath, $this->state))->not->toBe($before);
});

it('refuses a native runtime with unexpected links or a changed state target', function (): void {
    $extra = $this->applicationPath.'/unexpected';
    symlink($this->state, $extra);
    expect(fn () => SelfHostNativeRuntime::fingerprint($this->applicationPath, $this->state))
        ->toThrow(InvalidArgumentException::class, 'unexpected link');
    unlink($extra);
    unlink($this->applicationPath.'/storage');
    symlink($this->root, $this->applicationPath.'/storage');
    expect(fn () => SelfHostNativeRuntime::fingerprint($this->applicationPath, $this->state))
        ->toThrow(InvalidArgumentException::class, 'state links do not match');
});
