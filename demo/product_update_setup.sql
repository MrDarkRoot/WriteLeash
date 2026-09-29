\set ON_ERROR_STOP on

-- Dedicated minimal product fixture: no subscriptions/users/refunds or test GUCs.
CREATE ROLE writeleash_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
    NOREPLICATION NOINHERIT NOBYPASSRLS;
CREATE ROLE writeleash_demo_writer LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE
    NOREPLICATION NOINHERIT NOBYPASSRLS
    PASSWORD 'writeleash_demo_writer_experiment_only';

REVOKE CONNECT, TEMPORARY ON DATABASE writeleash_native FROM PUBLIC;
GRANT CONNECT ON DATABASE writeleash_native TO writeleash_demo_writer;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO writeleash_demo_writer;

CREATE SCHEMA writeleash_native AUTHORIZATION writeleash_owner;
REVOKE ALL ON SCHEMA writeleash_native FROM PUBLIC;
CREATE EXTENSION writeleash_native_tx_state WITH SCHEMA writeleash_native;
ALTER FUNCTION writeleash_native.enforce_rows_updated() OWNER TO writeleash_owner;
REVOKE ALL ON FUNCTION writeleash_native.enforce_rows_updated() FROM PUBLIC;
