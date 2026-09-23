\echo Use "CREATE EXTENSION commitcap_native_tx_state" to load this file. \quit

CREATE FUNCTION enforce_update_budget()
RETURNS trigger
AS 'MODULE_PATHNAME', 'commitcap_native_enforce_update_budget'
LANGUAGE C;

REVOKE ALL ON FUNCTION enforce_update_budget() FROM PUBLIC;
