# Extension compatibility and deprecation

The compatibility promise starts with the first stable release that publishes the
v1.2 extension inventory. The freeze under [#235](https://github.com/adamgreenwell/kitsune/issues/235)
selects the supported PHP declarations and behaviors from the
[extension surface audit](extension-surface.md), including any supported Blade
inputs and menu shapes. Pre-freeze examples help authors rehearse the workflow.
The inventory establishes the supported contract. Maintainers may change
`@internal` code between releases.

## Deprecate a supported capability

1. Introduce and document the replacement. Keep the old declaration, argument names,
   and behavior available to existing consumers for the rest of that major.
2. Announce the first deprecated release, replacement, and migration guidance in
   the release notes and the affected reference. Include an appropriate runtime notice
   when the old behavior provides a reliable place to emit one.
3. Record the deprecation in the release inventory. Keep a test for an unchanged
   consumer of the old behavior and add one for the replacement.
4. Consider removal in the next major and describe the migration in that major’s
   upgrade guide.

## Check a release candidate

Use the most recent published inventory for the same major and its retained consumer
probes from the verified release source. Prepare the candidate inventory and a
trusted installation of the exact candidate packages. Run the check from a trusted
Kitsune development checkout.

```sh
php bin/check-extension-compatibility.php \
  /path/to/published-extensions.json /path/to/candidate-extensions.json \
  /path/to/candidate/vendor/autoload.php /path/to/published-probes /path/to/candidate-probes
```

An inventory is a JSON object with a stable `release` such as `1.2.0` and an
`exports` list. Each export records these fields.

| Field | Value |
| --- | --- |
| `name` | A Kitsune PHP class or member name, or a lowercase dotted behavior identifier such as `blade.menu-children`. |
| `kind` | `class`, `interface`, `trait`, `enum`, `enum_case`, `method`, `protected_method`, or `behavior`. |
| `status` | `stable` or `deprecated`. A deprecated export also has `since`, `replacement`, and `migration_guide`. |
| `probe` | The name of a PHP script in the relevant probe directory, without `.php`. |
| `probe_sha256` | The SHA-256 of the complete probe script. Keep its consumer source and fixture input in that script. |

Each probe receives the candidate’s Composer autoloader as its first argument. It
exercises the actual API or rendered behavior. It exits successfully and prints one
line containing `compatible` when the checks pass. The tool starts a separate PHP
process for runtime inspection and for each distinct probe.

A same-major candidate retains every published export and the digest of its probe.
The checker verifies the candidate’s PHP declarations, runs the unchanged consumers
from the published probe directory, and runs probes for new exports from the candidate
directory. At the freeze, qualify the first inventory against its own probes. For a
new major, use the prior published inventory for comparison, qualify the new
inventory, and document the removed capabilities in the upgrade guide.

The candidate checker and synthetic regression fixtures prepare this workflow. The
v1.2 freeze still needs to select the inventory, retain the real consumers, and make
the check a release admission step. [Versioning and upgrades](versioning-and-upgrades.md)
