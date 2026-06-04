CREATE TABLE tx_scheduler_task (
    tx_wsmeilisearch_site_identifier VARCHAR(255) DEFAULT '' NOT NULL,
    tx_wsmeilisearch_rebuild         TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    tx_wsmeilisearch_skip_embedder   TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL
);

-- Editor-controlled per-record relevance boost (0=very low … 4=very high,
-- 2=normal/default). The composite multiplier (type × record) is written
-- to the `boost` field on every document by the indexer pipeline; see
-- BoostCalculator + IndexEventListener + NewsSchemaProvider.
CREATE TABLE pages (
    tx_wsmeilisearch_boost TINYINT(1) UNSIGNED DEFAULT 2 NOT NULL
);

CREATE TABLE tx_news_domain_model_news (
    tx_wsmeilisearch_boost TINYINT(1) UNSIGNED DEFAULT 2 NOT NULL
);