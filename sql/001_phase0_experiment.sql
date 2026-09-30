-- Phase 0 experiment for CC-001, CC-002, and CC-003.
-- This is test infrastructure, not a WriteLeash product implementation.

\set ON_ERROR_STOP on

CREATE ROLE writeleash_owner
    NOLOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS;

CREATE ROLE writeleash_writer
    LOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS
    PASSWORD 'writeleash_writer_experiment_only';

REVOKE CONNECT ON DATABASE writeleash FROM PUBLIC;
REVOKE TEMPORARY ON DATABASE writeleash FROM PUBLIC;
GRANT CONNECT ON DATABASE writeleash TO writeleash_writer;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO writeleash_writer;

CREATE SCHEMA writeleash AUTHORIZATION writeleash_owner;
REVOKE ALL ON SCHEMA writeleash FROM PUBLIC;

CREATE TABLE writeleash.update_budget_state (
    transaction_id bigint PRIMARY KEY,
    row_updates integer NOT NULL CHECK (row_updates >= 0)
);
ALTER TABLE writeleash.update_budget_state OWNER TO writeleash_owner;
REVOKE ALL ON TABLE writeleash.update_budget_state FROM PUBLIC;
REVOKE ALL ON TABLE writeleash.update_budget_state FROM writeleash_writer;

CREATE OR REPLACE FUNCTION writeleash.enforce_update_budget()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog, writeleash
AS $function$
DECLARE
    next_count integer;
BEGIN
    INSERT INTO writeleash.update_budget_state AS state (transaction_id, row_updates)
    VALUES (pg_catalog.txid_current(), 1)
    ON CONFLICT (transaction_id) DO UPDATE
    SET row_updates = state.row_updates + 1
    RETURNING row_updates INTO next_count;

    IF next_count > 5 THEN
        RAISE EXCEPTION
            'WriteLeash mutation budget exceeded (limit 5, attempted %)',
            next_count;
    END IF;

    RETURN NEW;
END
$function$;

ALTER FUNCTION writeleash.enforce_update_budget() OWNER TO writeleash_owner;
REVOKE ALL ON FUNCTION writeleash.enforce_update_budget() FROM PUBLIC;

CREATE TABLE public.subscriptions (
    id bigint PRIMARY KEY,
    status text NOT NULL
);
ALTER TABLE public.subscriptions OWNER TO writeleash_owner;

CREATE TRIGGER subscriptions_update_budget
BEFORE UPDATE ON public.subscriptions
FOR EACH ROW
EXECUTE FUNCTION writeleash.enforce_update_budget();

REVOKE ALL ON TABLE public.subscriptions FROM PUBLIC;
GRANT SELECT (id), UPDATE (status) ON TABLE public.subscriptions TO writeleash_writer;

INSERT INTO public.subscriptions (id, status)
SELECT id, 'baseline'
FROM generate_series(1, 10) AS ids(id);
