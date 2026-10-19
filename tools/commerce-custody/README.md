# Commerce identifier ownership probe

This is an opt-in measurement tool for issue [#263](https://github.com/adamgreenwell/kitsune/issues/263),
not an uninstall command. It creates, reads and drops only randomly named synthetic tables in a dedicated
`kitsune_custody_probe_…` database. Never point it at an installation. It requires PDO MySQL and no Node;
the default test suite does not invoke it or require a server/container.

Provision a disposable engine with `lower_case_table_names=0` or `1` **at initialization**, create an empty
`kitsune_custody_probe_…` database, and grant a disposable account access to it. Supply the connection in
`KITSUNE_PROBE_HOST`, `KITSUNE_PROBE_PORT`, `KITSUNE_PROBE_DATABASE`, `KITSUNE_PROBE_USERNAME` and
`KITSUNE_PROBE_PASSWORD` through the environment, not command history. Then run:

```sh
KITSUNE_CUSTODY_PROBE=disposable php tools/commerce-custody/probe.php > identifier-proof.json
```

The report includes the exact server version, actual folding setting, connection charset, quoted-DDL setting,
requested names, catalogue names, DDL identifiers, byte lengths and independent row-resolution witnesses. It
omits credentials, complete DDL and customer data. Each fixture is removed in `finally`; audit the disposable
database as empty before removing its owned container/volume. Failure is a refusal, never permission to retry
against an existing installation or bypass custody.

## Measured boundary

The runtime resolves candidates through the **write** server's `SHOW CREATE TABLE`, parses only its quoted
physical identifier and compares it byte-for-byte with the current-schema catalogue name. The result's `Table`
field and PDO column metadata echo the requested spelling and are **not identity evidence**. Every possible
ASCII `commerce_` boundary is considered, including one inside a configured prefix or future table suffix.
A candidate longer than the engines' 64-character identifier limit cannot name an owned table and is skipped.
Missing candidates (1146) are unowned; every other metadata error refuses. Proven tables are read by their fully
quoted physical name with no builder prefix applied again. No Unicode conversion or collation equality is used.

Initial local measurements (2026-10-18, PDO MySQL/utf8mb4, isolated Linux Docker volumes):

| Engine | Setting | Result |
| --- | --- | --- |
| MySQL 8.0.46 | 1 | All nine catalogue/DDL/row identity witnesses passed |
| MySQL 8.4.7 | 1 | All nine witnesses passed |
| MariaDB 10.6.28 | 1 | All nine witnesses passed |
| MariaDB 11.4.2 | 1 | All nine witnesses passed |

In each setting-1 engine, `İ` becomes `i`, `ΟΣ` becomes `οσ`, `K` becomes `k`, `Ä` becomes `ä`, and `ẞ`
stays `ẞ`; composed `é` and decomposed `e` plus U+0301 remain distinct. Dotted I and Kelvin sign shorten in
UTF-8, so stripping the original prefix's byte length would slice into the table suffix. Backticks and an
embedded `commerce_` prefix also retain a correct physical witness. These observations are engine measurements,
not a normalization algorithm or a promise about every Unicode character or future release.

Setting 0 retains the prior exact-case path. Setting 2 remains unsupported for non-ASCII prefixes: it needs a
case-insensitive filesystem and has not been qualified here. Unquoted/unknown DDL shapes, temporary shadows,
denied metadata, failed row reads, unknown folding settings and schema errors refuse. The new path requires
the ordinary quoted `CREATE TABLE` format (`sql_quote_show_create=1`; incompatible SQL modes refuse).

The focused Pest group is `tests/Core/Modules/CommerceUnicodeCustodyTest.php`; run it on each setting-1 engine
alongside `CommerceMySqlCustodyTest`, the general commerce custody/lifecycle tests and PostgreSQL schema probes.
It covers retained/trashed/foreign-org and future rows, empty admission, similar installations, a local empty
counterpart, repeated namespace boundaries, quoting, write routing, temporary shadows, failures, and preservation
of the real module receipt before any teardown DDL. The existing ASCII and exact-case tests remain separate. The two `Unicode table prefixes` CI jobs initialize
setting-1 MySQL/MariaDB explicitly, assert that setting before Pest, retain identity/JUnit receipts and audit
empty fixture data before removing their owned containers/volumes. The service-free bare-clone suite is unchanged.

For an actual operator refusal, keep the module disabled and its receipt/data intact. Do not change a prefix,
folding setting or database name to make the refusal disappear. Stop all writers, take a verified backup and
establish the deployment's recovery procedure before any separately authorized teardown. A custody check is
not a concurrent-writer fence or a guarantee that multi-statement rollback can recover partial DDL.

Primary contracts: [MySQL SHOW CREATE TABLE](https://dev.mysql.com/doc/refman/8.4/en/show-create-table.html),
[MariaDB SHOW CREATE TABLE](https://mariadb.com/docs/server/reference/sql-statements/administrative-sql-statements/show/show-create-table),
[MySQL identifier limits](https://dev.mysql.com/doc/refman/8.0/en/identifier-length.html), and
[MariaDB identifier case sensitivity](https://mariadb.com/docs/server/reference/sql-structure/sql-language-structure/identifier-case-sensitivity).
