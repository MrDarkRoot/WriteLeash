# Disposable stack for issue #327

Recorded from the running containers. Not a published release.

| Item | Value |
| --- | --- |
| Status | PASS |
| Git commit | `141d439598890e7b446f111b0f6ae7c2ac602df1` |
| WriteLeash plugin tree | `ef809180e3f98b9c1a215b921be253a83cc19940` |
| WriteLeash header | 0.3.0 |
| WordPress | 7.1.2 |
| PHP | 8.2.34 |
| WooCommerce | 11.1.2 |
| WooCommerce zip sha256 | `9de9350a1cf5671b9960afb3151f40f7980e223217a441bf2ea5921b5fce8e9e` |
| Database | 8.0.44 |
| wp_posts engine | InnoDB |
| Core image | `wordpress:7.1.2-php8.2-apache@sha256:da4c3e7b0051b70835b0a188ecdd5bce5ebf6f83d1f3df41553f1a97d237fd19` |
| CLI image | `wordpress:cli-php8.2@sha256:aaa79b1239f24fccbb1634ec0a25c3e4fac6271bc9bf2334c40c3c8ef77bce89` |
| MySQL image | `mysql@sha256:9c3380eac945af0736031b200027f581925927c81e010056214a4bd6b6693714` |
| Grant expiry | `2026-12-10 11:48:25` UTC, source `utc_now_plus_30_days` |
| Permitted price | 40.00 to 30.00 through `POST /wp-json/writeleash/v1/grants/{id}/jobs` and the `writeleash_process_job` hook |
| Denied price | Harborline Notebook stayed 25.00 after `PUT /wp-json/wc/v3/products/{id}` returned 403 `writeleash_perimeter_denied` |
| Report | counted the REST denial (`blocked` 1, surface `rest` 1). The row's product column is 2, the actor id, because that is the object id a REST denial stores. Harborline Notebook is product 11 and stayed 25.00. HTTP requests during the report load: 0 |

Fixture database passwords are the disposable lab values in `docker-compose.yml`. Generated WordPress and application passwords stayed in gitignored `runtime/` and are removed with the container. No production credential was used.
