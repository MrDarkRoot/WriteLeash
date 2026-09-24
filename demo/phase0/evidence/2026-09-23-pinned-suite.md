# Pinned-image demo and native nonregression evidence (current)

## Complete suite transcript from clean detached checkout (2026-09-24 UTC)

The original local-only suite capture described below was no longer available.
The full unchanged suite was therefore rerun at the **same publicly reachable
implementation SHA** `a51c2c794e5aad7f8a22e3b6d5785132a805a61e` in a
clean detached-HEAD checkout at `/tmp/opencode/commitcap-demo-suite-a51c2c7`.
The checkout's `git status --porcelain` was empty before and after execution;
`HEAD` was detached and equal to the tested SHA. Exact command in that checkout:

```bash
COMPOSE_PROJECT_NAME=commitcap_demo_suite COMMITCAP_LOG=/tmp/opencode/commitcap-suite-a51c2c7-detached.txt ./experiments/native_tx_state/run.sh
```

Process exit status **0**; final suite line `Native suite exit status: 0`.
Skipped cases: **none**; the full integrated harness ran. The image build
resolved `postgres:16.4-alpine` to
`sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`;
the suite reported
`Base image: postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`
and `PostgreSQL: 16.4`. Its Compose project and volume were removed by the
unchanged suite's exit trap.

**[Complete sanitized 842-line suite-output transcript](2026-09-24-native-suite-a51c2c7.txt)**
is committed beside this report, with a seven-line provenance header containing
the full command and exit code. Only trailing spaces on ten Compose progress
lines were removed; all other suite output is byte-identical to the captured
`COMMITCAP_LOG`. Raw capture SHA-256:
`4825e85ad88e9a2968e587084783cc63237020f60fefcaa3157c8897e19e6cb5`;
committed transcript SHA-256 (including provenance header):
`15209fb64ebc0a598241ef8a6f5b23166022622cc03df3629054156157a1d209`.
The log contains no credentials or personal data.

At the tested SHA, the five executed fixture files had the following blob
hashes. `git ls-tree` on both the detached implementation commit and the PR
evidence branch agreed, and `git hash-object` of every executed checkout file
agreed with those hashes:

```text
experiments/native_tx_state/run.sh                               3f8792d51893c0769063a7f14ce7d4a79c6db6bd
experiments/native_tx_state/numeric_cases.sh                     60cd78fc93cd6a26505e4c4597d428622d129565
experiments/native_tx_state/commitcap_native_tx_state.c          c6f88b60805898e0cc03e22a8d98a291f18798f6
experiments/native_tx_state/setup.sql                            c4a0c27659e42bc431c39ec2606fed27bce2cec4
experiments/native_tx_state/commitcap_native_tx_state--0.0.1.sql 0a753b54a655f43e98b5670eb131cf787d1b28f9
```

The attached complete transcript includes canonical state-transition
CC-020–022, CC-012 nested-savepoint checks, canonical numeric CC-030–033,
numeric contention CC-036 (three scenarios in `numeric_cases.sh`), both
independent-policy orders, individual-policy denial, and privilege probes.
Legacy experiment IDs printed by other harness sections are distinct from
same-number canonical test-plan IDs.

## Earlier run record (2026-09-23 UTC; excerpts retained for history)

Date: 2026-09-23 UTC. Public, clean-tree **implementation/tested commit**:
[`a51c2c794e5aad7f8a22e3b6d5785132a805a61e`](https://github.com/MrDarkRoot/CommitCap/commit/a51c2c794e5aad7f8a22e3b6d5785132a805a61e)
(resolved through the GitHub commits API before testing). Only
`demo/phase0/run.sh` and `demo/phase0/README.md` changed after the previous
run; the native fixture, Dockerfile, SQL, and suite remained untouched.

From the checkout root, exact commands and outcomes:

```bash
./demo/phase0/run.sh
# exit 0; complete 122-line sanitized transcript: 2026-09-23-pinned-demo.txt

COMPOSE_PROJECT_NAME=commitcap_demo_suite COMMITCAP_LOG=/tmp/opencode/commitcap-suite-a51c2c7.txt ./experiments/native_tx_state/run.sh
# exit 0; no suite cases skipped; unchanged suite cleans its own Compose volume
```

The demo pulled
`postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`
and tagged `postgres:16.4-alpine` locally; before Compose startup it verified
the tag and digest reference both resolved to image ID
`sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`.
The full suite build log also recorded
`FROM docker.io/library/postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`;
PostgreSQL reported `16.4` in both runs. Docker containers and volumes were
removed by their respective scripts.

[New complete sanitized demo transcript](2026-09-23-pinned-demo.txt): all 122
lines of the captured run are retained; only trailing spaces from ten Compose
progress lines were removed (checked using `diff -Z`). It includes the digest
preflight, version, all SQL command tags and errors, every exact fresh-admin
durable snapshot, and `Demo exit status: 0`. No passwords, tokens or personal
data appeared in the captured output. The [earlier transcript](2026-09-23-demo.txt)
and [earlier suite report](2026-09-23-suite.md) remain **historical**, tied to
their original `7a01f59698c70258b050fe78b3b8a49016d09382` implementation
SHA, which printed but did not enforce the digest.

Sanitized verbatim markers from the original 841-line suite capture at
`/tmp/opencode/commitcap-suite-a51c2c7.txt` (no longer locally available):
the **new complete rerun** linked above contains the same acceptance markers
and provides reviewable exact-SHA evidence.

```text
Classification: TEST FIRST
Command: ./experiments/native_tx_state/run.sh
Tested commit: a51c2c794e5aad7f8a22e3b6d5785132a805a61e
Base image: postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c
PostgreSQL: 16.4
privilege-envelope checks: PASS
privilege-audit checks: PASS
independence subscriptions→users→refunds: PASS (5|1|20.00, three fresh-admin durable oracles)
independence refunds→users→subscriptions: PASS (5|1|20.00, three fresh-admin durable oracles)
canonical CC-030: PASS (90.00 COMMIT, exact fresh-admin rows)
canonical CC-031: PASS (100.00 COMMIT, exact fresh-admin rows)
canonical CC-032 single: PASS (denied; fresh admin observed all eight baseline amounts)
canonical CC-032 decomposed: PASS (denied; fresh admin observed all eight baseline amounts)
canonical CC-033: PASS (+80/-80 consumed 80.00 in committed transaction; fresh admin amount 0.00)
independent-policy individual violation: PASS (3|1|40.00 independently accounted; sticky denial after ROLLBACK TO; poisoned COMMIT rejected; fresh admin saw all protected baselines and sibling audit=0)
native transaction-state experiment: all required tests PASS including independent per-policy acceptance in both orders and individual-violation recovery (PG16.4 research fixture)
Native suite exit status: 0
```

Skipped suite cases: **none**. Outside this research envelope (not skips):
capability-wide CC-034/035, managed deployment, ungranted INSERT/DELETE/COPY/
TRUNCATE accounting, other PostgreSQL majors and real poolers. See the
[separately established full integrated audit transcript](../../../experiments/native_tx_state/evidence/2026-09-23-integrated-audit-e97f19b.txt)
and [coverage record](../../../docs/phase0-security-coverage-run.md) for the
security audit's scope; this demo PASS is not production certification.
