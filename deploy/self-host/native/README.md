# Kitsune on a native Ubuntu host

Use a dedicated Ubuntu 22.04, 24.04 or 26.04 LTS machine on AMD64 or ARM64, with systemd running,
Bash, curl, OpenSSL, iproute2 and the standard Ubuntu package tools. The
[read-only package preflight](../../../bin/native-install-preflight.sh) prints the PHP choice and required
packages. Ubuntu 26.04 uses Ubuntu PHP 8.5. Ubuntu 22.04 and 24.04 use PHP 8.4 from the
[Surý Ubuntu repository](https://packages.sury.org/php/README.txt).

## Verify and install

Obtain the exact application archive, manifest and detached signature as described in the
[release verification workflow](../../../docs/release-artifacts.md). Independently confirm the public
key fingerprint. Inspect the installer and its Nginx configuration in the matching release source.
Run it on the dedicated target with available ports 80 and 8080.

```sh
sudo deploy/self-host/native/install.sh "$manifest" "$signature" "$public_key" "$fingerprint" \
  "$version" "$channel" "$application_archive"
```

The installer verifies the release before extraction. It installs the PHP and Nginx packages,
prepares SQLite and loads the Kitsune site on loopback. It disables the untouched packaged Nginx default
site and preserves existing operator-managed services and configuration. The installed application lives
at `/opt/kitsune/app`; the persistent configuration, database and media live under `/var/lib/kitsune`.

The installer prints the terminal command for creating the first owner. Run it with your org slug
and email, then open <http://localhost:8080/admin/login>. Configure production TLS and network exposure
as a separate host operation after creating the owner. The installer leaves Kitsune's web listener
on loopback.

## Recover or upgrade

On an interrupted install, rerun the same reviewed release with the same inputs. The installer
preserves the existing key and SQLite data. On a completed installation, it reports the installed
state and directs you to [upgrades](../../../docs/upgrades.md). Keep a backup of the entire data
directory while the site is stopped and store a protected copy of `APP_KEY`.

## Reruns and upgrades

A normal installer rerun verifies the manifest and archive, compares the release receipt, and
returns success for the already installed release. Use the signed upgrade workflow for a
different release. The installer accepts its commands with an `upgrade` prefix. See
[upgrade and compatibility](../../../docs/upgrades.md) for preparation, migration, staging,
traffic handoff and recovery.
