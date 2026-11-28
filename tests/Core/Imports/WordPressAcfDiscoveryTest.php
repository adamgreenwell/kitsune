<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('reports reviewed ACF JSON and literal PHP without running the inspected files', function (): void {
    $root = sys_get_temp_dir().'/kitsune-acf-'.bin2hex(random_bytes(8));
    mkdir($root.'/acf-json', 0700, true);
    mkdir($root.'/theme', 0700);
    $json = $root.'/acf-json/fields.json';
    $php = $root.'/theme/fields.php';
    $definition = <<<'JSON'
        {"key":"group_page","fields":[{"key":"field_caption","name":"caption","type":"text","label":"{\"inside\":true}"}],"location":[[{"param":"post_type","operator":"==","value":"page"}]]}
        JSON;
    file_put_contents($json, $definition);
    file_put_contents($php, <<<'SOURCE'
        <?php
        if (function_exists('acf_add_local_field_group')) {
            acf_add_local_field_group([
                'key' => 'group_post', 'fields' => [['key' => 'field_rating', 'name' => 'rating', 'type' => 'number']],
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            ]);
        }
        throw new RuntimeException('This file must never be executed.');
        SOURCE);

    $run = static fn (): Process => new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/inspect-wordpress-acf.php', $root, 'theme/fields.php']);
    try {
        $discovery = $run();
        expect($discovery->run())->toBe(0);
        $report = json_decode($discovery->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['blocked'])->toBeFalse()
            ->and(array_column($report['groups'], 'source_key'))->toBe(['group_page', 'group_post'])
            ->and($report['inputs'])->toBe(['acf-json/fields.json' => hash_file('sha256', $json), 'theme/fields.php' => hash_file('sha256', $php)]);

        file_put_contents($json, str_replace('"label":', '"\u006cabel":"conflict","label":', $definition));
        $ambiguous = $run();
        expect($ambiguous->run())->toBe(1);
        $report = json_decode($ambiguous->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($report['blocked'])->toBeTrue()
            ->and(array_column($report['findings'], 'code'))->toContain('invalid-json-definition')
            ->and(array_column($report['groups'], 'source_key'))->toBe(['group_post']);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }
});
