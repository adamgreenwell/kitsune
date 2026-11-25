<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\Manifest;
use Kitsune\Release\SelfHostModuleUpgrade;

foreach (['Files', 'Manifest', 'SelfHostNativeRuntime', 'SelfHostModuleUpgrade'] as $releaseHelper) {
    require_once dirname(__DIR__, 3).'/bin/release/'.$releaseHelper.'.php';
}

function moduleUpgradeManifest(array $packages): Manifest
{
    $source = str_repeat('a', 40);
    $digest = str_repeat('b', 64);
    $lines = ['kitsune-release-manifest-v1', "version\tv1.0.0", "channel\ttest", "source\t$source",
        "tree\t$source", "signature\trsa-pkcs1-sha256", "artifact\tapp.tar.gz\tnative\tany\t1\t$digest"];

    ksort($packages);
    foreach ($packages as $name => [$version, $publication]) {
        $lines[] = "package\t$name\t$version\t$source\t$digest\t$publication";
    }

    return Manifest::parse(implode("\n", $lines)."\n");
}

it('plans only installed package upgrades and verifies versions, timestamps, and enabled switches', function (): void {
    $fixture = realpath(sys_get_temp_dir()).'/kitsune-modules-'.bin2hex(random_bytes(8));
    mkdir($fixture, 0700);
    $originApp = $fixture.'/origin';
    $targetApp = $fixture.'/target';
    $inventory = $targetApp.'/vendor/composer/installed.json';
    mkdir(dirname($inventory), 0700, true);
    mkdir($targetApp.'/vendor/kitsune/person', 0700, true);
    mkdir($originApp.'/vendor/kitsune/person', 0700, true);
    $database = $fixture.'/database.sqlite';
    $db = new SQLite3($database);
    try {
        $db->exec('CREATE TABLE modules (handle TEXT, version TEXT, is_enabled INTEGER, installed_at TEXT)');
        $db->exec("INSERT INTO modules VALUES ('kitsune/person', '1.0.0', 1, '2026-10-01'), ('kitsune/commerce', '1.0.0', 0, '2026-10-02')");
        $origin = moduleUpgradeManifest(['kitsune/person' => ['1.0.0', 'bundled'], 'kitsune/commerce' => ['1.0.0', 'bundled']]);
        $target = moduleUpgradeManifest(['kitsune/person' => ['1.0.0', 'bundled'], 'kitsune/commerce' => ['1.1.0', 'bundled'], 'kitsune/core' => ['1.1.0', 'bundled']]);
        $writeInventory = static function (string $commerceVersion) use ($inventory): void {
            file_put_contents($inventory, json_encode(['packages' => [
                ['name' => 'kitsune/person', 'version' => '1.0.0'],
                ['name' => 'kitsune/commerce', 'version' => $commerceVersion],
                ['name' => 'kitsune/core', 'version' => '1.1.0'],
            ]], JSON_THROW_ON_ERROR));
        };
        $writeInventory('dev-main');
        expect(fn () => SelfHostModuleUpgrade::plan($database, $origin, $target, $originApp, $targetApp))
            ->toThrow(RuntimeException::class, 'staged Composer package inventory');

        $writeInventory('1.1.0');
        $excluded = moduleUpgradeManifest(['kitsune/person' => ['1.0.0', 'bundled'], 'kitsune/commerce' => ['1.1.0', 'excluded']]);
        expect(fn () => SelfHostModuleUpgrade::plan($database, $origin, $excluded, $originApp, $targetApp))
            ->toThrow(RuntimeException::class, 'staged Composer package inventory');

        $changedFile = $targetApp.'/vendor/kitsune/person/changed.php';
        file_put_contents($changedFile, 'changed package');
        expect(fn () => SelfHostModuleUpgrade::plan($database, $origin, $target, $originApp, $targetApp))
            ->toThrow(RuntimeException::class, 'changed package files');
        unlink($changedFile);

        $writeInventory('0.9.0');
        $downgrade = moduleUpgradeManifest(['kitsune/person' => ['1.0.0', 'bundled'], 'kitsune/commerce' => ['0.9.0', 'bundled']]);
        expect(fn () => SelfHostModuleUpgrade::plan($database, $origin, $downgrade, $originApp, $targetApp))
            ->toThrow(RuntimeException::class, 'forward version transition');

        $writeInventory('1.1.0');
        $plan = SelfHostModuleUpgrade::plan($database, $origin, $target, $originApp, $targetApp);
        expect($plan['upgrades'])->toBe(['kitsune/commerce']);
        $db->exec("UPDATE modules SET version = '1.1.0' WHERE handle = 'kitsune/commerce'");
        SelfHostModuleUpgrade::verify($database, $plan['expected']);
        $db->exec("UPDATE modules SET is_enabled = 1 WHERE handle = 'kitsune/commerce'");
        expect(fn () => SelfHostModuleUpgrade::verify($database, $plan['expected']))
            ->toThrow(RuntimeException::class, 'receipt readback');

        $writeInventory('1.1.0');
        expect(fn () => SelfHostModuleUpgrade::plan($database, $origin, $target, $originApp, $targetApp))
            ->toThrow(RuntimeException::class, 'both signed release manifests');
    } finally {
        $db->close();
        unlink($database);
        unlink($inventory);
        $directories = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($directories as $directory) {
            rmdir($directory->getPathname());
        }
        rmdir($fixture);
    }
});
