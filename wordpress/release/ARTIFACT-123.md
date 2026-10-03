# #123 deterministic local candidate

Source is frozen to reviewed main `27c3241be4eb5ce9772a128c3af1a381cd3c8843`.
Tooling commits do not become candidate source revisions. Invoke the tooling
from this branch against a separate clean checkout with HEAD at the frozen SHA.
No production PHP or accepted #122 asset is changed.

```sh
git clone https://github.com/MrDarkRoot/WriteLeash.git source
git -C source checkout --detach 27c3241be4eb5ce9772a128c3af1a381cd3c8843
python3 wordpress/release/build-wordpress-org.py --source source --sha 27c3241be4eb5ce9772a128c3af1a381cd3c8843 --output candidate
```

`candidate` must be a new directory outside `source`. Repeat with a second
clean checkout and a separate output directory. Compare SHA-256 and byte size
of both ZIPs. The builder rejects tracked modifications, untracked files,
ignored files, wrong HEAD, wrong requested SHA, missing manifest files and
nonregular Git entries. Inputs are read from committed Git blobs and checked
against the checkout. Output is removed if any gate fails.

Dependencies: Python 3.12+ standard library, Git and PHP CLI (7.4+ for accepted
audits). Exact executed versions are in `evidence.json`. ZIP uses ZIP_STORED,
sorted ASCII file names, DOS epoch 1980-01-01 00:00:00, Unix regular-file mode
0644, no directory entries, comments or extra fields. No compressor or zlib
version affects bytes; no UID/GID is encoded. Staged directories use 0755.
Evidence and outputs live outside the source tree and never enter the public
allowlist. No ZIP is committed.

The tree hash is SHA-256 of sorted JSON lines, each containing `path`, `sha256`
and `size`, with sorted object keys, compact separators and a final LF.
Equivalence checks compare the exact path-to-byte mapping, not only this hash.
The committed evidence records each runtime and asset size/hash and PNG
width/height (SVG viewBox). Assets are copied from the frozen source blobs.

Accepted #120 package preflight, literal closure, public runtime audit, source
and readme audits execute against the generated/extracted candidate. Inventory
candidate mode requires all PUBLIC_FREE_REQUIRED PHP and rejects nonmanifest
PHP; the existing source mode still requires the full historical inventory.
This avoids pretending that historical files belong in a public ZIP.

`python3 wordpress/release/test-artifact.py` runs network-free rejection tests
inside PR_FAST. It covers dirty and untracked trees, wrong SHA/HEAD, missing
manifest file, unsafe paths, extra runtime file, asset and historical shim in
ZIP, wrong root, duplicate ZIP entry, metadata mismatch, credential marker,
trunk/tag drift, ZIP/trunk drift and asset drift, plus two equal builds.

Reviewed first-party WordPress.org guidance on 2026-10-03:
https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#4-code-must-be-mostly-human-readable
The 37-file candidate contains readable PHP, readme and the GPL license. No
custom compiled/minified production asset needs separate source publication.
There is no added executable-code dependency or invented source-repository
requirement. Runtime source audit rejects compiled/dependency artifacts.

No SVN network operation, WordPress.org publication, GitHub Release, release
tag, merge, #124 implementation or RELEASE_FULL is performed. A change to
source/runtime/assets invalidates the exact-artifact block: regenerate and
rerun downstream audits.

## Executed ZIP smoke

Two independent detached clean source checkouts produced SHA-256
`2ecfd3edf667c15b36fc75fbb525551df07b075bdfc5696a07813a704eb6cf57`,
395959 bytes each. `artifact-123-evidence.json` contains the exact handoff.

Fresh WordPress 7.1.2 and WooCommerce 11.1.2 used a temporary MariaDB 10.11.14
Unix-socket fixture with the ordinary WordPress connection, no custom grants
or product SQL. WP-CLI `plugin install <generated-zip>` invokes the normal
WordPress Plugin_Upgrader path. The reviewed Woo ZIP hash was
`9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e`.
The generated candidate was installed and activated successfully. The
repository-only `zip-smoke.php` observer verified basename, one header, Core's
Woo dependency recognition, Products submenu and rendered page. A real local
HTTP login and GET to `wp-admin/edit.php?post_type=product&page=writeleash-bulk-prices`
returned 200 with the WriteLeash Bulk Prices page. No Redirection or Doctor
was installed. Installed runtime path/bytes exactly matched the candidate.

On a fresh 6.8.3 fixture Core refused the normal ZIP installation itself:
minimum WordPress 7.0. To independently exercise Core activation, the fixture
was provisioned with 7.1.2, the same generated ZIP installed inactive via
Plugin_Upgrader, then Core replaced with exact 6.8.3. WP-CLI activation was
refused for the minimum Core version; `validate_plugin_requirements` returned
`plugin_wp_incompatible`. The plugin remained inactive; zero WriteLeash tables
and zero products existed before and after the attempt. The installed 37-file
tree still exactly matched the ZIP, without a shim. This provision-then-Core-
downgrade step is necessary because supported normal installation cannot
install the candidate directly on 6.8.3. No source-tree staging was used.

These are packaging smokes only; they do not claim #125 acceptance or an
expanded database/version support envelope. Docker was unavailable locally;
no daemon/system configuration was changed. No RELEASE_FULL ran.
