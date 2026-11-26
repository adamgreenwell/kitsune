#!/usr/bin/env bash
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
set -euo pipefail
umask 077

refuse() {
    printf 'Kitsune installation refused: %s\n' "$*" >&2
    exit 1
}

verify_application() {
    local manifest=$1 signature=$2 key=$3 fingerprint=$4 version=$5 channel=$6 archive=$7 architecture=$8
    local artifact
    artifact=$(basename -- "$archive")
    mkdir "$work/verified"
    cp -- "$manifest" "$work/manifest"
    cp -- "$signature" "$work/signature"
    cp -- "$key" "$work/public-key.pem"
    cp -- "$archive" "$work/verified/$artifact"
    bash "$root/bin/verify-release.sh" verify \
        --manifest="$work/manifest" --signature="$work/signature" --key="$work/public-key.pem" \
        --fingerprint="$fingerprint" --version="$version" --channel="$channel" \
        --directory="$work/verified" --artifact="$artifact"

    local archive_sha source_commit
    archive_sha=$(awk -F '\t' -v name="$artifact" -v platform="linux-$architecture" \
        '$1 == "artifact" && $2 == name && $3 == "native" && ($4 == "any" || $4 == platform) {print $6}' "$work/manifest")
    [[ "$archive_sha" =~ ^[a-f0-9]{64}$ ]] || refuse 'application archive role or platform does not match'
    source_commit=$(awk -F '\t' '$1 == "source" {print $2}' "$work/manifest")
    printf '%s\n' "$version" "$source_commit" "$archive_sha" > "$work/release"
    mv -- "$work/verified/$artifact" "$work/application.tar.gz"
}

unpack_application() {
    local archive=$work/application.tar.gz
    # The bundle contract allows regular files and directories only. Verify
    # member types before privileged extraction; GNU tar also rejects traversal.
    tar --list --verbose --numeric-owner --gzip --file="$archive" > "$work/members"
    awk '$1 !~ /^[-d]/ {exit 1} END {if (NR == 0) exit 1}' "$work/members" \
        || refuse 'unexpected archive member type'
    mkdir -p "$work/installation/app"
    (
        umask 022
        tar --extract --gzip --file="$archive" --directory="$work/installation/app" \
            --no-same-owner --no-same-permissions
    )
    local app=$work/installation/app
    [[ -f "$app/public/index.php" && -f "$app/vendor/autoload.php" && -f "$app/composer.lock" ]] \
        || refuse 'archive is missing the runtime application'
    [[ ! -e "$app/.env" && ! -e "$app/database/database.sqlite" && ! -e "$app/public/storage" ]] \
        || refuse 'archive already contains runtime state'
    mv -- "$app/storage" "$work/storage-scaffold"
    ln -s "$state/storage" "$app/storage"
    ln -s "$state/.env" "$app/.env"
    ln -s "$state/storage/app/public" "$app/public/storage"
    install -m 0600 "$work/release" "$work/installation/.release"
    chmod 0755 "$work/installation" "$app"
}

configure_sury() {
    local keyring=/usr/share/keyrings/kitsune-sury.gpg source=/etc/apt/sources.list.d/kitsune-sury.sources
    # Pin the reviewed keyring payload. Extract the key only, without running
    # package maintainer scripts or installing unrelated repository keys.
    local expected=7511384559c9ddf1d5ce5f60be429ae9d4e7d01d9480d6f1b7a30c0810cf8b60
    curl --fail --silent --show-error --proto '=https' \
        --output "$work/keyring.deb" https://packages.sury.org/debsuryorg-archive-keyring.deb
    printf '%s  %s\n' "$expected" "$work/keyring.deb" | sha256sum --check --status - \
        || refuse 'Surý keyring differs from the reviewed bytes'
    dpkg-deb --fsys-tarfile "$work/keyring.deb" | tar -xO ./usr/share/keyrings/debsuryorg-archive-keyring.gpg > "$work/keyring.gpg"
    [[ -s "$work/keyring.gpg" ]] || refuse 'Surý keyring is empty'
    install_managed_file "$work/keyring.gpg" "$keyring"
    printf '%s\n' 'Types: deb' 'URIs: https://packages.sury.org/php/' "Suites: $suite" \
        'Components: main' "Architectures: $architecture" "Signed-By: $keyring" > "$work/sury.sources"
    install_managed_file "$work/sury.sources" "$source"
}

install_managed_file() {
    local source=$1 target=$2
    if [[ -e "$target" || -L "$target" ]]; then
        [[ -f "$target" && ! -L "$target" ]] && cmp -s "$source" "$target" \
            || refuse "existing managed file differs: $target"
        return 0
    fi
    local pending
    pending=$(mktemp "$(dirname -- "$target")/.kitsune.XXXXXXXX")
    install -m 0644 "$source" "$pending"
    ln -T -- "$pending" "$target" || { unlink "$pending"; refuse "could not create $target"; }
    unlink "$pending"
}

configure_application() {
    local php_bin=/usr/bin/php"$php_version" config=$state/.env database=$state/database/database.sqlite
    [[ ! -L "$state" && ! -L "$config" && ! -L "$state/database" && ! -L "$state/storage" && ! -L "$database" ]] \
        || refuse 'application state contains an unexpected link'
    if [[ ! -e "$config" ]]; then
        [[ ! -e "$state/database" && ! -e "$state/storage" ]] \
            || refuse 'restore the configuration and APP_KEY for the existing data'
        install -d -m 0750 -o root -g www-data "$state"
        local pending key
        key=$("$php_bin" -r 'echo base64_encode(random_bytes(32));')
        pending=$(mktemp "$state/.pending-env.XXXXXXXX")
        printf '%s\n' 'APP_NAME=Kitsune' 'APP_ENV=production' 'APP_DEBUG=false' \
            "APP_KEY=base64:$key" 'APP_URL=http://localhost:8080' 'DB_CONNECTION=sqlite' "DB_DATABASE=$database" \
            'SESSION_DRIVER=file' 'CACHE_STORE=file' 'QUEUE_CONNECTION=sync' 'MAIL_MAILER=log' > "$pending"
        unset key
        chown root:www-data "$pending"
        chmod 0640 "$pending"
        mv -T -- "$pending" "$config"
    fi

    install -d -m 0700 -o www-data -g www-data "$state/database" "$state/storage" "$destination/app/bootstrap/cache"
    runuser -u www-data -- mkdir -p "$state/storage/app/private" "$state/storage/app/public" \
        "$state/storage/framework/cache/data" "$state/storage/framework/sessions" "$state/storage/framework/views" "$state/storage/logs"
    runuser -u www-data -- "$php_bin" -r '
        require $argv[1]."/vendor/autoload.php";
        try {
            $config = Dotenv\Dotenv::parse(file_get_contents($argv[2]));
            $key = $config["APP_KEY"] ?? "";
            exit(
                str_starts_with($key, "base64:") && strlen(base64_decode(substr($key, 7), true) ?: "") === 32
                && ($config["APP_ENV"] ?? null) === "production" && ($config["APP_DEBUG"] ?? null) === "false"
                && ($config["DB_CONNECTION"] ?? null) === "sqlite" && ($config["DB_DATABASE"] ?? null) === $argv[3]
                ? 0 : 1
            );
        } catch (Throwable) {
            exit(1);
        }
    ' "$destination/app" "$config" "$database" || refuse 'review the private configuration and SQLite path'
    if [[ ! -e "$database" ]]; then
        runuser -u www-data -- touch "$database"
    fi
    runuser -u www-data -- env -i PATH=/usr/bin:/bin "$php_bin" "$destination/app/artisan" migrate --force --no-interaction
}

disable_packaged_nginx_default() {
    local enabled=/etc/nginx/sites-enabled/default site=/etc/nginx/sites-available/default
    if [[ ! -e "$enabled" && ! -L "$enabled" ]]; then
        return
    fi
    [[ -L "$enabled" && "$(readlink --canonicalize "$enabled")" == "$site" ]] \
        || refuse 'existing nginx default site needs operator review'
    local expected
    expected=$(dpkg-query -W -f='${Conffiles}' nginx-common | awk -v site="$site" '$1 == site {print $2}')
    [[ "$expected" =~ ^[a-f0-9]{32}$ ]] \
        && printf '%s  %s\n' "$expected" "$site" | md5sum --check --status - \
        || refuse 'existing nginx default site differs from the package'
    unlink "$enabled"
}

start_services() {
    local nginx_config=/etc/nginx/conf.d/kitsune.conf
    disable_packaged_nginx_default
    sed "s|@PHP_VERSION@|$php_version|g" "$profile/nginx.conf" > "$work/nginx.conf"
    install_managed_file "$work/nginx.conf" "$nginx_config"
    nginx -t
    # Daemons started by package or service hooks must not inherit the install lock.
    systemctl enable --now "php$php_version-fpm.service" nginx.service 9>&-
    [[ -S /run/php/php"$php_version"-fpm.sock ]] || refuse 'the PHP-FPM socket is unavailable'
    systemctl reload nginx.service 9>&-
    curl --fail --silent --show-error --max-time 10 --retry 3 --retry-connrefused --noproxy '*' http://127.0.0.1:8080/up >/dev/null
    curl --fail --silent --show-error --max-time 10 --retry 3 --retry-connrefused --noproxy '*' http://127.0.0.1:8080/admin/login >/dev/null
}

install_host_packages() (
    # Maintainer scripts create host files outside the installer's private state.
    umask 022
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
)

require_install_journal_available() {
    local journal=$1 php=$2 repository=$3
    if [[ ! -e "$journal" && ! -L "$journal" ]]; then
        return
    fi

    "$php" -r '
        require $argv[1]."/bin/release/SelfHostUpgradeJournal.php";
        $journal = Kitsune\Release\SelfHostUpgradeJournal::open($argv[2], readOnly: true);
        $journal->requireAvailable();
    ' "$repository" "$journal" 9>&- >/dev/null 2>&1 \
        || refuse 'resolve the native upgrade journal before rerunning the installer; see docs/upgrades.md'
}

main() {
    (( EUID == 0 )) || refuse 'run on a dedicated Ubuntu host as root after reviewing the script'
    [[ -d /run/systemd/system ]] || refuse 'a running systemd host is required'
    if [[ ${1:-} != upgrade ]]; then
        (( $# == 7 )) || refuse 'usage: install.sh MANIFEST SIGNATURE PUBLIC_KEY FINGERPRINT VERSION CHANNEL ARCHIVE'
        [[ $7 == *.tar.gz ]] || refuse 'the application archive must be a .tar.gz'
    fi
    local tool
    for tool in curl openssl dpkg ss flock; do
        command -v "$tool" >/dev/null || refuse "missing host prerequisite: $tool"
    done

    profile=$(cd "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
    root=$(cd "$profile/../../.." && pwd)
    destination=/opt/kitsune
    state=/var/lib/kitsune
    architecture=$(dpkg --print-architecture)
    local plan suite_value php_value source_value packages_value
    plan=$(bash "$root/bin/native-install-preflight.sh") || refuse 'unsupported host'
    suite_value=$(awk -F= '$1=="suite" {print $2}' <<< "$plan")
    php_value=$(awk -F= '$1=="php" {print $2}' <<< "$plan")
    source_value=$(awk -F= '$1=="php_source" {print $2}' <<< "$plan")
    packages_value=$(awk -F= '$1=="packages" {print $2}' <<< "$plan")
    suite=$suite_value
    php_version=$php_value
    local -a packages
    read -r -a packages <<< "$packages_value"

    if [[ $1 == upgrade ]]; then
        shift
        case "${1:-}" in
            inspect|plan|request-native-admission|verify-native-admission|migrate-native|stage-native|confirm-native-private|publish-native|recover-native) ;;
            *) refuse 'choose an explicit native upgrade phase; see docs/upgrades.md' ;;
        esac
        # The upgrade commands own the journal and installer locks across each operation.
        exec env -i PATH=/usr/bin:/bin "/usr/bin/php$php_version" "$root/bin/self-host-upgrade.php" "$@"
    fi

    # Keep the same root-owned lock as the PHP upgrade commands, including after a reboot.
    local lock=/run/lock/kitsune-native-install.lock
    if [[ ! -e "$lock" && ! -L "$lock" ]]; then
        (set -o noclobber; : > "$lock") 2>/dev/null || true
    fi
    [[ -f "$lock" && ! -L "$lock" && "$(stat -c '%u:%a:%h' -- "$lock")" == '0:600:1' ]] \
        || refuse 'the native installer lock must be a root-owned private file'
    exec 9<> "$lock"
    flock -n 9 || refuse 'another installation or upgrade is running'
    require_install_journal_available /var/lib/kitsune-upgrades/journal "/usr/bin/php$php_version" "$root"
    if [[ -e "$destination/.installed" || -L "$destination/.installed" ]]; then
        [[ ! -L "$destination" && ! -L "$destination/.installed" && ! -L "$destination/.release" ]] \
            && cmp -s "$destination/.release" "$destination/.installed" \
            || refuse 'existing installation has invalid release records'
        [[ -s "$state/database/database.sqlite" && -s "$state/.env" ]] \
            || refuse 'restore the database and APP_KEY for the existing installation'
        work=$(mktemp -d /opt/.kitsune-inspect.XXXXXXXX)
        trap 'rm -rf -- "$work"' EXIT
        verify_application "$@" "$architecture"
        cmp -s "$work/release" "$destination/.installed" \
            || refuse 'a different verified release requires install.sh upgrade with a signed admission; see docs/upgrades.md'
        printf '%s\n' 'The requested release is already installed. Use install.sh upgrade inspect to review its state.'
        return
    fi
    if [[ ! -e "$destination" && ! -L "$destination" ]]; then
        [[ ! -e "$state" && ! -L "$state" && ! -e /etc/nginx/conf.d/kitsune.conf ]] \
            || refuse 'Kitsune paths already contain unmanaged state'
        local listeners package status
        listeners=$(ss -H -ltn '( sport = :80 or sport = :8080 )') || refuse 'could not inspect listening ports'
        [[ -z "$listeners" ]] || refuse 'port 80 or 8080 is already in use'
        for package in nginx nginx-common "php$php_version-fpm"; do
            status=$(dpkg-query -W -f='${db:Status-Status}' "$package" 2>/dev/null || true)
            [[ -z "$status" || "$status" == not-installed ]] \
                || refuse "existing $package requires an operator-managed installation"
        done
    fi

    work=$(mktemp -d /opt/.kitsune-install.XXXXXXXX)
    trap 'rm -rf -- "$work"' EXIT
    verify_application "$@" "$architecture"
    if [[ -e "$destination" || -L "$destination" ]]; then
        [[ -d "$destination" && ! -L "$destination" && -f "$destination/.release" ]] \
            && cmp -s "$work/release" "$destination/.release" \
            || refuse 'existing application files require the upgrade procedure'
    else
        unpack_application
        mv -T -- "$work/installation" "$destination"
    fi
    if [[ "$source_value" = sury ]]; then
        configure_sury
    fi
    install_host_packages "${packages[@]}" 9>&-
    configure_application
    start_services
    mv -T -- "$work/release" "$destination/.installed"
    printf '%s\n' 'Kitsune is ready on http://localhost:8080.' \
        'Create your first owner from this terminal. Replace the example org and email:' \
        "sudo -u www-data -- /usr/bin/php$php_version $destination/app/artisan kitsune:blueprint apply blog --org=myblog --owner=you@example.test"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    main "$@"
fi
