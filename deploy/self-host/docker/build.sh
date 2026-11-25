#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail
umask 077

refuse() {
    printf 'Kitsune image build refused: %s\n' "$*" >&2
    exit 1
}

(( $# == 9 )) || refuse 'usage: build.sh MANIFEST SIGNATURE PUBLIC_KEY FINGERPRINT VERSION CHANNEL ARCHIVE PLATFORM IMAGE'
manifest=$1
signature=$2
public_key=$3
fingerprint=$4
version=$5
channel=$6
archive=$7
platform=$8
image=$9

case "$platform" in
    linux/amd64|linux/arm64) ;;
    *) refuse 'use linux/amd64 or linux/arm64' ;;
esac
case "$archive" in
    *.tar.gz) ;;
    *) refuse 'the application archive must be a .tar.gz' ;;
esac

profile=$(cd "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
root=$(cd "$profile/../../.." && pwd)
work=$(mktemp -d "${TMPDIR:-/tmp}/kitsune-image.XXXXXXXX")
trap 'rm -rf -- "$work"' EXIT
artifact=$(basename -- "$archive")
mkdir "$work/verified" "$work/context"
# Verify the same private copy that goes into the build context.
cp -- "$manifest" "$work/manifest"
cp -- "$signature" "$work/signature"
cp -- "$public_key" "$work/public-key.pem"
cp -- "$archive" "$work/verified/$artifact"
bash "$root/bin/verify-release.sh" verify \
    --manifest="$work/manifest" --signature="$work/signature" --key="$work/public-key.pem" \
    --fingerprint="$fingerprint" --version="$version" --channel="$channel" \
    --directory="$work/verified" --artifact="$artifact"

release_platform=${platform/\//-}
archive_sha=$(awk -F '\t' -v name="$artifact" -v platform="$release_platform" \
    '$1 == "artifact" && $2 == name && $3 == "native" && ($4 == "any" || $4 == platform) {print $6}' "$work/manifest")
[[ "$archive_sha" =~ ^[a-f0-9]{64}$ ]] || refuse 'application archive role or platform does not match'
source_sha=$(awk -F '\t' '$1 == "source" {print $2}' "$work/manifest")
mv -- "$work/verified/$artifact" "$work/context/application.tar.gz"
cp -- "$profile/Dockerfile" "$profile/start.sh" "$profile/nginx.conf" "$profile/php-fpm.conf" "$work/context/"

docker build --platform "$platform" --tag "$image" \
    --build-arg KITSUNE_SOURCE_SHA="$source_sha" --build-arg KITSUNE_ARCHIVE_SHA256="$archive_sha" \
    "$work/context"
