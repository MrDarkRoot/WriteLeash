# Native Transaction-State Experiment

## Classification

`TEST FIRST`. This is a Phase 0 feasibility spike, not a production extension
and not a V0 support claim.

The experiment answers one question on PostgreSQL 16.4: can backend-local
state and transaction callbacks provide rollback-aware allowed consumption and
sticky top-level denial at the same time?

## Reproduce

From the repository root:

```bash
./experiments/native_tx_state/run.sh
```

The script builds a disposable image from `postgres:16.4-alpine`, initializes
the constrained role and table fixture, runs the tests, prints server-side
callback traces, and removes the container and volume. Every durable-state
assertion opens a fresh trusted-admin connection.

## Candidate

The C trigger function keeps two distinct kinds of backend-local state:

```text
top-level state:
    active
    consumed
    denied

subtransaction frame:
    subxid
    allowed-consumption delta
```

`consumed` records only allowed row-update events that are still live. An
allowed event in a subtransaction increments both `consumed` and that
subtransaction's delta. `SUBXACT_EVENT_ABORT_SUB` subtracts the delta, while
`SUBXACT_EVENT_COMMIT_SUB` transfers it to a subtransaction parent when one
exists. The event that would exceed five sets `denied` before raising the
immediate CommitCap error. No subtransaction callback clears `denied`.

`XACT_EVENT_PRE_COMMIT` runs while PostgreSQL can still abort the transaction.
If `denied` is true, the callback raises
`CommitCap top-level transaction denied after mutation budget violation`.
PostgreSQL then takes the top-level abort path. `XACT_EVENT_COMMIT` and
`XACT_EVENT_ABORT` perform backend-local cleanup.

The trigger lazily creates a frame when the library is first loaded inside an
already-active subtransaction. This matters because callback registration can
occur after that subtransaction's `SUBXACT_EVENT_START_SUB` event.

## Results

Observed on PostgreSQL 16.4:

| Test | Result | Evidence |
| --- | --- | --- |
| `CC-001` | **PASS** | Five events committed and a fresh connection observed five durable changes |
| `CC-002` | **PASS** | Event six denied one six-row statement and a fresh connection observed the baseline |
| `CC-003` | **PASS** | Five statements consumed one shared budget; statement six denied and nothing became durable |
| `CC-008` | **PASS** | `ROLLBACK TO` recovered statement execution but not commit authority; final `COMMIT` errored and a fresh connection observed the baseline |
| `CC-009` | **PASS** | Aborting three allowed savepoint events changed live consumption from three to zero; five replacements committed |
| `CC-024` | **PASS** | PL/pgSQL caught event-six error, but final `COMMIT` errored and a fresh connection observed the baseline |

The same-backend cleanup probes also passed:

- A transaction consumed three and committed; the next transaction consumed
  five and committed.
- A transaction consumed three and rolled back; the next transaction consumed
  five and committed.

These results make this mechanism class **VIABLE FOR FURTHER TESTING**. They do
not make the experiment production-ready or any operation supported.

## Observed Lifecycle

Server `LOG` traces showed these callback orders:

```text
normal COMMIT:
    XACT_PRE_COMMIT
    XACT_COMMIT

normal ROLLBACK:
    XACT_ABORT

SAVEPOINT RELEASE:
    SUBXACT_START
    SUBXACT_PRE_COMMIT
    SUBXACT_COMMIT

ROLLBACK TO SAVEPOINT after denial (CC-008):
    SUBXACT_START                 -- original savepoint
    event six sets denied
    SUBXACT_ABORT                 -- denied remains true
    SUBXACT_START                 -- PostgreSQL restarts the savepoint with a new subxid
    SUBXACT_PRE_COMMIT
    SUBXACT_COMMIT
    XACT_PRE_COMMIT               -- sees denied=true and raises ERROR
    XACT_ABORT

ROLLBACK TO SAVEPOINT for allowed work (CC-009):
    SUBXACT_ABORT                 -- consumed 3 -> 0, denied=false
    SUBXACT_START                 -- restarted savepoint
    SUBXACT_PRE_COMMIT
    SUBXACT_COMMIT
    XACT_PRE_COMMIT
    XACT_COMMIT

PL/pgSQL exception recovery (CC-024):
    SUBXACT_START
    event six sets denied
    SUBXACT_ABORT                 -- denied remains true
    XACT_PRE_COMMIT               -- sees denied=true and raises ERROR
    XACT_ABORT

uncaught top-level denial:
    XACT_ABORT
```

For `CC-009`, the extension library was first loaded by an update inside the
original savepoint, so its original `SUBXACT_START` occurred before callback
registration and was not logged. Lazy frame creation still let the observed
`SUBXACT_ABORT` unwind all three allowed events.

After `CC-008`'s `ROLLBACK TO` and after `CC-024`'s exception handler, an
ordinary `SELECT` executed successfully. The poisoned state remained and the
final `COMMIT` failed. The trigger also rejects any later protected update
while `denied` is true.

## Memory Lifetime

The fixed state record is C static storage and therefore private to one
PostgreSQL backend. Subtransaction frames are explicitly allocated under
`TopMemoryContext`, not `CurTransactionContext`, so PostgreSQL does not delete
them as part of a subtransaction abort. The callbacks explicitly merge,
subtract, and free frames.

Both top-level terminal paths reset all scalar fields and free every remaining
frame:

- successful top-level completion: `XACT_EVENT_COMMIT`;
- top-level failure or rollback: `XACT_EVENT_ABORT`.

An error raised at `XACT_EVENT_PRE_COMMIT` is followed by the observed
`XACT_EVENT_ABORT`, which performs the same cleanup.

## Why The Table Mechanism Failed

The existing PL/pgSQL experiment stores event-six accounting in an ordinary
transactional table and raises its denial error in the same recoverable
subtransaction. Savepoint or PL/pgSQL exception rollback removes both the
increment and the error state. Nothing remains to prevent top-level commit.

This experiment instead keeps the sticky bit outside subtransaction-owned
memory while using explicit per-subtransaction deltas for the state that must
roll back. It does not make all accounting irreversible.

## Callback Interface

The experiment uses declarations from PostgreSQL 16.4 installed server
headers:

- `RegisterXactCallback` and `RegisterSubXactCallback` are explicitly described
  in PostgreSQL source as callbacks for dynamically loaded modules.
- `TopMemoryContext` is exported for loadable modules.
- `GetCurrentSubTransactionId`, `GetCurrentTransactionNestLevel`, and
  `IsSubTransaction` are exported accessors; the experiment does not access
  private transaction-state structures.

PostgreSQL's `CommitTransaction()` invokes `XACT_EVENT_PRE_COMMIT` before the
commit decision and describes that region as able to raise an error and enter
the abort path. PostgreSQL 16.4's in-tree `postgres_fdw` can also raise an error
from its pre-commit callback. By contrast, `XACT_EVENT_COMMIT` is after the
commit decision and is used here only for noncritical cleanup.

These are defensible server-extension interfaces, but PostgreSQL provides no
stable cross-major C ABI. The exact callback placement and behavior must be
revalidated for every proposed supported major version.

## Privilege Model

The protected writer remains a non-superuser, non-owner role. It receives only
database `CONNECT`, `public` schema `USAGE`, `SELECT(id)`, and `UPDATE(status)`.
It has no trusted-schema access, direct trigger-function `EXECUTE`, table-wide
`SELECT` or `UPDATE`, unsupported DML, DDL, trigger, owner-role membership, or
mutable accounting object. The protected relation and trigger function are
owned by the separate `NOLOGIN` `commitcap_owner` role. No additional writer
privilege was required for the native experiment.

## Known Risks And Unknowns

- Only the six requested tests, lifecycle cleanup, and one savepoint-release
  probe were run. This is not the complete Gate 1 matrix.
- Nested savepoint accounting beyond the tested paths remains uncharacterized.
- Interactions with other transaction callbacks, callback ordering between
  extensions, and errors from later pre-commit work remain uncharacterized.
- PostgreSQL majors other than 16.4 remain untested.
- Parallel execution, prepared transactions, connection-pool role reuse,
  concurrency, triggers beyond the one generated trigger, cascades,
  partitions, and alternate mutation forms remain untested or out of scope.
- Backend termination and out-of-memory behavior were not fault-injected.
- The experiment hard-codes one relation's update budget of five and contains
  no policy, configuration, shared authority, or production installation
  design.
