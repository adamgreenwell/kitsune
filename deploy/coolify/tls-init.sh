#!/bin/sh
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -eu
umask 077
existing=false
for file in /private/ca.key /private/ca.crt /private/server.key /private/server.crt \
    /private/server.csr /private/ca.srl /private/extensions /public/ca.crt; do
    if test -e "$file" || test -L "$file"; then existing=true; fi
done
if "$existing"; then
    test -s /private/ca.key && test -s /private/ca.crt && test -s /private/server.key \
        && test -s /private/server.crt && test -s /public/ca.crt
    cmp /private/ca.crt /public/ca.crt
    checked_keys=$(mktemp -d)
    trap 'rm -rf "$checked_keys"' 0
    for name in ca server; do
        openssl pkey -in "/private/$name.key" -pubout -out "$checked_keys/key.pub" >/dev/null 2>&1
        openssl x509 -in "/private/$name.crt" -pubkey -noout -out "$checked_keys/certificate.pub"
        cmp "$checked_keys/key.pub" "$checked_keys/certificate.pub"
    done
    openssl verify -CAfile /public/ca.crt -verify_hostname development.kitsunecms.org /private/server.crt
    exit 0
fi
mkdir -p /private /public
chmod 0700 /private
openssl req -x509 -newkey rsa:3072 -nodes -days 365 -sha256 \
    -subj '/CN=Kitsune development private origin CA' \
    -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
    -keyout /private/ca.key -out /private/ca.crt >/dev/null 2>&1
openssl req -newkey rsa:3072 -nodes -sha256 -subj '/CN=development.kitsunecms.org' \
    -keyout /private/server.key -out /private/server.csr >/dev/null 2>&1
printf '%s\n' 'basicConstraints=critical,CA:FALSE' 'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth' 'subjectAltName=DNS:development.kitsunecms.org' > /private/extensions
openssl x509 -req -in /private/server.csr -CA /private/ca.crt -CAkey /private/ca.key -CAcreateserial \
    -days 90 -sha256 -extfile /private/extensions -out /private/server.crt >/dev/null 2>&1
cp /private/ca.crt /public/ca.crt
chmod 0755 /public
chmod 0644 /public/ca.crt
openssl verify -CAfile /public/ca.crt -verify_hostname development.kitsunecms.org /private/server.crt
