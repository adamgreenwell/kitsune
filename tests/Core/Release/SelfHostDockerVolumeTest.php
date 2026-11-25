<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Kitsune\Release\SelfHostDockerVolume;

beforeEach(function (): void {
    foreach (['Manifest', 'Files', 'SelfHostDockerVolume'] as $helper) {
        require_once dirname(__DIR__, 3).'/bin/release/'.$helper.'.php';
    }
    $this->volume = realpath(sys_get_temp_dir()).'/kitsune-docker-fence-'.bin2hex(random_bytes(10));
    mkdir($this->volume, 0700);
    touch($this->volume.'/.lock');
    $this->origin = str_repeat('a', 40)."\n";
    $this->target = "kitsune-docker-installed-v2\nv1.2.3\n".str_repeat('b', 40)."\n"
        .str_repeat('c', 64)."\n".str_repeat('d', 64)."\n";
    file_put_contents($this->volume.'/.installed', $this->origin);
    $this->plan = str_repeat('e', 64);
});

afterEach(function (): void {
    File::deleteDirectory($this->volume);
});

it('uses the existing runtime lock and does not allow a retained object to change the volume after unlock', function (): void {
    $runtime = fopen($this->volume.'/.lock', 'r+b');
    expect(flock($runtime, LOCK_EX | LOCK_NB))->toBeTrue();
    try {
        expect(fn () => SelfHostDockerVolume::locked($this->volume, fn () => $this->fail('Volume remained owned.')))
            ->toThrow(RuntimeException::class, 'holds the Docker volume');
    } finally {
        fclose($runtime);
    }

    $released = SelfHostDockerVolume::locked($this->volume, fn ($volume) => $volume);
    expect(fn () => $released->fence($this->plan, $this->origin))->toThrow(RuntimeException::class, 'no longer held')
        ->and(file_get_contents($this->volume.'/.installed'))->toBe($this->origin);
});

it('publishes only over the exact prior receipt and preserves the plan fence after losing the lock', function (): void {
    SelfHostDockerVolume::locked($this->volume, function (SelfHostDockerVolume $volume): void {
        expect(fn () => $volume->fence($this->plan, $this->target))->toThrow(RuntimeException::class, 'no longer matches')
            ->and(file_get_contents($this->volume.'/.installed'))->toBe($this->origin);
        $volume->fence($this->plan, $this->origin);
    });

    expect(file_get_contents($this->volume.'/.installed'))->toBe("kitsune-docker-upgrade-v1\n{$this->plan}\n");
    SelfHostDockerVolume::locked($this->volume, function (SelfHostDockerVolume $volume): void {
        expect(fn () => $volume->publish(str_repeat('f', 64), $this->target))->toThrow(RuntimeException::class, 'no longer matches');
        $volume->publish($this->plan, $this->target);
        expect(fn () => $volume->publish($this->plan, $this->target))->toThrow(RuntimeException::class, 'no longer matches');
    });
    expect(file_get_contents($this->volume.'/.installed'))->toBe($this->target);
});
