# Blade themes

Kitsune v1.1 can render a published Site with a host-installed Blade theme. A Site with no usable
selection continues to serve its welcome page. The Blade surface is provisional through v1.1.
The extension stability rules begin at the v1.2 freeze.

## Install a theme

Put the theme under `resources/views/themes/<handle>`. Handles start with a lowercase letter and
can contain lowercase letters, digits and hyphens. Add a `theme.json` manifest and a readable
`home.blade.php` template.

```json
{"entry_types":["article","page"]}
```

The manifest lists the entry types the operator intends to publish with this theme. Use an empty
list for a Site that needs only a home page. Install Blade files as trusted host code and choose
which fields each template renders.

Run `php artisan kitsune:theme list` to discover usable themes. Select one using the Org slug and
the Site handle.

```sh
php artisan kitsune:theme select journal --org=news --site=english
php artisan kitsune:theme clear --org=news --site=english
```

Each Site has its own choice. A missing or malformed manifest or an absent home template makes
the theme unavailable. An unavailable selection falls back to the welcome page.

## Templates

- The Site root renders `home.blade.php`.
- `/{type}/{slug}` relative to the Site's canonical prefix renders `entries/{type}.blade.php`.
- `entry.blade.php` is the fallback for a listed type without a dedicated template.
- A missing entry template or an unavailable, draft, or unlisted entry returns 404. Routes
  already owned by the application, reader accounts or commerce take precedence.

When editing a published entry, leave **Redirect the previous address** selected to keep its
former slug. A former address sends visitors directly to the entry's latest public address.
The redirect is temporary and uncached so it follows later edits and stops serving when the
entry is unavailable. A published entry using the old address takes precedence. If its editor
later renames it and keeps the old address, the redirect follows that newer entry.

All templates receive `$site`, `$locale` and `$direction`. Use `lang="{{ $locale }}"` and
`dir="{{ $direction }}"` on the document root. Entry templates also receive `$entry`,
its public `$entryPath`, and `$related`, which maps relation field handles to their readable
entries in authored order. Use `$relatedPaths[$entryId]` for a related entry's same-host link.
Destinations owned by another Site or a host route are omitted.

Use `$menu('main')` on a home or entry template to render the Site's menu named `main`.
Each link has a `label`, a same-host `path` including the Site prefix, and an ordered `children` list.
A missing or empty menu returns an empty list. Use Blade's escaped output for both the path and label.

Open **Menus** in the Site's admin to create, reorder or remove a named menu. Its theme handle
stays stable when you change the display name. Choose the Site home or a local entry. Following
translations uses the current Site's member of the group. Pinning keeps the chosen entry.
Unavailable destinations stay in the editor and are omitted from the public menu.

Open **Redirects** in the Site's admin to review, add or remove former addresses. Supply the
former type handle and slug, then select a reachable public entry on the same Site. Access to
both screens requires an Org owner or a Site-admitted editor with wildcard view and publish
grants.
Group links resolve on the current Site. A missing local translation is omitted. A row pin is
rendered only while its exact entry remains publicly readable. Media delivery stays behind the
existing media routes and their visibility rules. Adding a media type to the manifest does not
make its records or files public.

The theme runs on Blade and the existing database at request time. A standard SQLite
installation can render it without Node, a separate service or a worker.

## Response caching

Kitsune renders theme HTML for each request and sends `Cache-Control: private, no-store` on
theme pages. Trusted Blade can access the request, session or reader state. Each response
uses the current Org, Site, locale, entries and menus. Kitsune's release script rebuilds
compiled Blade template code into that release’s private view cache. Each response renders
that code against the current request.

A support integration can place an inert, identity-free placeholder in a theme. The separate
support module will authorize the current reader on a same-origin POST under the current
Site's canonical prefix. Keep that response private and out of storage, including when
authorization is refused. The module remains subject to the provider qualification in ADR-036.
