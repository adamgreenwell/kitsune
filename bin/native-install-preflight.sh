#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

# Print the native package plan before the installer configures repositories or services.
kitsune_native_platform_plan() {
    local os_release=$1 architecture=$2
    local key value id='' version='' suite='' php source repository=''

    if [[ ! -r "$os_release" ]]; then
        printf '%s\n' 'The operating-system release file is unavailable.' >&2
        return 1
    fi

    # Only these three fields enter the allowlist. The release file is data.
    while IFS='=' read -r key value || [[ -n "$key" ]]; do
        value=${value%$'\r'}
        case "$value" in
            \"*\"|\'*\') value=${value:1:${#value}-2} ;;
        esac
        case "$key" in
            ID) id=$value ;;
            VERSION_ID) version=$value ;;
            VERSION_CODENAME) suite=$value ;;
        esac
    done < "$os_release"

    case "$id:$version:$suite" in
        ubuntu:22.04:jammy|ubuntu:24.04:noble)
            php=8.4
            source=sury
            repository=https://packages.sury.org/php/
            ;;
        ubuntu:26.04:resolute)
            php=8.5
            source=ubuntu
            ;;
        *)
            printf '%s\n' 'Native package planning requires Ubuntu 22.04, 24.04 or 26.04 LTS.' >&2
            return 1
            ;;
    esac

    case "$architecture" in
        amd64|arm64) ;;
        *)
            printf '%s\n' 'Native package planning requires amd64 or arm64.' >&2
            return 1
            ;;
    esac

    printf 'suite=%s\narchitecture=%s\nphp=%s\nphp_source=%s\n' "$suite" "$architecture" "$php" "$source"
    if [[ -n "$repository" ]]; then
        printf 'php_repository=%s\n' "$repository"
    fi
    printf 'packages=nginx php%s-cli php%s-fpm php%s-curl php%s-intl php%s-mbstring php%s-sqlite3 php%s-xml php%s-zip\n' \
        "$php" "$php" "$php" "$php" "$php" "$php" "$php" "$php"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    if (( $# != 0 )); then
        printf 'Usage: %s\n' "$0" >&2
        exit 2
    fi
    if [[ $(uname -s) != Linux ]] || ! command -v dpkg >/dev/null 2>&1; then
        printf '%s\n' 'Run the native preflight on an Ubuntu Linux system with dpkg.' >&2
        exit 1
    fi
    if ! architecture=$(dpkg --print-architecture); then
        printf '%s\n' 'Could not identify the native package architecture.' >&2
        exit 1
    fi
    kitsune_native_platform_plan /etc/os-release "$architecture"
fi
