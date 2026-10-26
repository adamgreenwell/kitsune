<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Tools\Documentation\DocumentationSite;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/docs/DocumentationSite.php';

try {
    $options = getopt('', ['version:', 'ref:', 'output:']);
    $site = new DocumentationSite(dirname(__DIR__));
    $result = $site->build(
        $options['output'] ?? dirname(__DIR__).'/build/docs',
        $options['version'] ?? 'development',
        $options['ref'] ?? null,
    );
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Documentation build refused: '.$error->getMessage()."\n");
    exit(1);
}
