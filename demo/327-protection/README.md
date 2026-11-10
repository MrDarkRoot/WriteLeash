# Disposable protection demo (#327)

Unreleased lab. It installs this worktree's WriteLeash into a disposable WordPress and WooCommerce site, issues a one-item grant to a shop-manager agent, changes one price through the grant job route, and denies a direct WooCommerce REST write to a second product. The Protection report must count that denial. `run.sh` deletes the containers and volumes when it finishes.

No production data and no production credentials. The MySQL passwords in `docker-compose.yml` are the same disposable fixture passwords the repository tests use. Generated WordPress and application passwords stay in gitignored `runtime/` and are not written into `out/evidence.json`.

```bash
bash demo/327-protection/run.sh
```

`DEMO327_KEEP=1` leaves the container up. The normal path tears it down and checks that the compose project has no containers, networks, or volumes.

`VERSIONS.md` and `out/evidence.json` are rewritten from the running containers. Do not invent them if the stack does not start.
