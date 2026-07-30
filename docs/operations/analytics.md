---
title: Analytics storage
weight: 4
description: Choosing where authentication analytics are stored — nothing, the app's own database, or ClickHouse — and the retention that keeps the choice viable.
---

# Analytics storage

The Analytics console reads authentication activity — logins, tokens issued, new
users, MFA enrolments — over a time window. Where those events are *stored* is a
deployment choice, and the three options differ in what they cost you rather than in
what the dashboards show.

## The three stores

| `CBOX_ID_ANALYTICS_STORE` | Events are… | Dashboards read… | Costs |
| --- | --- | --- | --- |
| `none` (default) | discarded | the platform's own usage counters | nothing |
| `database` | one row per event in `id_analytics_events` | that table | a table that grows with traffic |
| — (set `CBOX_ID_ANALYTICS_CLICKHOUSE_DSN`) | streamed to ClickHouse | ClickHouse | a column store to run |

A ClickHouse DSN always wins: set one and both the sink and the reader switch to it
regardless of `CBOX_ID_ANALYTICS_STORE`.

The sink and the reader are always swapped as a **matched pair**, so the dashboards
read back what the sink just wrote. There is no configuration in which one store is
written and another is read.

## `none` — the default

Events go to an inert sink. The Analytics area still renders; it reads the usage
counters the platform maintains anyway. Nothing accumulates, and there is nothing to
operate. This is the right choice until someone actually asks a question the usage
counters cannot answer.

## `database` — the low-volume answer

```dotenv
CBOX_ID_ANALYTICS_STORE=database
CBOX_ID_ANALYTICS_ENABLED=true
CBOX_ID_ANALYTICS_RETENTION_DAYS=365
```

One row per delivered domain event in `id_analytics_events`, in the app's own
database. No extra service, no extra connection, no extra thing to back up.

Two properties worth knowing:

- **Writes are idempotent through the engine.** `event_id` is the table's primary key
  and the sink writes with `insertOrIgnore`, so at-least-once outbox re-delivery
  collapses on a key conflict rather than in a read-then-write race. The same event
  arriving twice stays one row.
- **Writes fail open.** A broken analytics store degrades to "no analytics"; it never
  becomes a domain event that fails to deliver.

It is a **row store**. The dashboards' `GROUP BY` aggregates scan an index range
rather than a column segment, so growth shows up as dashboard latency — not as wrong
numbers. That latency, not a correctness cliff, is the signal to move to ClickHouse.

### Retention is not optional here

`id_analytics_events` is the one table in this application that grows with **traffic**
rather than with tenants. It is swept daily by the scheduled `model:prune`, using
`CBOX_ID_ANALYTICS_RETENTION_DAYS`:

```bash
php artisan model:prune --model="Cbox\Id\Analytics\Models\AnalyticsEvent"
```

If you run this store, run the scheduler. Without it the table grows without bound,
and that is the only way this option can hurt you.

## ClickHouse — the high-volume answer

```dotenv
CBOX_ID_ANALYTICS_CLICKHOUSE_DSN=http://clickhouse:8123
CBOX_ID_ANALYTICS_CLICKHOUSE_DATABASE=default
CBOX_ID_ANALYTICS_CLICKHOUSE_USER=app
CBOX_ID_ANALYTICS_CLICKHOUSE_PASSWORD=…
```

The target table is a `ReplacingMergeTree` keyed on `event_id`, so duplicate delivery
collapses on merge and retention is a table TTL rather than a scheduled sweep.

Note that ClickHouse is not offered by every hosting platform — Laravel Cloud has no
ClickHouse service — so on those you either point at an externally managed instance
or use the `database` store.

## Tenancy

Every read is scoped to the environment in context, in both stores, and an unscoped
read returns **nothing** rather than every environment's events. Analytics is
cross-tenant data in a single table; deny-by-default is the only safe reading of "no
environment is in context".
