-- What the mapped Rilven product is called, so the mapping screen can show it without asking
-- Rilven for every row. A copy taken when the row is saved; Rilven's id stays the reference.
-- Additive and safe to re-run (MariaDB 10.3+).
ALTER TABLE sma_rilven_product_map
    ADD COLUMN IF NOT EXISTS rilven_name    VARCHAR(500) NULL AFTER factor,
    ADD COLUMN IF NOT EXISTS rilven_code    VARCHAR(100) NULL AFTER rilven_name,
    ADD COLUMN IF NOT EXISTS rilven_measure VARCHAR(64)  NULL AFTER rilven_code;
