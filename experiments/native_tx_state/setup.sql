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

REVOKE CONNECT ON DATABASE writeleash_native FROM PUBLIC;
REVOKE TEMPORARY ON DATABASE writeleash_native FROM PUBLIC;
GRANT CONNECT ON DATABASE writeleash_native TO writeleash_writer;

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO writeleash_writer;

CREATE SCHEMA writeleash_native AUTHORIZATION writeleash_owner;
REVOKE ALL ON SCHEMA writeleash_native FROM PUBLIC;

CREATE EXTENSION writeleash_native_tx_state WITH SCHEMA writeleash_native;
ALTER FUNCTION writeleash_native.enforce_update_budget() OWNER TO writeleash_owner;
ALTER FUNCTION writeleash_native.enforce_rows_updated() OWNER TO writeleash_owner;
ALTER FUNCTION writeleash_native.enforce_role_transition() OWNER TO writeleash_owner;
ALTER FUNCTION writeleash_native.enforce_refund_delta() OWNER TO writeleash_owner;

-- Read-only instrumentation for the Phase 0 experiment. It reports only the
-- calling backend's own counters and grants no mutation authority.
CREATE SCHEMA writeleash_probe AUTHORIZATION writeleash_owner;
REVOKE ALL ON SCHEMA writeleash_probe FROM PUBLIC;
GRANT USAGE ON SCHEMA writeleash_probe TO writeleash_writer;

CREATE FUNCTION writeleash_probe.writeleash_native_probe(
    OUT active boolean,
    OUT consumed bigint,
    OUT denied boolean,
    OUT backend_pid integer
)
RETURNS record
AS '$libdir/writeleash_native_tx_state', 'writeleash_native_probe'
LANGUAGE C;

ALTER FUNCTION writeleash_probe.writeleash_native_probe() OWNER TO writeleash_owner;
REVOKE ALL ON FUNCTION writeleash_probe.writeleash_native_probe() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION writeleash_probe.writeleash_native_probe() TO writeleash_writer;

CREATE FUNCTION writeleash_probe.writeleash_native_policy_probe(
    OUT subscriptions_consumed bigint,
    OUT users_consumed bigint,
    OUT refunds_positive_delta numeric,
    OUT denied boolean
)
RETURNS record
AS '$libdir/writeleash_native_tx_state', 'writeleash_native_policy_probe'
LANGUAGE C;
ALTER FUNCTION writeleash_probe.writeleash_native_policy_probe() OWNER TO writeleash_owner;
REVOKE ALL ON FUNCTION writeleash_probe.writeleash_native_policy_probe() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION writeleash_probe.writeleash_native_policy_probe() TO writeleash_writer;

CREATE TABLE public.subscriptions (
    id bigint PRIMARY KEY,
    status text NOT NULL
);
ALTER TABLE public.subscriptions OWNER TO writeleash_owner;

CREATE TABLE public.unprotected_audit (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    message text NOT NULL
);
ALTER TABLE public.unprotected_audit OWNER TO writeleash_owner;

CREATE TABLE public.users (
    id bigint PRIMARY KEY,
    tenant_id bigint NOT NULL,
    role text NOT NULL
);
ALTER TABLE public.users OWNER TO writeleash_owner;

-- Research-only decimal fixture: no numeric(p,s) typmod, which would round
-- excess scale before a BEFORE ROW trigger could validate the input.
CREATE TABLE public.refunds (
    id bigint PRIMARY KEY,
    customer_id bigint NOT NULL,
    amount numeric
);
ALTER TABLE public.refunds OWNER TO writeleash_owner;

CREATE TRIGGER subscriptions_update_budget
BEFORE UPDATE ON public.subscriptions
FOR EACH ROW
EXECUTE FUNCTION writeleash_native.enforce_update_budget();

CREATE TRIGGER users_role_transition
BEFORE UPDATE ON public.users
FOR EACH ROW
EXECUTE FUNCTION writeleash_native.enforce_role_transition();

CREATE TRIGGER refunds_positive_delta
BEFORE UPDATE ON public.refunds
FOR EACH ROW
EXECUTE FUNCTION writeleash_native.enforce_refund_delta();

REVOKE ALL ON TABLE public.subscriptions FROM PUBLIC;
GRANT SELECT (id), UPDATE (status) ON TABLE public.subscriptions TO writeleash_writer;

REVOKE ALL ON TABLE public.users FROM PUBLIC;
GRANT SELECT (id), UPDATE (role) ON TABLE public.users TO writeleash_writer;

REVOKE ALL ON TABLE public.refunds FROM PUBLIC;
GRANT SELECT (id, amount), UPDATE (amount) ON TABLE public.refunds TO writeleash_writer;

REVOKE ALL ON TABLE public.unprotected_audit FROM PUBLIC;
GRANT INSERT (message) ON TABLE public.unprotected_audit TO writeleash_writer;

INSERT INTO public.subscriptions (id, status)
SELECT id, 'baseline'
FROM generate_series(1, 10) AS ids(id);

INSERT INTO public.users (id, tenant_id, role)
SELECT id, 10, 'member'
FROM generate_series(1, 6) AS ids(id);

INSERT INTO public.refunds (id, customer_id, amount)
SELECT id, 10, 0.00
FROM generate_series(1, 8) AS ids(id);
