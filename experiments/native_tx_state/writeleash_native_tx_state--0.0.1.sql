\echo Use "CREATE EXTENSION writeleash_native_tx_state" to load this file. \quit

CREATE FUNCTION enforce_update_budget()
RETURNS trigger
AS 'MODULE_PATHNAME', 'writeleash_native_enforce_update_budget'
LANGUAGE C;

-- V0 generic UPDATE row-event budget. Only the trusted relation owner creates
-- the trigger; its single canonical decimal argument is not writer-controlled.
CREATE FUNCTION enforce_rows_updated()
RETURNS trigger
AS 'MODULE_PATHNAME', 'writeleash_native_enforce_rows_updated'
LANGUAGE C;

CREATE FUNCTION enforce_role_transition()
RETURNS trigger
AS 'MODULE_PATHNAME', 'writeleash_native_enforce_role_transition'
LANGUAGE C;

CREATE FUNCTION enforce_refund_delta()
RETURNS trigger
AS 'MODULE_PATHNAME', 'writeleash_native_enforce_refund_delta'
LANGUAGE C;

REVOKE ALL ON FUNCTION enforce_update_budget() FROM PUBLIC;
REVOKE ALL ON FUNCTION enforce_rows_updated() FROM PUBLIC;
REVOKE ALL ON FUNCTION enforce_role_transition() FROM PUBLIC;
REVOKE ALL ON FUNCTION enforce_refund_delta() FROM PUBLIC;
