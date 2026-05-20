CREATE TABLE tx_scheduler_task (
    tx_wsmeilisearch_site_identifier VARCHAR(255) DEFAULT '' NOT NULL,
    tx_wsmeilisearch_rebuild         TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
    tx_wsmeilisearch_skip_embedder   TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL
);
