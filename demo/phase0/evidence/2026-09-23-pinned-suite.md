# Pinned-image demo and native nonregression evidence (current)

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

Sanitized verbatim markers from the locally retained **new** complete 841-line
suite log at `/tmp/opencode/commitcap-suite-a51c2c7.txt`:

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
