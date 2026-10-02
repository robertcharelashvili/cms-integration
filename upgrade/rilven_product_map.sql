-- Which Rilven product (asset SKU) each CMS medicine is written off as, and how many Rilven units
-- one CMS unit is. Kept HERE, in the CMS: Rilven keeps no map of an outside catalogue -- every
-- system that writes stock off in Rilven maps its own products to Rilven's.
-- Additive and safe to re-run.
CREATE TABLE IF NOT EXISTS sma_rilven_product_map (
    product_id      INT            NOT NULL PRIMARY KEY,
    rilven_sku_id   BIGINT         NOT NULL,
    factor          DECIMAL(15,4)  NOT NULL DEFAULT 1,
    note            VARCHAR(255)   NULL,
    updated_by      INT            NULL,
    updated_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_rilven_product_map_sku (rilven_sku_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
