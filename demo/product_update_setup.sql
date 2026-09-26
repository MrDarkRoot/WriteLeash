\set ON_ERROR_STOP on

-- Dedicated minimal product fixture: no subscriptions/users/refunds or test GUCs.
CREATE ROLE commitcap_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
    NOREPLICATION NOINHERIT NOBYPASSRLS;
CREATE ROLE commitcap_demo_writer LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
    NOREPLICATION NOINHERIT NOBYPASSRLS
    PASSWORD 'commitcap_demo_writer_experiment_only';

REVOKE CONNECT, TEMPORARY ON DATABASE commitcap_native FROM PUBLIC;
GRANT CONNECT ON DATABASE commitcap_native TO commitcap_demo_writer;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO commitcap_demo_writer;

CREATE SCHEMA commitcap_native AUTHORIZATION commitcap_owner;
REVOKE ALL ON SCHEMA commitcap_native FROM PUBLIC;
CREATE EXTENSION commitcap_native_tx_state WITH SCHEMA commitcap_native;
ALTER FUNCTION commitcap_native.enforce_rows_updated() OWNER TO commitcap_owner;
REVOKE ALL ON FUNCTION commitcap_native.enforce_rows_updated() FROM PUBLIC;
