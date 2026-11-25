# Schema storage and admin reads at 10k, 100k and 1M entries

[Issue #261](https://github.com/adamgreenwell/kitsune/issues/261) records six Linux SQLite floor/control
cases and nine native database cases. The measurements use the synthetic corpus and application at
[`4e6f905`](https://github.com/adamgreenwell/kitsune/tree/4e6f9059d841533b7ff45e18685da2c7022392ab).
The portable end-to-end launcher remains open.

## Corpus and measurement

Each size uses three orgs and five sites. The largest org holds 65% of entries across two sites
and its shared assets. The second holds 30% across two sites and shared assets. The third holds 5%
on one site. The nine populations are 30% primary articles, 15% sibling articles, 15% shared assets,
and 5% primary assets in the largest org, then 15%, 5%, 5%, and 5% in the second org, then 5% articles in
the third. The corpus has org-owned article and asset types, rich text, an indexed score, category and
tag values, ordered relations, revisions and physical private media. Rich-text samples are 768, 4,096
or 32,768 bytes, and private PNGs are 70-byte 1×1 fixtures.

Each case has 25 sequential warm observations per metric. The tables show median / observed p95 in
milliseconds, rounded to whole milliseconds. Observed p95 is sample 24 of 25 after sorting. Dashboard,
search and deep-page times end when the browser is ready. Save times end at the verified Saved notification
and exclude filling the fields. Relation-picker times include the 1,000 ms debounce. The selected rows below
use the owner on site 1.

## Linux SQLite

PHP 8.4.25 and SQLite shared the same Linux image and 116-package graph in all six cases. The floor used
1 vCPU and 1 GiB. The control used 2 vCPUs and 2 GiB. Both used 128 MiB PHP heap, 128 PIDs and zero swap.
The three pairs contain 2,568 audited metric rows. The 10k control collected its complete observations
before the controller failed in final bookkeeping. An independent audit accepted the records and retained the
failed controller result.

| Entries | Profile | Dashboard | Selective list search | Deep page | Broad relation picker | Save 32 KiB body and score |
| ---: | --- | ---: | ---: | ---: | ---: | ---: |
| 10,000 | Floor | 104 / 113 | 110 / 121 | 125 / 136 | 1,070 / 1,081 | 233 / 246 |
| 10,000 | Control | 102 / 117 | 120 / 148 | 128 / 155 | 1,061 / 1,078 | 235 / 244 |
| 100,000 | Floor | 120 / 141 | 151 / 161 | 149 / 165 | 1,124 / 1,136 | 236 / 260 |
| 100,000 | Control | 114 / 129 | 151 / 170 | 148 / 159 | 1,113 / 1,172 | 236 / 254 |
| 1,000,000 | Floor | 265 / 308 | 660 / 748 | 579 / 651 | 4,412 / 4,640 | 241 / 267 |
| 1,000,000 | Control | 265 / 277 | 587 / 630 | 520 / 547 | 1,848 / 1,887 | 243 / 263 |

The 1M broad picker had the largest separation between the two profiles in this table. The experiment
changed CPU and memory together, and each profile ran once per size. These serial, synthetic observations
support a targeted query investigation and leave production tails, realistic media and concurrency open.

## Native database cases

PostgreSQL, MySQL and MariaDB used 2 vCPUs and 2 GiB for each database container. PHP ran on macOS with a
128 MiB heap and host CPU and RAM available. The nine cases contain 3,852 audited metric rows. Stopped
database directory allocation comes from file metadata in the Linux guest. Engine-reported allocation uses
engine-specific definitions, and the file totals include each engine's bookkeeping.

| Engine | Entries | Dashboard | Deep page | Broad relation picker | Stopped DB allocation (MiB) |
| --- | ---: | ---: | ---: | ---: | ---: |
| PostgreSQL | 10,000 | 158 / 176 | 201 / 315 | 1,104 / 1,138 | 54 |
| MySQL | 10,000 | 108 / 117 | 139 / 150 | 1,096 / 1,110 | 147 |
| MariaDB | 10,000 | 102 / 112 | 135 / 144 | 1,070 / 1,095 | 150 |
| PostgreSQL | 100,000 | 165 / 177 | 215 / 263 | 1,128 / 1,146 | 434 |
| MySQL | 100,000 | 144 / 151 | 179 / 320 | 1,167 / 1,196 | 900 |
| MariaDB | 100,000 | 140 / 153 | 404 / 444 | 1,117 / 1,144 | 894 |
| PostgreSQL | 1,000,000 | 299 / 325 | 694 / 728 | 1,348 / 1,383 | 4,269 |
| MySQL | 1,000,000 | 1,899 / 2,021 | 12,056 / 13,245 | 23,028 / 26,683 | 8,345 |
| MariaDB | 1,000,000 | 2,443 / 2,613 | 13,103 / 14,146 | 12,695 / 13,976 | 8,303 |

The longer MySQL and MariaDB recovery timeouts separate those cases from their original failed short
collection windows. The original SELECT and binding sets have matched estimated plans, including
partial and diagnostic profiles. The timing statistics use the completed sample vectors. MySQL's 1M
picker spent a median 21.97 seconds in the original title SELECT. This gives [#331](https://github.com/adamgreenwell/kitsune/issues/331)
a measured starting point before any query or index change. The 1M deep-page reads on both InnoDB
engines also warrant separate plan analysis.

## Measurement records

The retained reports and independent audit receipts are indexed in
[#261](https://github.com/adamgreenwell/kitsune/issues/261). The table summarizes the CSVs whose SHA-256
values match their validation receipts.

| Export | SHA-256 |
| --- | --- |
| SQLite 10k pair | `3c437ccbdff6263b27dff9c5108c74040ed31457db734e2ab2f4666eef22ecfd` |
| SQLite 100k pair | `044579cc09f9598f703f3cce5ec1558569104cc0536d862e6ec606a8410cf32f` |
| SQLite 1M pair | `ff86f59e98448e84026d564eaae0f0fcb20bab2f8354a3b0537a507356e23c0a` |
| Nine native observations | `bb4fe322a35eaec1d0c1ac0e037c8f19fbd659c0ff43888404fd160e928271db` |
