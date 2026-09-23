\echo Use "CREATE EXTENSION commitcap_native_tx_state" to load this file. \quit

CREATE FUNCTION enforce_update_budget()
RETURNS trigger
AS 'MODULE_PATHNAME', 'commitcap_native_enforce_update_budget'
LANGUAGE C;

CREATE FUNCTION enforce_role_transition()
RETURNS trigger
AS 'MODULE_PATHNAME', 'commitcap_native_enforce_role_transition'
LANGUAGE C;

REVOKE ALL ON FUNCTION enforce_update_budget() FROM PUBLIC;
REVOKE ALL ON FUNCTION enforce_role_transition() FROM PUBLIC;
