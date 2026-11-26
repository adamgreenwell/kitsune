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

## Discover a Site's fields

Send a GET request to `/api/v1/schema` under the Site's canonical URL, including its full path prefix
when it has one. Include the bearer header and request JSON. The response's `data` list contains the
entry types for which the token and account allow viewing or editing. Each type includes its handle
and a JSON Schema object for configured field values. The response includes required fields, choice
keys, and configured item and length bounds. It is private and must stay out of shared caches.

## Read entries

- `GET /api/v1/entries/{type}` returns an entry list in ascending ID order. Pass `limit` from 1 to
  100, with a default of 25. When `pagination.next_after` has an ID, send it as `after` to fetch
  the next page. A null value ends the list.
- `GET /api/v1/entries/{type}/{id}` returns one entry. Each entry includes its ID, type, title,
  slug, status, and configured field values. Relation lists retain their authored order and include
  only readable targets. A row pin is the existing integer entry ID. A group link is an object such
  as `{"group":"a5773ed2-5df9-4ffe-92d8-cf2f51de66f2","entry":42}`, where `entry` identifies
  the readable member on the token's Site. Links without a readable member on that Site are omitted.

Unreadable types or entries return 404. Invalid pagination arguments return 422 with field error
messages. The same Site and token requirements apply to both operations.


## Create and update entries

- `POST /api/v1/entries/{type}` requires the type's create ability and returns 201 with
  `data.id`. Send a title and the required values listed in the `create` schema from discovery.
- `PATCH /api/v1/entries/{type}/{id}` requires the type's update ability. It returns 200 with
  `data.id`. The `update` schema accepts just the fields to change. An omitted relation preserves its stored links.

Send a JSON object. The top-level keys are `title`, `slug`, `status` and `values`. A configured slug
field belongs in `values` under its handle. Otherwise `slug` accepts lowercase letters, digits
and single hyphens between segments. New entries start as drafts unless the request supplies another
status. Setting a draft or archived entry to `published` also requires the type's publish ability.

A supplied relation list replaces the field. An integer pins an exact entry. An object such as
`{"entry":42}` follows a translation group when the target has one, and pins other entries.
Set `mode` to `"pin"` to pin a translated entry, or `"follow"` to require a translation group.
A new target must be readable to the token on its Site. You can send back a `{"group":"…","entry":42}`
object from the read response to retain an existing group link on that field. The group identifies
what is retained even if its eligible entry has changed. A write that fails relation validation
leaves the entry and its history unchanged.

Invalid field values return 422. A missing publish ability returns 403. A storage conflict
returns 409. Entry writes accept only JSON.

## Upload media

Send `POST /api/v1/media/{type}` with a multipart `file` and an optional `title`. The token and
staff account need both create and publish abilities for the enabled media type. Discovery lists
the accepted extensions and intake size limits in `media_upload`. PHP or the web server can
impose a lower upload limit.

Uploads use the existing intake rules and stay private on the token's Site. Media sharing and
public delivery use their existing admin controls. A successful upload returns 201 with
`data.id`. A refused file returns 422. Requests that supply JSON instead of a multipart form
return 415.
