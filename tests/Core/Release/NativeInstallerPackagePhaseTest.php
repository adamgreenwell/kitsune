<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\SelfHostUpgradeJournal;
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

it('leaves interrupted native upgrades for recovery before the installer changes anything', function (string $scenario): void {
    $repository = dirname(__DIR__, 3);
    require_once $repository.'/bin/release/SelfHostUpgradeJournal.php';

    $directory = realpath(sys_get_temp_dir()).'/kitsune-installer-journal-'.bin2hex(random_bytes(8));
    $record = $directory.'/upgrades.jsonl';
    $journal = null;

    try {
        if ($scenario !== 'absent') {
            mkdir($directory, 0700);
        }

        if (in_array($scenario, ['active', 'terminal', 'locked'], true)) {
            $journal = SelfHostUpgradeJournal::open($directory);
            $plan = str_repeat('a', 64);
            $journal->begin($plan);
            if ($scenario === 'terminal') {
                $journal->record($plan, SelfHostUpgradeJournal::ABORTED);
            }
            if ($scenario !== 'locked') {
                $journal->close();
            }
        } elseif ($scenario === 'invalid') {
            file_put_contents($record, '{incomplete');
            chmod($record, 0600);
        }

        $bytes = is_file($record) ? file_get_contents($record) : null;
        $script = <<<'BASH'
            source "$1/deploy/self-host/native/install.sh"
            require_install_journal_available "$2" "$3" "$1"
            printf 'installer may continue\n'
            BASH;
        $run = new Process(['bash', '-c', $script, 'native-journal-probe', $repository, $directory, PHP_BINARY]);
        $run->run();

        if (in_array($scenario, ['absent', 'terminal'], true)) {
            expect($run->isSuccessful())->toBeTrue()
                ->and(trim($run->getOutput()))->toBe('installer may continue');
        } else {
            expect($run->getExitCode())->toBe(1)
                ->and($run->getOutput())->toBe('')
                ->and($run->getErrorOutput())->toContain('resolve the native upgrade journal');
        }
        expect(is_file($record) ? file_get_contents($record) : null)->toBe($bytes);
    } finally {
        $journal?->close();
        if (is_file($record)) {
            unlink($record);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
})->with(['absent', 'active', 'terminal', 'locked', 'missing record', 'invalid']);
