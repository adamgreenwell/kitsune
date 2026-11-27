#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, you can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail
umask 077

host=${KITSUNE_REHEARSAL_HOST:?Set the dedicated Kitsune rehearsal demo hostname}
[[ "$host" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]*[a-z0-9])?$ ]]
[[ ! -L /ca/.lock && ! -L /ca/authority && ! -L /published/ca.crt ]]
exec 9>>/ca/.lock
flock -n 9
scratch=
pending=
cleanup() {
    [[ -z "$scratch" ]] || rm -rf "$scratch"
    [[ -z "$pending" ]] || rm -f "$pending"
}
trap cleanup EXIT

if [[ ! -d /ca/authority ]]; then
    [[ ! -e /published/ca.crt && ! -L /origin/current ]]
    scratch=$(mktemp -d /ca/.new.XXXXXXXX)
    openssl req -x509 -newkey rsa:3072 -nodes -days 3650 -sha256 \
        -subj '/CN=Kitsune rehearsal disposable origin CA' \
        -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
        -keyout "$scratch/ca.key" -out "$scratch/ca.crt" >/dev/null 2>&1
    sync "$scratch/ca.key" "$scratch/ca.crt"
    mv -T "$scratch" /ca/authority
    sync -f /ca
    scratch=
fi

ca=/ca/authority/ca.crt
key=/ca/authority/ca.key
[[ -s "$ca" && -s "$key" && ! -L "$ca" && ! -L "$key" ]]
cmp <(openssl pkey -in "$key" -pubout) <(openssl x509 -in "$ca" -pubkey -noout)
if [[ ! -e /published/ca.crt ]]; then
    pending=$(mktemp /published/.ca.XXXXXXXX)
    install -m 0444 "$ca" "$pending"
    sync "$pending"
    mv -T "$pending" /published/ca.crt
    sync -f /published
    pending=
fi
cmp "$ca" /published/ca.crt

current=/origin/current
if [[ -e "$current" || -L "$current" ]]; then
    [[ -L "$current" && "$(readlink "$current")" =~ ^leaf\.[A-Za-z0-9]+$ ]]
    [[ -s "$current/server.key" && -s "$current/server.crt" ]]
    cmp <(openssl pkey -in "$current/server.key" -pubout) <(openssl x509 -in "$current/server.crt" -pubkey -noout)
    # Check lineage and hostname separately from expiry so an expired owned leaf can renew.
    openssl verify -no_check_time -CAfile "$ca" -verify_hostname "$host" "$current/server.crt" >/dev/null
    if openssl x509 -checkend 2592000 -noout -in "$current/server.crt" >/dev/null; then
        openssl verify -CAfile "$ca" -verify_hostname "$host" "$current/server.crt"
        exit 0
    fi
fi

scratch=$(mktemp -d /origin/.leaf.XXXXXXXX)
newLeaf=${scratch##*/}
newLeaf=${newLeaf#.}
openssl req -newkey rsa:3072 -nodes -sha256 -subj "/CN=$host" \
    -keyout "$scratch/server.key" -out "$scratch/server.csr" >/dev/null 2>&1
printf '%s\n' 'basicConstraints=critical,CA:FALSE' 'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth' "subjectAltName=DNS:$host" > "$scratch/extensions"
openssl x509 -req -in "$scratch/server.csr" -CA "$ca" -CAkey "$key" \
    -days 90 -sha256 -extfile "$scratch/extensions" -out "$scratch/server.crt" >/dev/null 2>&1
openssl verify -CAfile "$ca" -verify_hostname "$host" "$scratch/server.crt"
chown www-data:www-data "$scratch" "$scratch/server.key" "$scratch/server.crt"
chmod 0400 "$scratch/server.key"
chmod 0444 "$scratch/server.crt"
rm -f "$scratch/server.csr" "$scratch/extensions"
sync "$scratch/server.key" "$scratch/server.crt"
mv -T "$scratch" "/origin/$newLeaf"
scratch=
pending=$(mktemp /origin/.current.XXXXXXXX)
rm "$pending"
ln -s "$newLeaf" "$pending"
mv -T "$pending" "$current"
sync -f /origin
pending=
