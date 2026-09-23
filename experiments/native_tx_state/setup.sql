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
ALTER FUNCTION commitcap_native.enforce_role_transition() OWNER TO commitcap_owner;

-- Read-only instrumentation for the Phase 0 experiment. It reports only the
-- calling backend's own counters and grants no mutation authority.
CREATE SCHEMA commitcap_probe AUTHORIZATION commitcap_owner;
REVOKE ALL ON SCHEMA commitcap_probe FROM PUBLIC;
GRANT USAGE ON SCHEMA commitcap_probe TO commitcap_writer;

CREATE FUNCTION commitcap_probe.cc_native_probe(
    OUT active boolean,
    OUT consumed bigint,
    OUT denied boolean,
    OUT backend_pid integer
)
RETURNS record
AS '$libdir/commitcap_native_tx_state', 'commitcap_native_probe'
LANGUAGE C;

ALTER FUNCTION commitcap_probe.cc_native_probe() OWNER TO commitcap_owner;
REVOKE ALL ON FUNCTION commitcap_probe.cc_native_probe() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION commitcap_probe.cc_native_probe() TO commitcap_writer;

CREATE TABLE public.subscriptions (
    id bigint PRIMARY KEY,
    status text NOT NULL
);
ALTER TABLE public.subscriptions OWNER TO commitcap_owner;

CREATE TABLE public.unprotected_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    message text NOT NULL
);
ALTER TABLE public.unprotected_audit OWNER TO commitcap_owner;

CREATE TABLE public.users (
    id bigint PRIMARY KEY,
    tenant_id bigint NOT NULL,
    role text NOT NULL
);
ALTER TABLE public.users OWNER TO commitcap_owner;

CREATE TRIGGER subscriptions_update_budget
BEFORE UPDATE ON public.subscriptions
FOR EACH ROW
EXECUTE FUNCTION commitcap_native.enforce_update_budget();

CREATE TRIGGER users_role_transition
BEFORE UPDATE ON public.users
FOR EACH ROW
EXECUTE FUNCTION commitcap_native.enforce_role_transition();

REVOKE ALL ON TABLE public.subscriptions FROM PUBLIC;
GRANT SELECT (id), UPDATE (status) ON TABLE public.subscriptions TO commitcap_writer;

REVOKE ALL ON TABLE public.users FROM PUBLIC;
GRANT SELECT (id), UPDATE (role) ON TABLE public.users TO commitcap_writer;

REVOKE ALL ON TABLE public.unprotected_audit FROM PUBLIC;
GRANT INSERT (message) ON TABLE public.unprotected_audit TO commitcap_writer;

INSERT INTO public.subscriptions (id, status)
SELECT id, 'baseline'
FROM generate_series(1, 10) AS ids(id);

INSERT INTO public.users (id, tenant_id, role)
SELECT id, 10, 'member'
FROM generate_series(1, 6) AS ids(id);
