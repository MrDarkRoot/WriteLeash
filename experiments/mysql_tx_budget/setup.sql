-- Disposable research only. The WordPress writer neither owns nor manages these objects.
USE cc_research;
CREATE TABLE cc_items (
    id INT PRIMARY KEY,
    touched INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE cc_autocommit_events (
    id INT PRIMARY KEY,
    touched INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE cc_state (
    connection_id BIGINT UNSIGNED PRIMARY KEY,
    consumed INT NOT NULL
) ENGINE=InnoDB;

DELIMITER //
CREATE PROCEDURE cc_start()
SQL SECURITY DEFINER
BEGIN
    -- START TRANSACTION implicitly commits any currently active transaction.
    START TRANSACTION;
    INSERT INTO cc_state (connection_id, consumed)
    VALUES (CONNECTION_ID(), 0)
    ON DUPLICATE KEY UPDATE consumed = 0;
END//
CREATE TRIGGER cc_count_update BEFORE UPDATE ON cc_items
FOR EACH ROW
BEGIN
    UPDATE cc_state SET consumed = consumed + 1
    WHERE connection_id = CONNECTION_ID() AND consumed < 5;
    IF ROW_COUNT() != 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MYSQL_ERRNO = 1644, MESSAGE_TEXT = 'CommitCap research: UPDATE budget exhausted or no guard';
    END IF;
END//
DELIMITER ;

CREATE USER 'cc_writer'@'%' IDENTIFIED BY 'disposable_writer_password';
GRANT SELECT, UPDATE ON cc_research.cc_items TO 'cc_writer'@'%';
GRANT SELECT, UPDATE ON cc_research.cc_autocommit_events TO 'cc_writer'@'%';
GRANT EXECUTE ON PROCEDURE cc_research.cc_start TO 'cc_writer'@'%';
