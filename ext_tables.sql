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

-- Knowledge resources imported by any registered SourceImporter
-- (DITA-OT XHTML drop, single-file uploads, …). One row per (topic,
-- language) — sys_language_uid + l10n_parent so translations imported
-- from sibling folders chain back to the default-language source.
-- The `identifier` is the importer-supplied natural key within a
-- single language; KnowledgeResourceSchemaProvider builds document ids
-- of the form "knowledge-<uid>" (and "knowledge-<uid>-l<n>" for
-- translations) so they align with how FileSchemaProvider handles
-- multilingual docs.
--
-- Historically called "help docs" (tx_wsmeilisearch_knowledge_resource) — renamed
-- 2026-06 to clarify the role: these are RAG-context resources, not
-- public help pages. They are indexed for grounding but hidden from
-- the FE search result list and from the RAG answer's "Sources"
-- panel. KnowledgeResourceMigration upgrade wizard renames the
-- existing table in-place; nothing is dropped or copied.
CREATE TABLE tx_wsmeilisearch_knowledge_resource (
    identifier         VARCHAR(190) DEFAULT '' NOT NULL,
    title              VARCHAR(512) DEFAULT '' NOT NULL,
    abstract           TEXT,
    body               MEDIUMTEXT,
    resource_type      VARCHAR(32)  DEFAULT '' NOT NULL,
    parent_identifier  VARCHAR(190) DEFAULT '' NOT NULL,
    source_path        VARCHAR(512) DEFAULT '' NOT NULL,
    -- Primary media (image / video) attached to the resource by the
    -- importer and copied into fileadmin/<knowledge-root>/<identifier>/.
    -- Stored as FAL count; sys_file_reference holds the link.
    media              INT(11) UNSIGNED DEFAULT '0' NOT NULL,
    tx_wsmeilisearch_boost TINYINT(1) UNSIGNED DEFAULT 2 NOT NULL,

    KEY identifier_language (identifier(64), sys_language_uid),
    KEY parent (parent_identifier(64))
);

-- RAG regression tests: editor-maintained (question, expected answer)
-- pairs that are periodically run against the configured RAG provider
-- so a model rotation / prompt tune / context-window change can't
-- silently degrade answer quality. RagTestRunner computes cosine
-- similarity between expected and actual answer via the site's
-- configured embedder; pass / fail is decided against the per-record
-- similarity_threshold. Last run's state is persisted on the row so
-- the BE List module shows a current pass / fail badge.
CREATE TABLE tx_wsmeilisearch_ragtest (
    uid                   INT(11) UNSIGNED AUTO_INCREMENT NOT NULL,
    pid                   INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    tstamp                INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate                INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    deleted               SMALLINT(5) UNSIGNED DEFAULT 0 NOT NULL,
    hidden                SMALLINT(5) UNSIGNED DEFAULT 0 NOT NULL,

    title                 VARCHAR(255) DEFAULT '' NOT NULL,
    question              TEXT,
    expected_answer       TEXT,
    -- Pass threshold for cosine similarity. 0.85 is a sane default for
    -- nomic-embed-text; tune per record if a question can be answered
    -- in many wordings.
    similarity_threshold  DECIMAL(4,3) UNSIGNED DEFAULT '0.850' NOT NULL,
    -- Which site to run the test against. Empty = first site that has
    -- a RAG provider configured.
    site_identifier       VARCHAR(64) DEFAULT '' NOT NULL,
    -- Persisted state of the last run — read by the BE List module so
    -- editors can see pass / fail at a glance without re-running.
    last_run_at           INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    last_score            DECIMAL(4,3) UNSIGNED DEFAULT NULL,
    last_status           VARCHAR(16) DEFAULT '' NOT NULL,
    last_actual_answer    MEDIUMTEXT,
    last_error            TEXT,

    PRIMARY KEY (uid),
    KEY parent (pid)
);

-- One row per RagTestRunner invocation per test. Drives the
-- score-history sparkline in the BE tab + lets operators eyeball
-- the trend after a model rotation. Pruned to RagTestRunner::HISTORY_KEEP
-- entries per test on each new insert (rolling window, bounded
-- growth without per-row maintenance).
CREATE TABLE tx_wsmeilisearch_ragtest_run (
    uid             INT(11) UNSIGNED AUTO_INCREMENT NOT NULL,
    pid             INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    crdate          INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    -- Soft FK to tx_wsmeilisearch_ragtest.uid; no DBAL constraint
    -- declared because TYPO3's deleted=1 soft-delete + replicated
    -- environments make hard FKs brittle.
    test_uid        INT(11) UNSIGNED DEFAULT 0 NOT NULL,
    status          VARCHAR(16) DEFAULT '' NOT NULL,
    score           DECIMAL(4,3) UNSIGNED DEFAULT NULL,
    actual_answer   MEDIUMTEXT,
    error_message   TEXT,

    PRIMARY KEY (uid),
    KEY test_recent (test_uid, crdate)
);