#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Pre-PHP verification and selected authenticated download. Never extracts or executes payload bytes.
set -euo pipefail
export LC_ALL=C
umask 077

refuse() { printf 'Release verification refused: %s\n' "$1" >&2; exit 1; }
mode=verify
case "${1:-}" in fetch|verify) mode=$1; shift ;; esac
manifest= signature= key= fingerprint= version= channel= directory= artifact= work=
for argument in "$@"; do
    [[ "$argument" == --*=* && -n "${argument#*=}" ]] || refuse 'empty or implicit option'
    case "$argument" in
        --manifest=*) [[ -z "$manifest" ]] || refuse 'duplicate manifest'; manifest=${argument#--manifest=} ;;
        --signature=*) [[ -z "$signature" ]] || refuse 'duplicate signature'; signature=${argument#--signature=} ;;
        --key=*) [[ -z "$key" ]] || refuse 'duplicate key'; key=${argument#--key=} ;;
        --fingerprint=*) [[ -z "$fingerprint" ]] || refuse 'duplicate fingerprint'; fingerprint=${argument#--fingerprint=} ;;
        --version=*) [[ -z "$version" ]] || refuse 'duplicate version'; version=${argument#--version=} ;;
        --channel=*) [[ -z "$channel" ]] || refuse 'duplicate channel'; channel=${argument#--channel=} ;;
        --directory=*) [[ -z "$directory" ]] || refuse 'duplicate directory'; directory=${argument#--directory=} ;;
        --artifact=*) [[ -z "$artifact" ]] || refuse 'duplicate artifact'; artifact=${argument#--artifact=} ;;
        *) refuse 'unknown option; use seven explicit --name=value verification inputs' ;;
    esac
done
[[ -n "$key" && -n "$directory" ]] || refuse 'missing input'
if [[ "$mode" == verify ]]; then
    [[ -n "$manifest" && -n "$signature" ]] || refuse 'missing manifest or signature'
else
    [[ -z "$manifest" && -z "$signature" && -n "$artifact" ]] || refuse 'fetch requires a selected artifact and no local manifest/signature'
fi
if [[ -n "$artifact" ]]; then
    [[ "$artifact" =~ ^[a-zA-Z0-9][a-zA-Z0-9._-]*$ && ${#artifact} -le 128 && "$artifact" != *..* ]] || refuse 'invalid selected artifact basename'
fi
[[ "$fingerprint" =~ ^[a-f0-9]{64}$ ]] || refuse 'invalid trusted fingerprint'
[[ "$version" =~ ^v[0-9A-Za-z.+-]+$ && ${#version} -le 128 ]] || refuse 'invalid requested version'
[[ "$channel" == test || "$channel" == stable ]] || refuse 'invalid requested channel'
directory=$(cd -- "$directory" && pwd -P) || refuse 'artifact directory unavailable'
if [[ "$mode" == fetch && ( -e "$directory/$artifact" || -L "$directory/$artifact" ) ]]; then
    refuse 'selected output already exists'
fi

cleanup() {
    # Only this invocation's fixed files in its newly created private directory.
    if [[ -n "$work" && -d "$work" && ! -L "$work" ]]; then
        rm -f -- "$work/manifest" "$work/signature" "$work/key" "$work/key.der" "$work/key.txt" "$work/rows" "$work/nonascii" "$work/payload" "$work/http-status"
        rmdir -- "$work" 2>/dev/null || true
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if [[ "$mode" == fetch ]]; then
    # Same filesystem as the destination, so promotion can be exclusive without a copy fallback.
    work=$(mktemp -d "$directory/.kitsune-fetch.XXXXXXXX") || refuse 'private download directory unavailable'
else
    work=$(mktemp -d "${TMPDIR:-/tmp}/kitsune-verify.XXXXXXXX") || refuse 'private temporary directory unavailable'
fi

regular() {
    [[ -f "$1" && ! -L "$1" ]] || refuse 'regular input file required'
    [[ "$(find "$1" -prune -type f -links 1 -print)" == "$1" ]] || refuse 'single-link input file required'
}
control() {
    local path=$1 name=$2 maximum=$3 count
    [[ "$path" == /* ]] || path="$PWD/$path"
    regular "$path"
    count=$(wc -c < "$path")
    [[ "$count" -gt 0 && "$count" -le "$maximum" ]] || refuse 'control file size'
    # Copy a bounded snapshot, not an unbounded cp of a concurrently growing file.
    dd if="$path" of="$work/$name" bs=1024 count="$(( (maximum + 1023) / 1024 + 1 ))" 2>/dev/null || refuse 'control read'
    count=$(wc -c < "$work/$name")
    [[ "$count" -gt 0 && "$count" -le "$maximum" ]] || refuse 'control snapshot size'
}
download() {
    local name=$1 destination=$2 maximum=$3 count
    # This is a versioned project-domain route. Never retrieve the trust root from this host.
    if ! (
        ceiling=$(( (maximum + 511) / 512 + 2 ))
        inherited=$(ulimit -f)
        # Never raise an inherited file limit. This child-only backstop also bounds older curl builds.
        if [[ "$inherited" == unlimited || "$inherited" -gt "$ceiling" ]]; then
            ulimit -f "$ceiling" || exit 1
        fi
        curl --disable --silent --show-error --fail --proto '=https' --proto-redir '=https' \
            --max-redirs 0 --tlsv1.2 --connect-timeout 10 --max-time 300 --max-filesize "$maximum" \
            --output "$destination" --write-out '%{http_code}' \
            "https://kitsunecms.org/releases/$version/$name"
    ) > "$work/http-status"; then
        refuse 'HTTPS transfer failed'
    fi
    [[ "$(cat "$work/http-status")" == 200 ]] || refuse 'HTTPS response must be 200; redirects are refused'
    count=$(wc -c < "$destination")
    [[ "$count" -gt 0 && "$count" -le "$maximum" ]] || refuse 'download size'
}
control "$key" key 16384
IFS= read -r key_header < "$work/key" || refuse 'public key header'
[[ "${key_header%$'\r'}" == '-----BEGIN PUBLIC KEY-----' ]] || refuse 'public key format'
openssl rsa -pubin -in "$work/key" -text -noout > "$work/key.txt" 2>/dev/null < /dev/null || refuse 'RSA public key required'
bits=$(awk 'match($0, /\([0-9]+ bit\)/) { print substr($0, RSTART + 1, RLENGTH - 6); exit }' "$work/key.txt")
[[ "$bits" =~ ^[0-9]{4}$ ]] || refuse 'RSA key size'
[[ "$bits" -ge 3072 && "$bits" -le 8192 && "$((bits % 256))" -eq 0 ]] || refuse 'RSA key size'
openssl pkey -pubin -in "$work/key" -outform DER > "$work/key.der" 2>/dev/null < /dev/null || refuse 'public key encoding'
actual_pin=$(openssl dgst -sha256 -r < "$work/key.der" | awk '{print $1}') || refuse 'public key fingerprint'
[[ "$actual_pin" == "$fingerprint" ]] || refuse 'untrusted public key'
if [[ "$mode" == fetch ]]; then
    download release.manifest "$work/manifest" 65536
    download release.manifest.sig "$work/signature" 1024
else
    control "$manifest" manifest 65536
    control "$signature" signature 1024
fi
[[ "$(wc -c < "$work/signature")" -eq "$((bits / 8))" ]] || refuse 'signature size'
openssl dgst -sha256 -sigopt rsa_padding_mode:pkcs1 -verify "$work/key" -signature "$work/signature" "$work/manifest" > /dev/null 2>&1 < /dev/null || refuse 'invalid signature'

# Authentication precedes semantic interpretation. Reject bytes awk would otherwise normalize.
tr -d '\011\012\040-\176' < "$work/manifest" > "$work/nonascii"
[[ ! -s "$work/nonascii" ]] || refuse 'non-ASCII manifest'
last_byte=$(tail -c 1 "$work/manifest" | od -An -tu1 | tr -d ' \n')
[[ "$last_byte" == 10 ]] || refuse 'manifest needs final LF'
if ! awk -F '\t' -v expected_version="$version" -v expected_channel="$channel" '
function fail() { bad = 1; exit 1 }
function hex(value, size) { return length(value) == size && value !~ /[^a-f0-9]/ }
function identifiers(value) { return value ~ /^[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*$/ }
function release_part(value, parts) { split(value, parts, /[+]/); return parts[1] }
function semver(value, pieces, number, base, pre, offset, parts, i, total) {
    if (length(value) > 128 || substr(value, 1, 1) != "v") return 0
    number = split(substr(value, 2), pieces, /[+]/)
    if (number > 2 || (number == 2 && !identifiers(pieces[2]))) return 0
    base = pieces[1]; offset = index(base, "-")
    if (offset > 0) {
        pre = substr(base, offset + 1); base = substr(base, 1, offset - 1)
        if (!identifiers(pre)) return 0
        total = split(pre, parts, /\./)
        for (i = 1; i <= total; i++)
            if (parts[i] ~ /^[0-9]+$/ && length(parts[i]) > 1 && substr(parts[i], 1, 1) == "0") return 0
    }
    total = split(base, parts, /\./)
    if (total != 3) return 0
    for (i = 1; i <= total; i++) if (parts[i] !~ /^(0|[1-9][0-9]*)$/) return 0
    return 1
}
NR == 1 { if ($0 != "kitsune-release-manifest-v1") fail(); next }
NR == 2 { if (NF != 2 || $1 != "version" || !semver($2) || $2 != expected_version) fail(); ver = $2; next }
NR == 3 { if (NF != 2 || $1 != "channel" || $2 !~ /^(test|stable)$/ || $2 != expected_channel) fail(); channel = $2; if (channel == "stable" && index(release_part(ver), "-")) fail(); next }
NR == 4 { if (NF != 2 || $1 != "source" || !hex($2, 40)) fail(); source = $2; next }
NR == 5 { if (NF != 2 || $1 != "tree" || !hex($2, 40)) fail(); tree = $2; next }
NR == 6 { if (NF != 2 || $1 != "signature" || $2 != "rsa-pkcs1-sha256") fail(); next }
{
    if (NF != 6) fail()
    if ($1 == "artifact") {
        if (packages || ++artifacts > 16 || ("x" $2) <= ("x" last_artifact) ||
            length($2) > 128 || $2 !~ /^[a-zA-Z0-9][a-zA-Z0-9._-]*$/ || index($2, "..") ||
            $3 !~ /^(bootstrap|native|docker|provenance)$/ || $4 !~ /^(any|linux-amd64|linux-arm64)$/ ||
            $5 !~ /^[1-9][0-9]*$/ || length($5) > 11 || $5 + 0 > 10737418240 || !hex($6, 64)) fail()
        last_artifact = $2
        print "A\t" $2 "\t" $5 "\t" $6 "\t" $3 "\t" $4
    } else if ($1 == "package") {
        development = substr($3, 1, 4) == "dev-" && hex(substr($3, 5), 40)
        if (!artifacts || ++packages > 32 || ("x" $2) <= ("x" last_package) || length($2) > 128 ||
            $2 !~ /^kitsune\/[a-z0-9]+(-[a-z0-9]+)*$/ || (!development && !semver("v" $3)) ||
            (development && $3 != "dev-" $4) ||
            (channel == "stable" && (development || index(release_part($3), "-"))) ||
            !hex($4, 40) || !hex($5, 64) || $6 !~ /^(split|bundled|excluded)$/) fail()
        last_package = $2
    } else fail()
}
END {
    if (bad || NR < 8 || !artifacts || !packages) exit 1
    print "R\t" ver "\t" channel "\t" source "\t" tree "\t" artifacts "\t" packages
}
' "$work/manifest" > "$work/rows"; then
    refuse 'unsupported, ambiguous or mismatched manifest'
fi

verified_artifacts=0
while IFS=$'\t' read -r kind first second third fourth fifth sixth; do
    if [[ "$kind" == A ]]; then
        [[ -z "$artifact" || "$first" == "$artifact" ]] || continue
        verified_artifacts=$((verified_artifacts + 1))
        if [[ "$mode" == fetch ]]; then
            download "$first" "$work/payload" "$second"
            path="$work/payload"
        else
            path="$directory/$first"
        fi
        regular "$path"
        [[ "$(wc -c < "$path")" -eq "$second" ]] || refuse 'artifact size'
        digest=$(head -c "$((second + 1))" "$path" | openssl dgst -sha256 -r | awk '{print $1}') || refuse 'artifact read'
        [[ "$digest" == "$third" && "$(wc -c < "$path")" -eq "$second" ]] || refuse 'artifact checksum or size'
        if [[ "$mode" == fetch ]]; then
            # ln without force refuses a racing/existing destination; only verified bytes are promoted.
            ln -- "$work/payload" "$directory/$artifact" || refuse 'exclusive output creation failed'
            rm -f -- "$work/payload"
        fi
    else
        receipt_version=$first receipt_channel=$second receipt_source=$third receipt_tree=$fourth artifact_count=$fifth package_count=$sixth
    fi
done < "$work/rows"
[[ "$verified_artifacts" -gt 0 ]] || refuse 'selected artifact is absent from the authenticated manifest'
if [[ -n "$artifact" ]]; then
    printf '{"verified":true,"version":"%s","channel":"%s","source":"%s","tree":"%s","artifact_count":%s,"manifest_artifact_count":%s,"package_count":%s,"artifact_name":"%s"}\n' \
        "$receipt_version" "$receipt_channel" "$receipt_source" "$receipt_tree" "$verified_artifacts" "$artifact_count" "$package_count" "$artifact"
else
    printf '{"verified":true,"version":"%s","channel":"%s","source":"%s","tree":"%s","artifact_count":%s,"package_count":%s}\n' \
        "$receipt_version" "$receipt_channel" "$receipt_source" "$receipt_tree" "$verified_artifacts" "$package_count"
fi
