<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;

/** Generate reviewable download commands. No network, publication or payload execution. */
final class Distribution
{
    /** @return array<string, mixed> */
    public static function instructions(Manifest $manifest, string $signature, string $fingerprint, string $bootstrapName, string $artifactName): array
    {
        $bootstrap = $artifact = null;
        foreach ($manifest->artifacts as $row) {
            if ($row['name'] === $bootstrapName) {
                $bootstrap = $row;
            }
            if ($row['name'] === $artifactName) {
                $artifact = $row;
            }
        }
        if ($bootstrap === null || $artifact === null || $bootstrapName === $artifactName
            || $bootstrap['role'] !== 'bootstrap' || $bootstrap['platform'] !== 'any') {
            throw new InvalidArgumentException('Select a bootstrap for any platform and a distinct payload from the authenticated manifest.');
        }
        // Generated commands execute this reviewed verifier, not an arbitrary signed shell artifact.
        $shipped = Files::digest(dirname(__DIR__).'/verify-release.sh');
        if ($bootstrap['size'] !== $shipped['size'] || ! hash_equals($bootstrap['sha256'], $shipped['sha256'])) {
            throw new InvalidArgumentException('Bootstrap must match the shipped standalone verifier bytes.');
        }
        $local = 'kitsune-bootstrap-'.$bootstrap['sha256'].'.sh';
        if (in_array($artifactName, [$local, 'release-public.pem'], true)) {
            throw new InvalidArgumentException('Selected output conflicts with an operator trust input.');
        }
        $base = 'https://kitsunecms.org/releases/'.$manifest->version.'/';
        $download = strtr(<<<'BASH'
            set -euo pipefail
            export LC_ALL=C
            umask 077
            [[ -f ./release-public.pem && ! -L ./release-public.pem ]] || exit 1
            [[ ! -e ./@LOCAL@ && ! -L ./@LOCAL@ ]] || exit 1
            work=$(mktemp -d './.kitsune-bootstrap.XXXXXXXX') || exit 1
            trap 'status=$?; rm -f -- "$work/bootstrap" "$work/status"; rmdir -- "$work"; exit "$status"' EXIT
            trap 'exit 130' INT
            trap 'exit 143' TERM
            (
                ceiling=@LIMIT@
                inherited=$(ulimit -f)
                if [[ "$inherited" == unlimited || "$inherited" -gt "$ceiling" ]]; then
                    ulimit -f "$ceiling"
                fi
                curl --disable --silent --show-error --fail --proto '=https' --proto-redir '=https' \
                    --max-redirs 0 --tlsv1.2 --connect-timeout 10 --max-time 300 --max-filesize @SIZE@ \
                    --output "$work/bootstrap" --write-out '%{http_code}' '@URL@'
            ) > "$work/status" || exit 1
            [[ "$(cat "$work/status")" == 200 ]] || exit 1
            [[ "$(wc -c < "$work/bootstrap")" -eq @SIZE@ ]] || exit 1
            digest=$(head -c @BOUND@ "$work/bootstrap" | openssl dgst -sha256 -r | awk '{print $1}') || exit 1
            [[ "$digest" == @HASH@ && "$(wc -c < "$work/bootstrap")" -eq @SIZE@ ]] || exit 1
            ln -- "$work/bootstrap" ./@LOCAL@ || exit 1
            BASH, [
            '@LOCAL@' => $local, '@URL@' => $base.$bootstrapName,
            '@SIZE@' => (string) $bootstrap['size'], '@BOUND@' => (string) ($bootstrap['size'] + 1),
            '@LIMIT@' => (string) ((int) ceil($bootstrap['size'] / 512) + 2), '@HASH@' => $bootstrap['sha256'],
        ]);
        $run = strtr(<<<'BASH'
            set -euo pipefail
            export LC_ALL=C
            [[ -f ./@LOCAL@ && ! -L ./@LOCAL@ ]] || exit 1
            [[ "$(wc -c < ./@LOCAL@)" -eq @SIZE@ ]] || exit 1
            digest=$(head -c @BOUND@ ./@LOCAL@ | openssl dgst -sha256 -r | awk '{print $1}') || exit 1
            [[ "$digest" == @HASH@ && "$(wc -c < ./@LOCAL@)" -eq @SIZE@ ]] || exit 1
            bash ./@LOCAL@ fetch --key="$PWD/release-public.pem" --fingerprint=@PIN@ --version=@VERSION@ --channel=@CHANNEL@ --directory="$PWD" --artifact=@ARTIFACT@ || exit 1
            BASH, [
            '@LOCAL@' => $local, '@SIZE@' => (string) $bootstrap['size'],
            '@BOUND@' => (string) ($bootstrap['size'] + 1), '@HASH@' => $bootstrap['sha256'],
            '@PIN@' => $fingerprint, '@VERSION@' => $manifest->version, '@CHANNEL@' => $manifest->channel,
            '@ARTIFACT@' => $artifactName,
        ]);

        return [
            'format' => 'kitsune-release-instructions-v1',
            'publication_verified' => false,
            'version' => $manifest->version, 'channel' => $manifest->channel,
            'source' => $manifest->source, 'tree' => $manifest->tree,
            'fingerprint' => $fingerprint,
            'manifest_sha256' => hash('sha256', $manifest->bytes()),
            'signature_sha256' => hash('sha256', $signature),
            'manifest_url' => $base.'release.manifest', 'signature_url' => $base.'release.manifest.sig',
            'bootstrap' => $bootstrap + ['url' => $base.$bootstrapName, 'local_name' => $local],
            'artifact' => $artifact + ['url' => $base.$artifactName], 'packages' => $manifest->packages,
            'download' => 'bash -c '.self::quote($download),
            'inspect' => 'less -- ./'.$local,
            'run' => 'bash -c '.self::quote($run),
            'one_liner' => 'bash -c '.self::quote(str_replace("\\\n", '', $download."\n".$run)),
            'scope' => 'Obtain instructions and fingerprint independently; place the independently trusted key at ./release-public.pem in an operator-controlled directory. Download, inspect, then run the pinned verifier. The companion command skips inspection only. Both fetch one authenticated payload; neither extracts or executes it. URLs describe separately authorized publication, not existing downloads or host/install qualification.',
        ];
    }

    private static function quote(string $script): string
    {
        // Dollar-quoted Bash strings preserve line boundaries while keeping the companion one physical line.
        return "$'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\n', '\r'], $script)."'";
    }
}
