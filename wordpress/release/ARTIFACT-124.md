# B1 — artifact regeneration after C124-001

Source fix PR #140 is merged. Remote main was verified as exactly
`5ccc75c1d895a2fb379866ce4cf0e9901d1c276c` before building. This release branch changes tooling
and evidence only; unique production runtime diff is NONE. Runtime, manifest,
listing, claims and accepted #122 assets are unchanged from that main.

OLD ZIP `2ecfd3edf667c15b36fc75fbb525551df07b075bdfc5696a07813a704eb6cf57`
is superseded / compliance-blocked, NOT authorized for #125. Historical
`artifact-123-evidence.json` and the original record are preserved; PR #139
was not modified. Closed #123/#124 issue state does not constitute acceptance.

NEW ZIP `7932ed450fa4fdd43902cfd74fb264f3db14271a63030726066c17e677d4223e` (396314 bytes) is regenerated after the
C124-001 source fix. #124 full re-audit is still required and NOT YET RUN.
#125 is NOT authorized. No publication, release tag, merge or RELEASE_FULL.

## Reproduction and package gates

Invoke the builder from this tooling branch against each of two separate clean
detached source clones at the SHA above:

```sh
python3 wordpress/release/build-wordpress-org.py --source /tmp/writeleash-124-regen-source-1 --sha 5ccc75c1d895a2fb379866ce4cf0e9901d1c276c --output /tmp/writeleash-124-regen-build-1
python3 wordpress/release/build-wordpress-org.py --source /tmp/writeleash-124-regen-source-2 --sha 5ccc75c1d895a2fb379866ce4cf0e9901d1c276c --output /tmp/writeleash-124-regen-build-2
cmp /tmp/writeleash-124-regen-build-1/writeleash-0.1.0.zip /tmp/writeleash-124-regen-build-2/writeleash-0.1.0.zip
```

Outputs must not preexist and must be outside source. Both ZIP hashes and sizes
match; bytes compare equal. Existing dirty/untracked/ignored/wrong SHA/wrong
HEAD, manifest, regular-file and Git blob/checkout gates remain intact. Rejection
tests also exercise source symlinks, mismatched checkout bytes and unsafe output.
The test now builds from separate clean clones and gates extracted nonce paths.
Deterministic sorted entries, DOS epoch, Unix regular 0644, ZIP_STORED, no
comments/extra fields/directories/duplicate or traversal entries are preserved.

`artifact-124-evidence.json` records all 37 runtime paths with hash/size, the
manifest hash, exact ZIP identity, trunk/tag hashes, unchanged asset hashes,
commands and tool versions. No ZIP/evidence enters the public runtime. Accepted
package-preflight, inventory candidate mode, source/readme, literal closure and
credential/path scans pass: 37 files, 35 PHP, one header, no historical leakage.
The unchanged claim matrix is audited against the extracted readme in a temporary
repository-shaped staging tree. ZIP/trunk/tag exact path-to-byte equality passes.

## Artifact nonce and install evidence

`nonce-artifact-audit.py` inspects extracted/installed class-free-admin.php:
all four verification sites use a typed helper with string validation, then
wp_unslash, sanitize_text_field and wp_verify_nonce. Preview uses the common gate;
Approve/Resume/Undo normalize directly. Raw request-derived verification: NONE.
Preview/Approve/Resume/Undo: PASS.

Fresh WP 7.1.2 and Woo 11.1.2 used an isolated MariaDB 10.11.14 Unix socket,
ordinary WordPress connection and no custom grants. Normal WP-CLI plugin install
uses Plugin_Upgrader on the NEW ZIP, followed by successful activation.
`zip-smoke.php` verified writeleash/writeleash.php, one header, Core Woo dependency,
Products menu and rendered page. Authenticated HTTP GET to Products → Bulk Prices
returned 200 with the page. No Redirection, Doctor or privileged DB setup.
Installed tree exactly equals NEW ZIP.

The repository observer `wordpress/tests/admin/nonce-normalization.php` ran via
WP-CLI eval-file with the ZIP-installed plugin; it delegates to real WordPress
normalization/verification and passed 111 assertions. It covers all four actions,
valid/invalid/slashed/HTML nonce input, absent/nonstring input, method/capability
and cross-actor controls and unchanged rejected-request durable/product state.
No source-tree plugin files were substituted. Observer code is outside the ZIP.

Fresh WP 6.8.3 refused normal ZIP installation for minimum WP 7.0; no candidate
was installed and zero WriteLeash tables/products existed. A second site installed
the NEW ZIP inactive on 7.1.2, then replaced only Core with exact 6.8.3. Core
refused activation with plugin_wp_incompatible; plugin inactive, zero durable
WriteLeash tables/products. Its installed tree still equals NEW ZIP, with no shim.
These are packaging/focused nonce smokes, not the #124 full compliance re-audit.

## CI ownership

Only wordpress/release tooling/evidence changes. Selected runtime owners: NONE.
PR_FAST and CI_COVERAGE must pass on the exact final PR head; no RELEASE_FULL.
