<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

function bootstrapOpenSslAdapter(): string
{
    return <<<'PHP'
    <?php

    /*
     * This Source Code Form is subject to the terms of the Mozilla Public
     * License, v. 2.0. If a copy of the MPL was not distributed with this
     * file, You can obtain one at https://mozilla.org/MPL/2.0/.
     */

    declare(strict_types=1);

    $a = array_slice($argv, 1);
    if (count($a) === 6 && array_slice($a, 0, 3) === ['rsa', '-pubin', '-in'] && array_slice($a, 4) === ['-text', '-noout']) {
        $key = openssl_pkey_get_public(file_get_contents($a[3]));
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA) { exit(1); }
        echo 'Public-Key: ('.$details['bits'].' bit)', "\n";
        exit(0);
    }
    if (count($a) === 6 && array_slice($a, 0, 3) === ['pkey', '-pubin', '-in'] && array_slice($a, 4) === ['-outform', 'DER']) {
        $key = openssl_pkey_get_public(file_get_contents($a[3]));
        $pem = openssl_pkey_get_details($key)['key'];
        echo base64_decode(implode('', array_slice(explode("\n", trim($pem)), 1, -1)), true);
        exit(0);
    }
    if ($a === ['dgst', '-sha256', '-r']) {
        echo hash('sha256', stream_get_contents(STDIN)), ' *stdin', "\n";
        exit(0);
    }
    if (count($a) === 9 && array_slice($a, 0, 5) === ['dgst', '-sha256', '-sigopt', 'rsa_padding_mode:pkcs1', '-verify'] && $a[6] === '-signature') {
        $valid = openssl_verify(file_get_contents($a[8]), file_get_contents($a[7]), file_get_contents($a[5]), OPENSSL_ALGO_SHA256) === 1;
        echo $valid ? "Verified OK\n" : "Verification failure\n";
        exit($valid ? 0 : 1);
    }
    fwrite(STDERR, "Unexpected crypto command in bootstrap test\n");
    exit(96);
    PHP;
}
