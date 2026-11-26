# Site API tokens

Kitsune's v1.1 product API uses bearer credentials bound to a single Site and a staff account. The
credential carries explicit entry-type abilities such as `entry.article.view`. Each request also
requires the account's current permission, Org and Site membership, and an enabled entry type.

## Enable token access

Install Laravel Sanctum 4.5 or later in the 4.x series in the host application. Run the normal schema
migrations before applying the optional token migration. On the Kitsune skeleton, use:

```sh
composer require 'laravel/sanctum:^4.5'
php artisan migrate --path=database/api-migrations
```

Set `api.staff_provider` in the host's `config/kitsune.php` to the staff provider from
`config/auth.php`. The skeleton's provider is `users`. Use the provider whose staff table owns both
membership pivots. The migration supports the skeleton membership layout and host staff models using
integer, ULID or UUID identifiers with matching membership column types. A host with different
membership tables must supply the equivalent foreign keys.

The guard is `kitsune-site-api`. Place `ResolveSiteFromRequest` and `auth:kitsune-site-api` on the
Site API routes before content binding. The API must use the resolved Site's canonical host and path.
For content operations, check the token ability with `SiteTokens::allows()` and use the usual
entry policy and scoped record reads. The generated content routes and response contract are separate
v1.1 work. The guard checks only the bearer header. A host that uses Sanctum only for Kitsune may
set `sanctum.routes` to `false` to omit Sanctum's separate SPA cookie route.

## Issue and revoke

Run issuance from a private operator terminal. Replace the example Org, Site handle and staff ID
with the actual values. Repeat `--ability` for each permission needed by the client.

```sh
php artisan kitsune:api-token issue --org=example --site=magazine --actor=42 \
  --name=publisher --ability=entry.article.view --ability=entry.article.create --days=30
```

The command prints the bearer credential once. The number before the pipe is the token ID. Store the
full credential in the client's secret manager and send it in the `Authorization: Bearer …` header
over HTTPS and request JSON with `Accept: application/json`. Use a lifetime from 1 to 365 days. The command defaults to 30 days.

```sh
php artisan kitsune:api-token revoke --org=example --site=magazine --id=7
```

Revocation applies on the next request. Removing the account's membership in the Site or Org deletes
its affected credentials permanently. Adding that membership back requires a new credential. A role
change takes effect on the next request, and disabling an entry type makes its operations unavailable
for that Site. Missing, expired or revoked credentials return 401. An authenticated credential that
lacks the required ability or current entry-type permission receives 403.
