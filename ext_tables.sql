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

-- Help topics imported by any registered SourceImporter (DITA-OT XHTML
-- drop, single-file uploads, …). One row per (topic, language) —
-- sys_language_uid + l10n_parent so translations imported from sibling
-- folders chain back to the default-language source.
-- The `identifier` is the importer-supplied natural key within a single
-- language; HelpDocSchemaProvider builds document ids of the form
-- "help-<uid>" (and "help-<uid>-l<n>" for translations) so they align
-- with how FileSchemaProvider handles multilingual docs.
CREATE TABLE tx_wsmeilisearch_helpdoc (
    identifier         VARCHAR(190) DEFAULT '' NOT NULL,
    title              VARCHAR(512) DEFAULT '' NOT NULL,
    abstract           TEXT,
    body               MEDIUMTEXT,
    help_type          VARCHAR(32)  DEFAULT '' NOT NULL,
    parent_identifier  VARCHAR(190) DEFAULT '' NOT NULL,
    source_path        VARCHAR(512) DEFAULT '' NOT NULL,
    -- Primary media (image / video) attached to the topic by the
    -- importer and copied into fileadmin/<helpdoc-root>/<identifier>/.
    -- Stored as FAL count; sys_file_reference holds the link.
    media              INT(11) UNSIGNED DEFAULT '0' NOT NULL,
    tx_wsmeilisearch_boost TINYINT(1) UNSIGNED DEFAULT 2 NOT NULL,

    KEY identifier_language (identifier(64), sys_language_uid),
    KEY parent (parent_identifier(64))
);