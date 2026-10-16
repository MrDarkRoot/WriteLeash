\set ON_ERROR_STOP on
-- setup.sql installs the EXACT native functions, trigger graph, roles and grants.
-- LIKE INCLUDING ALL copies column definitions and primary-key indexes, but not
-- triggers, ownership or ACLs. Verify these explicitly before measurement.
CREATE TABLE public.baseline_subscriptions (LIKE public.subscriptions INCLUDING ALL);
CREATE TABLE public.baseline_users (LIKE public.users INCLUDING ALL);
CREATE TABLE public.baseline_refunds (LIKE public.refunds INCLUDING ALL);
ALTER TABLE public.baseline_subscriptions OWNER TO commitcap_owner;
ALTER TABLE public.baseline_users OWNER TO commitcap_owner;
ALTER TABLE public.baseline_refunds OWNER TO commitcap_owner;
REVOKE ALL ON public.baseline_subscriptions, public.baseline_users, public.baseline_refunds FROM PUBLIC;
GRANT SELECT (id), UPDATE (status) ON public.baseline_subscriptions TO commitcap_writer;
GRANT SELECT (id), UPDATE (role) ON public.baseline_users TO commitcap_writer;
GRANT SELECT (id, amount), UPDATE (amount) ON public.baseline_refunds TO commitcap_writer;
-- Trusted test-only setting; required for 100-row UPDATE. Writer cannot SET it.
ALTER ROLE commitcap_writer SET commitcap_native.test_budget = 100;
