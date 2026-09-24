# Native PostgreSQL 16.4 research CI (#17)

The [native-pg16-security workflow](../workflows/native-pg16-security.yml)
runs on pull requests and pushes to `main`. It uses a disposable GitHub-hosted
Ubuntu runner with Docker Compose. It fetches the exact
`postgres:16.4-alpine@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c`
base image and tags that *same image* for the unchanged fixture Dockerfile.
Then it executes **`./experiments/native_tx_state/run.sh`** in full, with no
test-selection flags. A Bash `pipefail` preserves the suite's nonzero result
through `tee`; a separate marker check rejects missing canonical numeric
CC-030–033, CC-036 contention A/B/C, CC-012 nested-savepoint, independent-
policy or privilege results. The actual PostgreSQL durable-state and lock-wait
assertions are still performed by the original suite, not the marker check.

The suite has its own Compose cleanup trap; an independent `always()` step
also removes the disposable service and volume on failure. The job has a
30-minute limit and read-only repository permission. Test-only passwords are
hardcoded **only for this disposable synthetic fixture**; no external database,
GitHub secret or production data is used. The complete runner output is in the
Actions log and is kept as a 7-day `native-pg16-security-log` artifact when a
suite log exists. It contains synthetic rows, SQLSTATEs, PIDs and callback
traces; no real credentials are printed. A failed assertion is visible in the
`Full integrated suite` step; an omitted marker also makes that step fail.

For the local equivalent (Docker, Compose and Bash required):

```sh
docker pull postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c
docker tag postgres@sha256:5660c2cbfea50c7a9127d17dc4e48543eedd3d7a41a595a2dfa572471e37e64c postgres:16.4-alpine
./experiments/native_tx_state/run.sh
```

Automated PASS applies only to the documented PG16.4 research fixture and
its tested role, schema and SQL envelope. It does not establish transaction-
splitting resistance, capability-wide authority, arbitrary trigger/FK/
partition behavior, managed PostgreSQL deployment or production support.
Legacy row-budget `CC-*` numbers in `run.sh` must not be confused with
canonical numeric cases in `numeric_cases.sh`.
