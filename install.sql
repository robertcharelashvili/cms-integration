-- ---------------------------------------------------------------------------
-- Rilven sync: the two tables the clinic's own database needs.
--
-- Change the prefix if this installation does not use sma_ (config/database.php,
-- 'dbprefix'). Everything else in the library goes through CodeIgniter's query
-- builder, which applies the prefix itself -- this file is the one place it has
-- to be written out.
--
-- Safe to re-run, and safe to run over the earlier version of these tables: the
-- columns added since are added only if they are missing.
--
-- Run it through the mysql client, or through phpMyAdmin's SQL tab with the
-- delimiter box set to // -- the upgrade section at the bottom defines a
-- procedure, and DELIMITER has to be understood for that to parse.
-- ---------------------------------------------------------------------------

-- What is waiting to go to Rilven.
--
-- A queue and not a direct call from the save handler, because the alternative is
-- a registration desk that waits on somebody else's server. A patient must be
-- saveable while Rilven is down, being deployed, or simply slow.
CREATE TABLE IF NOT EXISTS sma_rilven_outbox (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    -- Which register the row belongs in over there: 'contractor', 'category' or
    -- 'service'. They share this queue and its unique key, which is what lets one
    -- cron run send all three and one status page report on all three.
    entity        VARCHAR(20)  NOT NULL,
    -- The source row's own id -- sma_companies.id, sma_categories.id or
    -- sma_products.id, depending on `entity` -- and, verbatim, the `code` of the
    -- record over there. This column IS the link between the two systems.
    --
    -- The pair (entity, external_id) is what the unique key enforces, so the same
    -- number in two registers is two rows and not a collision: a patient and a
    -- category may both be 114.
    external_id   VARCHAR(80)  NOT NULL,
    -- What kind of counterparty this is over there. Informational.
    type_code     VARCHAR(40)  NOT NULL,
    -- 0 pending, 1 sent, 2 failed (will be retried), 3 given up
    status        TINYINT      NOT NULL DEFAULT 0,
    attempts      INT          NOT NULL DEFAULT 0,
    -- sha-256 of the payload as last ACCEPTED by Rilven. A row re-queued with an
    -- unchanged hash is closed before it costs a request.
    payload_hash  VARCHAR(64)  DEFAULT NULL,
    -- Their id for this patient, kept as it comes back.
    --
    -- Nothing in the sync reads it: the link is `code`, and a lookup by code needs
    -- no map. It is recorded because the last time this integration kept its
    -- mapping only on the far side, the far side's map table was dropped and
    -- sixty-nine thousand links could not be reconstructed from either database.
    -- This column costs a few bytes a row and is that reconstruction.
    rilven_id         BIGINT   DEFAULT NULL,
    rilven_branch_id  BIGINT   DEFAULT NULL,
    last_error    VARCHAR(500) DEFAULT NULL,
    created_at    DATETIME     NOT NULL,
    updated_at    DATETIME     DEFAULT NULL,
    -- One entry per patient: somebody edited eleven times before the cron runs is
    -- one thing to send, not eleven.
    UNIQUE KEY ux_rilven_outbox_row (entity, external_id),
    KEY ix_rilven_outbox_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Where the library keeps its own small facts: the session cookies it signed in
-- with, and the reference ids it resolved once so it need not ask again.
CREATE TABLE IF NOT EXISTS sma_rilven_state (
    name          VARCHAR(60)  NOT NULL PRIMARY KEY,
    value         VARCHAR(255) DEFAULT NULL,
    updated_at    DATETIME     DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Upgrading an installation that already has the first version of these tables.
--
-- Written through information_schema rather than as ALTER ... ADD COLUMN IF NOT
-- EXISTS, which MariaDB understands and MySQL does not; these installations are
-- forks and not all of them are on the same server.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS rilven_add_column;
DELIMITER //
CREATE PROCEDURE rilven_add_column(IN tbl VARCHAR(64), IN col VARCHAR(64), IN spec VARCHAR(255))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = col) THEN
        SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', spec);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL rilven_add_column('sma_rilven_outbox', 'rilven_id',        'BIGINT DEFAULT NULL');
CALL rilven_add_column('sma_rilven_outbox', 'rilven_branch_id', 'BIGINT DEFAULT NULL');

-- What Rilven last told us the document's status was: 1 draft, 2 confirmed, 3 verified.
--
-- Kept HERE and not asked for over the wire, because the CMS reads it on every save of a case
-- and a save must never wait on somebody else's server -- that is the whole reason this is a
-- queue. A confirmed document has a posting in the books behind it, and letting the clinic edit
-- the case underneath it silently is how the two systems stop agreeing.
--
-- NULL means "never sent, or never confirmed", which is the safe reading: the guard only refuses
-- what it positively knows is posted.
CALL rilven_add_column('sma_rilven_outbox', 'rilven_status',    'TINYINT DEFAULT NULL');

-- A digest of the source case as it was when Rilven last accepted it.
--
-- Because `sma_sales.updated_at` cannot carry this. It is a plain timestamp with no ON UPDATE
-- clause, and only three of the fork's twenty-seven handlers that write the sales table set it --
-- not the calculation screens that rewrite line prices, and not updateStatus(), which is the one
-- transition an inpatient case posts on. A timestamp sweep therefore misses exactly the changes
-- that matter. This compares content instead. See Rilven::refreshSalesByFingerprint().
CALL rilven_add_column('sma_rilven_outbox', 'source_fingerprint', 'VARCHAR(32) DEFAULT NULL');

-- ---------------------------------------------------------------------------
-- Make the queue speak the same character set as the tables it is joined to.
--
-- sma_rilven_outbox.external_id is compared against the source row's id on every sweep, and a
-- comparison between two different character sets or two different collations of one character
-- set is not a slow query -- it is error 1267 and no query at all. The clinic's own tables are
-- often utf8mb3 (`utf8_general_ci`) from an installation made years ago, while CREATE TABLE here
-- takes the SERVER's default, which on a modern box is utf8mb4. Nothing warns about it: CodeIgniter
-- pins the connection collation, so the mismatch stays hidden until something connects differently
-- -- phpMyAdmin, a reporting tool, a colleague's client -- and then the sweep dies.
--
-- So the queue is not given a character set of its own. It is CONVERTED to whatever `sales` uses,
-- read out of information_schema at install time, which is right on every fork without anyone
-- having to know what theirs is.
--
-- Converting DOWN to utf8mb3 cannot hold 4-byte characters (emoji, some rare scripts). Only
-- `last_error` could ever contain free text, and Georgian is 3 bytes, so this is safe here -- but
-- it is the one thing to think about before running it on a fork that stores something else.
-- ---------------------------------------------------------------------------

SET @rilven_collation := (
    SELECT TABLE_COLLATION FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sma_sales');

SET @rilven_charset := (
    SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS
     WHERE COLLATION_NAME = @rilven_collation);

SET @rilven_current := (
    SELECT TABLE_COLLATION FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sma_rilven_outbox');

-- No-op when they already agree, and when `sales` is not there to ask.
SET @rilven_sql := IF(
    @rilven_collation IS NULL OR @rilven_charset IS NULL OR @rilven_current = @rilven_collation,
    'DO 0',
    CONCAT('ALTER TABLE sma_rilven_outbox CONVERT TO CHARACTER SET ',
           @rilven_charset, ' COLLATE ', @rilven_collation));

PREPARE rilven_align FROM @rilven_sql;
EXECUTE rilven_align;
DEALLOCATE PREPARE rilven_align;

-- What it settled on. These two must match, or every sweep that joins them is error 1267.
SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('sma_sales', 'sma_sale_items', 'sma_rilven_outbox');

DROP PROCEDURE IF EXISTS rilven_add_column;

-- The session token does not fit the 200 characters the first version allowed.
ALTER TABLE sma_rilven_state MODIFY value VARCHAR(255) DEFAULT NULL;

-- NOTHING BELOW THIS POINT IS NEEDED FOR THE SERVICE REGISTERS. They were designed
-- into these tables from the start -- `entity` is why the unique key is a pair -- so
-- adding categories and services required no DDL at all. An installation already
-- running the patient sync needs only the new PHP files and the new config.

-- A high-water mark this library no longer keeps. It was shared between kinds and
-- silently put every patient below the highest supplier id out of reach; the
-- catch-up now asks the outbox instead, which cannot drift.
DELETE FROM sma_rilven_state WHERE name = 'backfill_cursor';
