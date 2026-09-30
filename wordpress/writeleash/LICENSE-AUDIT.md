# WordPress Free V0.1 licensing and source provenance (#64)

> This records the license/provenance review for the existing advanced V0.1
> technical substrate; it does not approve a public release or define the
> intended Free product. #106 governs planned WooCommerce bulk price changes,
> which are not implemented; #107–#112 and the later rewritten release gates
> remain, and public release is deferred.

Reviewed 2026-09-29. The founder selected **GPL version 2 or any later
version** (`GPL-2.0-or-later`) for the **WordPress plugin subtree**. The main
plugin header and notice make that choice explicit; `LICENSE` is the verbatim
GNU GPL version 2 text from
<https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt>, SHA-256
`edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6`.
The notice, not a modification of the GNU license document, grants the
“or later” choice. Repository-root `LICENSE-TODO.md` concerns the broader
research repository and is not a license file in the WordPress distribution.

## Proposed distributable provenance inventory

| Item | Source / copyright evidence | Terms / notice |
| --- | --- | --- |
| `writeleash.php`, `uninstall.php`, `includes/*.php` | Tracked first-party WordPress subtree; Git history from `dcbb592` onward, later contributions in this same repository. | Founder-selected `GPL-2.0-or-later`; main-file header/notice and full GNU GPLv2 text shipped. No different file-level license notice found to displace it. |
| `readme.txt`, public operator guide | WriteLeash-owned release documentation in this repository. | Same project terms; no third-party content copied. |
| `LICENSE` | Free Software Foundation GNU GPLv2 verbatim text, URL/hash above. | GNU license notice preserved in full, including FSF attribution; do not edit the license text. |
| Bundled vendor/library/snippet/media/font/JS/CSS/compiled asset | **NONE** in the proposed package; runtime inventory has only human-readable first-party PHP and the text documents above. | No third-party bundled notices required. |
| Redirection **5.5.2** | **External installed plugin, not bundled or copied.** Official WordPress.org [tagged readme](https://plugins.svn.wordpress.org/redirection/tags/5.5.2/readme.txt) declares **GPLv3** and [tagged license.txt](https://plugins.svn.wordpress.org/redirection/tags/5.5.2/license.txt) contains GNU GPLv3. | Its own GPLv3 terms remain its own. WriteLeash interoperates through its reviewed installed APIs; Redirection source or assets are not redistributed or relicensed here. |
| WordPress core / Plugin Check / test fixtures | Installed separately by the fixture; not bundled in the distribution allowlist. | No third-party files or notices copied into the plugin. |

`git log --follow` shows repository commits authored as `MrDarkRoot` and
`Tran Khanh Duy` using a `MrDarkRoot@users.noreply.github.com` address. Git
author fields are *not* a legal determination of copyright ownership or a
WordPress.org contributor identity. No named copyright holder is invented in
the public header/readme. No contrary copyright notice, copied third-party
source, or incompatible bundled item was found in the proposed plugin files;
ownership attribution remains unspecified rather than fabricated.

**#64 gate: PASS** for the audited WordPress plugin contents and the founder's
selected license. This does not purport to license the entire research repo or
make a WordPress.org approval decision. Recheck against the exact #77 package
at #76.
