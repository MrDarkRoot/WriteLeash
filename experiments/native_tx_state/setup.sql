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

REVOKE CONNECT ON DATABASE commitcap_native FROM PUBLIC;
REVOKE TEMPORARY ON DATABASE commitcap_native FROM PUBLIC;
GRANT CONNECT ON DATABASE commitcap_native TO commitcap_writer;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO commitcap_writer;

CREATE SCHEMA commitcap_native AUTHORIZATION commitcap_owner;
REVOKE ALL ON SCHEMA commitcap_native FROM PUBLIC;

CREATE EXTENSION commitcap_native_tx_state WITH SCHEMA commitcap_native;
ALTER FUNCTION commitcap_native.enforce_update_budget() OWNER TO commitcap_owner;

CREATE TABLE public.subscriptions (
    id bigint PRIMARY KEY,
    status text NOT NULL
);
ALTER TABLE public.subscriptions OWNER TO commitcap_owner;

CREATE TRIGGER subscriptions_update_budget
BEFORE UPDATE ON public.subscriptions
FOR EACH ROW
EXECUTE FUNCTION commitcap_native.enforce_update_budget();

REVOKE ALL ON TABLE public.subscriptions FROM PUBLIC;
GRANT SELECT (id), UPDATE (status) ON TABLE public.subscriptions TO commitcap_writer;

INSERT INTO public.subscriptions (id, status)
SELECT id, 'baseline'
FROM generate_series(1, 10) AS ids(id);
