-- Every Rilven product a CMS medicine IS, with how many of its pieces one CMS unit is -- one
-- medicine bought from several suppliers is several products there. Learned from the purchases
-- the two systems share (sma_purchases.reference_no = the RS waybill Rilven imported, line by
-- line), from the name matches, and from the products created for the history. Used to bring
-- 2025-2026 consumption over (admin/rilven_medic history_*). Additive and safe to re-run.
CREATE TABLE IF NOT EXISTS sma_rilven_product_link (
    product_id     INT            NOT NULL,
    rilven_sku_id  BIGINT         NOT NULL,
    factor         DECIMAL(18,6)  NOT NULL DEFAULT 1,
    source         VARCHAR(16)    NOT NULL,
    purchases      INT            NOT NULL DEFAULT 0,
    created_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, rilven_sku_id),
    KEY ix_rilven_product_link_sku (rilven_sku_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
