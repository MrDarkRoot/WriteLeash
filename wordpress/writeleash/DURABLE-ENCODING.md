# Durable UTF-8 storage (#171)

The default WordPress connection and every character column in the five Free
job/journal/Undo tables use utf8mb4. JSON remains the original canonical
unescaped-Unicode serialization. Opaque plan/public IDs and the Undo fingerprint
use utf8mb4_bin to retain exact case-sensitive comparison. Their existing ASCII
value grammars are unchanged; permitting UTF-8 storage does not permit new IDs.

The previous tables combined utf8mb4 text with ascii/ascii_bin identity columns.
Core wpdb inferred ascii for the entire mixed table and rejected a raw prepared
INSERT containing Unicode JSON, although plan_json itself was utf8mb4. ASCII
queries skipped that validation path. The same layout existed in job items,
the price journal and both Undo tables; changing only jobs would not fix seeding
and all durable boundaries.

The normal explicit schema installers repair old physical encodings in place.
They run before import/approval/Undo creation transactions, never on read-only
History, activation, or in-flight item/Undo transactions. Readiness checks
accept only the new schema or the known legacy ASCII-key
layout, so old ASCII History and recovery remain usable. Explicit new
import/approval/Undo setup upgrades physical encoding before starting a writer
transaction. Logical row/layout versions remain jobs=1, journal=2 and Undo=1; #107 plan schema/hash versions remain unchanged.
Physical metadata, not an extra option or version shortcut, determines whether
the idempotent encoding upgrade is required. Journal schema 1's established
identity backfill still validates stored material before accepting schema 2.

Each existing table is altered atomically without dropping it, changing column
lengths, writing rows, or rewriting JSON/evidence/progress. Supported old text
encodings are ascii and UTF-8 (utf8/utf8mb3/utf8mb4). A SQL-side byte-preserving
conversion/UTF-8 validation checks every character column before DDL without
loading all history into PHP. Unknown binary/blob/other storage or invalid
bytes fail explicitly; no coercion or undecodable-row discard is allowed.

ALTER temporarily uses STRICT_ALL_TABLES and restores the original session mode.
The existing transaction transport's savepoint probe prevents implicitly
committing caller work. Metadata caches on the same wpdb connection are
invalidated after DDL so the immediately following Unicode import uses the new
schema. Partial encoding upgrades remain recognizable for legacy reads/recovery;
new
write setup refuses until its family migration finishes. Replay converges and
retains all existing row values. Binary comparisons and indexes keep their purpose.

A large retained history can require a metadata-lock wait and table rebuild.
This is an explicit lazy setup upgrade, not a background copy/drop migration or
new support/scale claim. Existing ASCII reads/Resume keep their original behavior
on the known legacy layout; explicit setup/import/approval upgrades storage.
Migration itself never changes targets, item states, counters, timestamps or
Undo eligibility.
