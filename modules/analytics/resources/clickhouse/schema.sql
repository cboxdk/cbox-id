-- Cbox ID analytics — ClickHouse event store.
--
-- One row per delivered domain event. Delivery off the transactional outbox is
-- at-least-once, so the same event id can arrive more than once; the
-- ReplacingMergeTree engine keyed on `event_id` collapses those duplicates on
-- merge (and `FINAL` / de-dup queries collapse them at read time), giving
-- exactly-once semantics without a write-side dedup table.
CREATE TABLE IF NOT EXISTS id_analytics_events
(
    event_id       String,
    type           String,
    metric         String,
    organization_id String,
    environment_id String,
    occurred_at    DateTime,
    payload_digest String,
    ingested_at    DateTime DEFAULT now()
)
ENGINE = ReplacingMergeTree(ingested_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (event_id);
