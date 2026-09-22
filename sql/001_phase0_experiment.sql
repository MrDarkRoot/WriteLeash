-- Phase 0 experiment for CC-001, CC-002, and CC-003.
-- This is test infrastructure, not a CommitCap product implementation.

\set ON_ERROR_STOP on

CREATE ROLE commitcap_owner
    NOLOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS;

CREATE ROLE commitcap_writer
    LOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS
    PASSWORD 'commitcap_writer_experiment_only';

REVOKE CONNECT ON DATABASE commitcap FROM PUBLIC;
GRANT CONNECT ON DATABASE commitcap TO commitcap_writer;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO commitcap_writer;

CREATE SCHEMA commitcap AUTHORIZATION commitcap_owner;
REVOKE ALL ON SCHEMA commitcap FROM PUBLIC;

CREATE TABLE commitcap.update_budget_state (
    transaction_id bigint PRIMARY KEY,
    row_updates integer NOT NULL CHECK (row_updates >= 0)
);
ALTER TABLE commitcap.update_budget_state OWNER TO commitcap_owner;
REVOKE ALL ON TABLE commitcap.update_budget_state FROM PUBLIC;
REVOKE ALL ON TABLE commitcap.update_budget_state FROM commitcap_writer;

CREATE OR REPLACE FUNCTION commitcap.enforce_update_budget()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, commitcap
AS $function$
DECLARE
    next_count integer;
BEGIN
    INSERT INTO commitcap.update_budget_state AS state (transaction_id, row_updates)
    VALUES (pg_catalog.txid_current(), 1)
    ON CONFLICT (transaction_id) DO UPDATE
    SET row_updates = state.row_updates + 1
    RETURNING row_updates INTO next_count;

    IF next_count > 5 THEN
        RAISE EXCEPTION
            'CommitCap mutation budget exceeded (limit 5, attempted %)',
            next_count;
    END IF;

    RETURN NEW;
END
$function$;

ALTER FUNCTION commitcap.enforce_update_budget() OWNER TO commitcap_owner;
REVOKE ALL ON FUNCTION commitcap.enforce_update_budget() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION commitcap.enforce_update_budget() TO commitcap_writer;

CREATE TABLE public.subscriptions (
    id bigint PRIMARY KEY,
    status text NOT NULL
);
ALTER TABLE public.subscriptions OWNER TO commitcap_owner;

CREATE TRIGGER subscriptions_update_budget
BEFORE UPDATE ON public.subscriptions
FOR EACH ROW
EXECUTE FUNCTION commitcap.enforce_update_budget();

REVOKE ALL ON TABLE public.subscriptions FROM PUBLIC;
GRANT SELECT (id), UPDATE (status) ON TABLE public.subscriptions TO commitcap_writer;

INSERT INTO public.subscriptions (id, status)
SELECT id, 'baseline'
FROM generate_series(1, 10) AS ids(id);
