WITH request AS (
    SELECT
        :'cc_schema_name'::text AS schema_name,
        :'cc_table_name'::text AS table_name,
        :'cc_expected_budget'::text AS expected_budget,
        CASE WHEN :'cc_writer_role_supplied'::text = 'true'
             THEN :'cc_writer_role'::text
             ELSE NULL::text
        END AS writer_role_name
),
relation AS (
    SELECT
        q.*,
        n.oid AS schema_oid,
        c.oid AS relation_oid,
        c.relkind,
        c.relispartition,
        c.relowner
    FROM request AS q
    LEFT JOIN pg_namespace AS n ON n.nspname = q.schema_name
    LEFT JOIN pg_class AS c
        ON c.relnamespace = n.oid
       AND c.relname = q.table_name
),
extension AS (
    SELECT e.oid AS extension_oid, e.extowner AS extension_owner,
           n.oid AS schema_oid, n.nspowner AS schema_owner
    FROM pg_extension AS e
    JOIN pg_namespace AS n ON n.oid = e.extnamespace
    WHERE e.extname = 'writeleash_native_tx_state'
      AND n.nspname = 'writeleash_native'
),
function_candidates AS (
    SELECT
        p.oid AS function_oid,
        p.proowner AS function_owner,
        (p.prorettype = 'trigger'::regtype AND p.pronargs = 0 AND l.lanname = 'c'
         AND EXISTS (
             SELECT 1
             FROM pg_depend AS d
             JOIN pg_extension AS e ON e.oid = d.refobjid
             WHERE d.classid = 'pg_proc'::regclass
               AND d.objid = p.oid
               AND d.refclassid = 'pg_extension'::regclass
               AND d.deptype = 'e'
               AND e.extname = 'writeleash_native_tx_state'
         )) AS supported_function
    FROM pg_proc AS p
    JOIN pg_namespace AS n ON n.oid = p.pronamespace
    JOIN pg_language AS l ON l.oid = p.prolang
    WHERE n.nspname = 'writeleash_native'
      AND p.proname = 'enforce_rows_updated'
      AND p.pronargs = 0
),
trigger_rows AS (
    SELECT
        t.*,
        EXISTS (
            SELECT 1
            FROM pg_depend AS d
            JOIN pg_extension AS e ON e.oid = d.refobjid
            WHERE d.classid = 'pg_proc'::regclass
              AND d.objid = t.tgfoid
              AND d.refclassid = 'pg_extension'::regclass
              AND d.deptype = 'e'
              AND e.extname = 'writeleash_native_tx_state'
        ) AS writeleash_extension_trigger
    FROM relation AS r
    JOIN pg_trigger AS t ON t.tgrelid = r.relation_oid
    WHERE NOT t.tgisinternal
),
generic_triggers AS (
    SELECT t.*
    FROM trigger_rows AS t
    WHERE t.tgfoid IN (SELECT function_oid FROM function_candidates WHERE supported_function)
),
trigger_summary AS (
    SELECT
        count(*) FILTER (WHERE t.writeleash_extension_trigger) AS extension_trigger_count,
        count(*) FILTER (
            WHERE t.writeleash_extension_trigger
              AND t.tgfoid IN (SELECT function_oid FROM function_candidates WHERE supported_function)
        ) AS generic_trigger_count,
        count(*) FILTER (
            WHERE t.writeleash_extension_trigger
              AND t.tgfoid IN (SELECT function_oid FROM function_candidates WHERE supported_function)
              AND t.tgname = 'writeleash_rows_updated'
        ) AS fixed_name_count
    FROM relation AS r
    LEFT JOIN trigger_rows AS t ON true
),
generic_arguments AS (
    SELECT
        t.tgnargs,
        t.tgargs,
        CASE
            WHEN t.tgnargs = 1
             AND octet_length(t.tgargs) > 0
             AND get_byte(t.tgargs, octet_length(t.tgargs) - 1) = 0
            THEN convert_from(
                substring(t.tgargs FROM 1 FOR octet_length(t.tgargs) - 1),
                current_setting('server_encoding')
            )
            ELSE NULL::text
        END AS budget_text
    FROM generic_triggers AS t
),
writer AS (
    SELECT r.writer_role_name, p.*
    FROM request AS r
    JOIN pg_roles AS p ON p.rolname = r.writer_role_name
),
checks AS (
    SELECT
        5 AS sort_order,
        'PostgreSQL 16.4 catalog version'::text AS check_name,
        (current_setting('server_version_num') = '160004') AS passed,
        current_setting('server_version') AS detail

    UNION ALL
    SELECT
        10 AS sort_order,
        'relation exists'::text AS check_name,
        (r.relation_oid IS NOT NULL) AS passed,
        format('%s.%s', r.schema_name, r.table_name) AS detail
    FROM relation AS r

    UNION ALL
    SELECT 20, 'ordinary table; no partition or inheritance routing',
        COALESCE(
            r.relation_oid IS NOT NULL
            AND r.relkind = 'r'
            AND NOT r.relispartition
            AND NOT EXISTS (
                SELECT 1 FROM pg_inherits AS i
                WHERE i.inhrelid = r.relation_oid OR i.inhparent = r.relation_oid
            ),
            false
        ),
        'requires relkind r, not a partition, with no parent or child inheritance'
    FROM relation AS r

    UNION ALL
    SELECT 30, 'WriteLeash extension and trusted schema',
        EXISTS (SELECT 1 FROM extension),
        'requires writeleash_native_tx_state installed in writeleash_native'

    UNION ALL
    SELECT 40, 'exact generic enforcement function',
        (SELECT count(*) = 1 AND bool_and(supported_function) FROM function_candidates),
        'requires writeleash_native.enforce_rows_updated(), C trigger function owned by the extension'

    UNION ALL
    SELECT 50, 'exactly one WriteLeash UPDATE trigger',
        (s.extension_trigger_count = 1 AND s.generic_trigger_count = 1 AND s.fixed_name_count = 1),
        format('extension triggers=%s, generic triggers=%s, fixed-name triggers=%s',
               s.extension_trigger_count, s.generic_trigger_count, s.fixed_name_count)
    FROM trigger_summary AS s

    UNION ALL
    SELECT 55, 'no other direct user-defined triggers',
        (SELECT count(*) = 1 FROM trigger_rows),
        'requires the WriteLeash trigger to be the only non-internal trigger on this relation'

    UNION ALL
    SELECT 60, 'enabled for ordinary writes',
        (SELECT count(*) = 1 AND bool_and(tgenabled IN ('O', 'A')) FROM generic_triggers),
        'requires trigger state O (origin) or A (always); disabled and replica-only fail'

    UNION ALL
    SELECT 70, 'BEFORE UPDATE FOR EACH ROW only',
        (SELECT count(*) = 1 AND bool_and(tgtype = 19) FROM generic_triggers),
        'requires PostgreSQL trigger type BEFORE + UPDATE + ROW, with no other event'

    UNION ALL
    SELECT 80, 'unconditional; no WHEN clause',
        (SELECT count(*) = 1 AND bool_and(tgqual IS NULL) FROM generic_triggers),
        'requires tgqual IS NULL'

    UNION ALL
    SELECT 90, 'all UPDATE columns; not UPDATE OF',
        (SELECT count(*) = 1 AND bool_and(cardinality(tgattr) = 0) FROM generic_triggers),
        'requires an empty trigger column list'

    UNION ALL
    SELECT 100, 'exactly one budget argument',
        (SELECT count(*) = 1 AND bool_and(tgnargs = 1) FROM generic_arguments),
        'requires one trigger argument'

    UNION ALL
    SELECT 110, 'canonical budget in 0..2147483647',
        COALESCE((
            SELECT count(*) = 1 AND bool_and(
                CASE
                    WHEN budget_text ~ '^(0|[1-9][0-9]*)$' AND length(budget_text) <= 10
                    THEN budget_text::numeric <= 2147483647
                    ELSE false
                END
            )
            FROM generic_arguments
        ), false),
        COALESCE((
            SELECT string_agg(COALESCE(budget_text, '<malformed>'), ',' ORDER BY budget_text)
            FROM generic_arguments
        ), 'missing or malformed argument')

    UNION ALL
    SELECT 115, 'installed budget equals reviewed plan',
        COALESCE((
            SELECT count(*) = 1 AND bool_and(g.budget_text = r.expected_budget)
            FROM generic_arguments AS g CROSS JOIN request AS r
        ), false),
        (SELECT 'reviewed budget=' || expected_budget FROM request)

    UNION ALL
    SELECT 120, 'protected writer role supplied and exists',
        EXISTS (SELECT 1 FROM writer),
        COALESCE((SELECT writer_role_name FROM request), 'writer role not supplied; pass --writer-role')

    UNION ALL
    SELECT 130, 'protected writer is not superuser',
        COALESCE(
            (SELECT NOT w.rolsuper AND NOT EXISTS (
                SELECT 1 FROM pg_roles AS elevated
                WHERE elevated.rolsuper
                  AND elevated.oid <> w.oid
                  AND pg_has_role(w.oid, elevated.oid, 'MEMBER')
            ) FROM writer AS w),
            false
        ),
        'checks direct SUPERUSER and membership in a superuser role'

    UNION ALL
    SELECT 140, 'protected writer has no dangerous role attributes',
        COALESCE(
            (SELECT
                NOT (w.rolcreatedb OR w.rolcreaterole OR w.rolreplication OR w.rolbypassrls)
                AND NOT EXISTS (
                    SELECT 1 FROM pg_roles AS elevated
                    WHERE (elevated.rolcreatedb OR elevated.rolcreaterole
                           OR elevated.rolreplication OR elevated.rolbypassrls)
                      AND elevated.oid <> w.oid
                      AND pg_has_role(w.oid, elevated.oid, 'MEMBER')
                )
             FROM writer AS w),
            false
        ),
        'checks CREATEDB, CREATEROLE, REPLICATION, BYPASSRLS and reachable memberships'

    UNION ALL
    SELECT 150, 'protected writer is not table owner',
        COALESCE((
            SELECT w.oid <> r.relowner AND NOT pg_has_role(w.oid, r.relowner, 'MEMBER')
            FROM writer AS w CROSS JOIN relation AS r
            WHERE r.relation_oid IS NOT NULL
        ), false),
        'checks direct ownership and membership in the table-owner role'

    UNION ALL
    SELECT 160, 'protected writer lacks TRIGGER privilege',
        COALESCE((
            SELECT NOT has_table_privilege(w.oid, r.relation_oid, 'TRIGGER')
            FROM writer AS w CROSS JOIN relation AS r
            WHERE r.relation_oid IS NOT NULL
        ), false),
        'evaluated with PostgreSQL effective table privileges'

    UNION ALL
    SELECT 170, 'protected writer cannot CREATE in WriteLeash schema',
        COALESCE((
            SELECT NOT has_schema_privilege(w.oid, e.schema_oid, 'CREATE')
            FROM writer AS w CROSS JOIN extension AS e
        ), false),
        'evaluated with PostgreSQL effective schema privileges'

    UNION ALL
    SELECT 180, 'protected writer is not a trusted-object owner',
        COALESCE((
            SELECT NOT pg_has_role(w.oid, e.schema_owner, 'MEMBER')
                   AND NOT pg_has_role(w.oid, e.extension_owner, 'MEMBER')
                   AND NOT EXISTS (
                       SELECT 1 FROM function_candidates AS f
                       WHERE pg_has_role(w.oid, f.function_owner, 'MEMBER')
                   )
            FROM writer AS w CROSS JOIN extension AS e
        ), false),
        'checks membership in WriteLeash extension, schema and enforcement-function owner roles'

    UNION ALL
    SELECT 190, 'protected writer cannot change session_replication_role',
        COALESCE((
            SELECT NOT has_parameter_privilege(w.oid, 'session_replication_role', 'SET')
                   AND NOT has_parameter_privilege(w.oid, 'session_replication_role', 'ALTER SYSTEM')
            FROM writer AS w
        ), false),
        'requires no effective SET or ALTER SYSTEM parameter privilege'

    UNION ALL
    SELECT 200, 'protected writer has no SET-able role memberships',
        COALESCE((
            SELECT NOT EXISTS (
                SELECT 1 FROM pg_roles AS other
                WHERE other.oid <> w.oid
                  AND pg_has_role(w.oid, other.oid, 'SET')
            )
            FROM writer AS w
        ), false),
        'V0 rejects any other role reachable through a SET ROLE chain'
),
summary AS (
    SELECT bool_and(passed) AS all_passed FROM checks
)
SELECT check_name, status, detail
FROM (
    SELECT sort_order, check_name,
           CASE WHEN passed THEN 'PASS' ELSE 'FAIL' END AS status,
           detail
    FROM checks
    UNION ALL
    SELECT 999, 'OVERALL',
           CASE WHEN all_passed THEN 'PASS' ELSE 'FAIL' END,
           CASE WHEN all_passed THEN 'all catalog and writer checks passed'
                ELSE 'do not present this relation as protected; fix every failed check and rerun'
           END
    FROM summary
) AS report
ORDER BY sort_order;
