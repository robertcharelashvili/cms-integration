-- rilven-sync: a payroll run is sent to Rilven only once CONFIRMED (owner or accounting), and only
-- as it stood when confirmed. Run on the CMS database BEFORE installing the Rilven_payroll.php and
-- Billers.php that read these columns. Safe to re-run.
--
-- Existing runs are correct (the clinic's word, 2026-09-30): the LATEST run of every month is
-- confirmed now, with its current fingerprint -- so the sync carries on exactly as before. Only
-- the latest: confirming every run would let a reopened month fall back to an older one.

ALTER TABLE sma_daricxva
  ADD COLUMN IF NOT EXISTS rilven_status TINYINT NOT NULL DEFAULT 0
      COMMENT 'Rilven: 1 = confirmed for payroll (owner/accounting), 0 = being calculated',
  ADD COLUMN IF NOT EXISTS rilven_status_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS rilven_status_user INT NULL,
  ADD COLUMN IF NOT EXISTS rilven_fingerprint VARCHAR(64) NULL
      COMMENT 'did:lines:total at confirmation (Rilven_payroll::fingerprintOf)';

-- the fingerprint exactly as Rilven_payroll::fingerprints() computes it (salary types 1-6, > 0)
UPDATE sma_daricxva d
  JOIN (SELECT MAX(id) AS id FROM sma_daricxva GROUP BY DATE_FORMAT(post_date, '%Y-%m')) l ON l.id = d.id
  LEFT JOIN (SELECT did, CONCAT(did, ':', COUNT(*), ':', CAST(SUM(ROUND(daricxuli_sul * 10000)) AS SIGNED)) AS fp
               FROM sma_daricxva_by_staff
              WHERE salary_type IN (1, 2, 3, 4, 5, 6) AND daricxuli_sul > 0
              GROUP BY did) f ON f.did = d.id
   SET d.rilven_status = 1,
       d.rilven_status_at = NOW(),
       d.rilven_fingerprint = COALESCE(f.fp, CONCAT(d.id, ':0:0'))
 WHERE d.rilven_status = 0 AND d.rilven_fingerprint IS NULL;

SELECT COUNT(*) AS runs, SUM(rilven_status) AS confirmed, MIN(post_date) AS first_run, MAX(post_date) AS last_run
  FROM sma_daricxva;
SELECT id, DATE_FORMAT(post_date, '%Y-%m') AS month, rilven_status, rilven_fingerprint
  FROM sma_daricxva WHERE post_date >= '2026-06-01' ORDER BY id;
