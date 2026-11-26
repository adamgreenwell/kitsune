<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('gives only the host package subprocess the normal file mask', function (bool $failUpdate): void {
    $installer = dirname(__DIR__, 3).'/deploy/self-host/native/install.sh';
    $script = <<<'BASH'
        source "$1"

        apt-get() {
            printf '%s %s %s\n' "$(umask)" "${DEBIAN_FRONTEND:-unset}" "$*"
            if [[ "$1" = update && "$FAIL_UPDATE" = 1 ]]; then return 23; fi
        }

        install_host_packages php-fpm nginx 9>&-
        printf 'caller %s\n' "$(umask)"
        BASH;

    $run = new Process(['bash', '-c', $script, 'native-package-probe', $installer], env: [
        'DEBIAN_FRONTEND' => false,
        'FAIL_UPDATE' => $failUpdate ? '1' : '0',
    ]);
    $run->run();

    if ($failUpdate) {
        expect($run->getExitCode())->toBe(23)
            ->and(trim($run->getOutput()))->toBe('0022 unset update');
    } else {
        expect($run->isSuccessful())->toBeTrue()
            ->and(explode("\n", trim($run->getOutput())))->toBe([
                '0022 unset update',
                '0022 noninteractive install -y --no-install-recommends php-fpm nginx',
                'caller 0077',
            ]);
    }
})->with([false, true]);
