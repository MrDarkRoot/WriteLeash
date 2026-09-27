"""Executable, intentionally disposable MySQL/MariaDB semantics experiment."""

from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
import re
from threading import Barrier

import pymysql
from pymysql.constants import SERVER_STATUS


def connect(host, user="cc_writer"):
    password = (
        "disposable_root_password" if user == "root" else "disposable_writer_password"
    )
    return pymysql.connect(
        host=host, port=3306, user=user, password=password, database="cc_research",
        autocommit=True, connect_timeout=10,
    )


@contextmanager
def session(host, user="cc_writer"):
    connection = connect(host, user)
    try:
        yield connection
    finally:
        connection.close()


def sql(connection, statement, args=None):
    with connection.cursor() as cursor:
        cursor.execute(statement, args)
        return cursor.fetchall() if cursor.description else None


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def prepare(host):
    with session(host, "root") as admin:
        sql(admin, "DELETE FROM cc_items")
        sql(admin, "DELETE FROM cc_autocommit_events")
        sql(admin, "DELETE FROM cc_state")
        with admin.cursor() as cursor:
            cursor.executemany(
                "INSERT INTO cc_items (id) VALUES (%s)", [(i,) for i in range(1, 21)]
            )
            cursor.executemany(
                "INSERT INTO cc_autocommit_events (id) VALUES (%s)",
                [(i,) for i in range(1, 7)],
            )


def durable(host, table="cc_items"):
    # Every durable oracle uses a NEW connection, never the writer's snapshot.
    with session(host) as observer:
        return dict(sql(observer, f"SELECT id, touched FROM {table} ORDER BY id"))


def update(connection, row):
    sql(connection, "UPDATE cc_items SET touched = touched + 1 WHERE id = %s", (row,))


def denied(connection, row=6):
    try:
        update(connection, row)
    except pymysql.MySQLError as error:
        # Both servers expose SQLSTATE through SQL standard diagnostics; PyMySQL's
        # exception itself supplies only the numeric error and message.
        sql(connection, "GET DIAGNOSTICS CONDITION 1 @cc_error_state = RETURNED_SQLSTATE")
        state = sql(connection, "SELECT @cc_error_state")[0][0]
        check(error.args[0] == 1644 and state == "45000", f"unexpected denial: {error.args}, {state}")
        print(f"  event six: errno={error.args[0]} SQLSTATE={state} message={error.args[1]}")
        return
    raise AssertionError("event six was not denied")


def state(connection):
    autocommit = sql(connection, "SELECT @@autocommit")[0][0]
    # SERVER_STATUS_IN_TRANS is the transaction-state bit reported by the
    # server after every command; unlike @@in_transaction it works on MySQL 8.
    return (autocommit, int(bool(connection.server_status & SERVER_STATUS.SERVER_STATUS_IN_TRANS)))


def five(connection):
    for row in range(1, 6):
        update(connection, row)


def case_safe(host):
    prepare(host)
    with session(host) as writer:
        check(state(writer) == (1, 0), "expected autocommit=ON outside explicit tx")
        sql(writer, "CALL cc_start()")
        check(state(writer) == (1, 1), "explicit transaction not active")
        five(writer)
        sql(writer, "COMMIT")
        check(state(writer) == (1, 0), "COMMIT did not end tx")
    check([durable(host)[i] for i in range(1, 7)] == [1] * 5 + [0], "five-event commit")
    print("  safe five: PASS (fresh connection sees exactly five)")


def case_denial(host):
    prepare(host)
    with session(host) as writer:
        sql(writer, "CALL cc_start()")
        five(writer)
        denied(writer)
        print(f"  after SIGNAL: autocommit,in_transaction={state(writer)}")
        check(state(writer) == (1, 1), "unexpected rollback on SIGNAL")
        sql(writer, "COMMIT")
        check(state(writer) == (1, 0), "COMMIT after SIGNAL failed")
        sql(writer, "CALL cc_start()")
        five(writer)
        sql(writer, "ROLLBACK")
        sql(writer, "CALL cc_start()")
        five(writer)
        sql(writer, "COMMIT")
    check([durable(host)[i] for i in range(1, 7)] == [2] * 5 + [0], "denial/new tx durability")
    print("  denial: sixth rejected, COMMIT succeeds; new transaction fresh: PASS")


def case_savepoint_denial(host):
    prepare(host)
    with session(host) as writer:
        sql(writer, "CALL cc_start()")
        five(writer)
        sql(writer, "SAVEPOINT s")
        denied(writer)
        sql(writer, "ROLLBACK TO SAVEPOINT s")
        check(sql(writer, "SELECT 1")[0] == (1,), "legal SQL after denial failed")
        check(state(writer) == (1, 1), "transaction unexpectedly aborted")
        sql(writer, "COMMIT")
    check([durable(host)[i] for i in range(1, 7)] == [1] * 5 + [0], "savepoint denial durable state")
    print("  savepoint recovery: COMMIT succeeds; sticky denial FAIL")


def case_repeated_and_zero(host):
    prepare(host)
    with session(host) as writer:
        sql(writer, "CALL cc_start()")
        for _ in range(5):
            update(writer, 1)
        sql(writer, "UPDATE cc_items SET touched = touched + 1 WHERE id = -999")
        # The restricted writer cannot read cc_state. A successful zero-row
        # UPDATE followed by a denied sixth row is the observable oracle.
        denied(writer)
        sql(writer, "COMMIT")
    check(durable(host)[1] == 5 and durable(host)[6] == 0, "repeated-row durability")
    print("  same row five times / zero-row costs zero / sixth denied: PASS")


def case_allowed_savepoint(host):
    prepare(host)
    with session(host) as writer:
        sql(writer, "CALL cc_start()")
        for row in (1, 2, 3):
            update(writer, row)
        sql(writer, "SAVEPOINT s")
        for row in (4, 5):
            update(writer, row)
        sql(writer, "ROLLBACK TO SAVEPOINT s")
        for row in (4, 5):
            update(writer, row)
        denied(writer)
        sql(writer, "COMMIT")
    check([durable(host)[i] for i in range(1, 7)] == [1] * 5 + [0], "allowed savepoint rollback")
    print("  allowed savepoint accounting unwinds: PASS")


def case_isolation(host):
    prepare(host)
    barrier = Barrier(2)

    def worker(first):
        with session(host) as writer:
            sql(writer, "CALL cc_start()")
            barrier.wait(timeout=20)
            for row in range(first, first + 5):
                update(writer, row)
            # Each session receives its own budget even while both are open.
            denied(writer, first + 5)
            sql(writer, "COMMIT")

    with ThreadPoolExecutor(max_workers=2) as pool:
        futures = [pool.submit(worker, row) for row in (1, 11)]
        for future in futures:
            future.result(timeout=30)
    rows = durable(host)
    check([rows[i] for i in range(1, 7)] == [1] * 5 + [0], "first session")
    check([rows[i] for i in range(11, 17)] == [1] * 5 + [0], "second session")
    print("  two concurrent sessions / independent counters: PASS")


def case_autocommit(host):
    prepare(host)
    with session(host) as writer:
        for row in range(1, 7):
            check(state(writer) == (1, 0), "not between implicit transactions")
            sql(writer, "UPDATE cc_autocommit_events SET touched = touched + 1 WHERE id = %s", (row,))
            check(state(writer) == (1, 0), "implicit UPDATE left transaction open")
            check(sum(durable(host, "cc_autocommit_events").values()) == row,
                  "independent autocommit transaction not immediately durable")
        # A cooperative wrapper must explicitly start a NEW transaction each
        # time; no cumulative budget exists across these six transactions.
        for row in range(1, 7):
            sql(writer, "CALL cc_start()")
            update(writer, row)
            sql(writer, "COMMIT")
    check([durable(host)[i] for i in range(1, 7)] == [1] * 6, "six fresh guarded transactions")
    print("  autocommit=ON: six bare UPDATEs immediately durable as SIX transactions; six separately guarded transactions each get fresh budget")


def case_unguarded_after_commit(host):
    prepare(host)
    with session(host) as writer:
        sql(writer, "CALL cc_start()")
        update(writer, 1)
        sql(writer, "COMMIT")
        # A persistent helper row is NOT a transaction-ID oracle: the trigger
        # will permit the remaining four events without a new guard.
        check(state(writer) == (1, 0), "expected autocommit after COMMIT")
        update(writer, 2)
    check(durable(host)[2] == 1, "unexpected unguarded UPDATE result")
    print("  UNGUARDED UPDATE after COMMIT uses stale helper row: BYPASS CONFIRMED")


def case_restart_inside_denied_tx(host):
    prepare(host)
    with session(host) as writer:
        sql(writer, "CALL cc_start()")
        five(writer)
        denied(writer)
        # The writer has only EXECUTE on this definer routine, yet invoking it
        # again implicitly commits the *previous* top-level transaction.
        sql(writer, "CALL cc_start()")
        check([durable(host)[i] for i in range(1, 7)] == [1] * 5 + [0],
              "restart did not implicitly commit the denied transaction")
        sql(writer, "ROLLBACK")
    print("  CALL cc_start() after denial IMPLICITLY COMMITS prior five events: BYPASS CONFIRMED")


def case_privileges(host):
    prepare(host)
    with session(host, "root") as admin:
        print("  trigger definer:", sql(admin, "SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_NAME='cc_count_update'")[0][0])
        print("  routine definer:", sql(admin, "SELECT DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_NAME='cc_start'")[0][0])
    with session(host) as writer:
        print("  DB identity:", sql(writer, "SELECT CURRENT_USER(), USER()")[0])
        grants = [grant[0] for grant in sql(writer, "SHOW GRANTS")]
        # MariaDB embeds the password hash in SHOW GRANTS. Never emit it into
        # local/CI transcripts (the disposable password is still not a secret).
        print("  grants:", [re.sub(r" IDENTIFIED BY PASSWORD '[^']+'", " [password hash redacted]", grant)
                              for grant in grants])
        attacks = {
            "SELECT helper": "SELECT * FROM cc_state",
            "UPDATE helper": "UPDATE cc_state SET consumed=0",
            "DELETE helper": "DELETE FROM cc_state",
            "DROP helper": "DROP TABLE cc_state",
            "DROP trigger": "DROP TRIGGER cc_count_update",
            "CREATE trigger": "CREATE TRIGGER cc_replace BEFORE UPDATE ON cc_items FOR EACH ROW SET @cc_bypass=1",
            "ALTER table / disable keys": "ALTER TABLE cc_items DISABLE KEYS",
            "replace trigger / DEFINER": "CREATE OR REPLACE DEFINER=CURRENT_USER TRIGGER cc_count_update BEFORE UPDATE ON cc_items FOR EACH ROW SET @cc_bypass=1",
            "change binary logging": "SET SESSION sql_log_bin=0",
        }
        for label, statement in attacks.items():
            try:
                sql(writer, statement)
            except pymysql.MySQLError as error:
                print(f"  {label}: denied errno={error.args[0]} message={error.args[1]}")
            else:
                raise AssertionError(f"writer unexpectedly succeeded: {label}")
        # User variables and some session settings ARE writable. Demonstrate
        # that this particular accounting mechanism does not depend on them.
        sql(writer, "SET @cc_consumed=0, @cc_bypass=1")
        sql(writer, "SET SESSION foreign_key_checks=0")
        sql(writer, "SET SESSION sql_mode=''")
        sql(writer, "CALL cc_start()")
        five(writer)
        denied(writer)
        sql(writer, "ROLLBACK")
        print("  session user variables / foreign_key_checks / sql_mode writable; trigger still denies event six")


def main():
    for host, family, version_prefix in (
        ("mysql", "MySQL", "8.0.44"), ("mariadb", "MariaDB", "10.11.15")
    ):
        print(f"\n=== {family} ===", flush=True)
        with session(host, "root") as writer:
            version = sql(writer, "SELECT VERSION()")[0][0]
            print("  exact server version:", version)
            check(version.startswith(version_prefix), "unexpected DB version")
            print("  isolation:", sql(writer, "SHOW VARIABLES LIKE '%isolation%'"))
            print("  engine:", sql(writer, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='cc_research' ORDER BY TABLE_NAME"))
            check(all(engine == "InnoDB" for _, engine in sql(writer, "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='cc_research'")), "non-InnoDB table")
        for case in (case_safe, case_denial, case_savepoint_denial,
                     case_repeated_and_zero, case_allowed_savepoint,
                     case_isolation, case_autocommit, case_unguarded_after_commit,
                     case_restart_inside_denied_tx,
                     case_privileges):
            case(host)
        print(f"{family}: expected assertions PASS; sticky top-level denial FAIL (confirmed)", flush=True)


if __name__ == "__main__":
    main()
