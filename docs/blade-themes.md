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

All templates receive `$site`, `$locale` and `$direction`. Use `lang="{{ $locale }}"` and
`dir="{{ $direction }}"` on the document root. Entry templates also receive `$entry` and
`$related`, which maps relation field handles to their readable entries in authored order.
Group links resolve on the current Site. A missing local translation is omitted. A row pin is
rendered only while its exact entry remains publicly readable. Media delivery stays behind the
existing media routes and their visibility rules. Adding a media type to the manifest does not
make its records or files public.

The theme runs on Blade and the existing database at request time. A standard SQLite
installation can render it without Node, a separate service or a worker.
