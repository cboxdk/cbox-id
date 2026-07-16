# Cbox ID Analytics (commercial)

**`cboxdk/laravel-id-analytics`** — usage analytics for Cbox ID, as a drop-in plugin.
It streams the platform's domain events into a pluggable report sink and lights up an
**Analytics** console with authentication dashboards — data **and** UI in one package,
with zero edits to the host.

Install it and events start flowing and the console appears; leave it out and the open,
self-hostable app is entirely unaffected. Nothing in the framework depends on it.

## What it adds

- **Event streaming** — a listener on the platform's public `EventDelivered` seam (the
  transactional outbox relay) projects every delivered domain event into a `ReportRecord`
  and writes it to the bound **`ReportSink`**. Delivery is at-least-once, so records carry
  the event id as their natural key and the sink dedupes on it; the listener fails open, so
  analytics never blocks event delivery.
- **Analytics console** — a gated **Overview** page charting `auth.*` activity (logins,
  tokens issued, new users, MFA enrolments, active organizations, MFA rate) over a
  configurable window, rendered as inline CSS/SVG bars (no chart CDN), plus a dashboard
  card (logins, last 24h).

## How it plugs in

- Streaming registers on **laravel-id's `EventDelivered`** event from the provider's
  `boot()` — the framework seam that needs no host config edit. New analytics is a
  listener, never a change to an emit site.
- Console (nav, gate, dashboard card) plugs into
  [`laravel-console-kit`](https://github.com/cboxdk/laravel-console-kit), gated on the
  `analytics` feature.

## The sink is pluggable — ClickHouse is optional

The **`ReportSink`** and **`ReportReader`** are contracts that live in this plugin, so the
open framework stays column-store-free:

- **No ClickHouse (default).** Events stream into the inert `NullReportSink` and the
  dashboards read the platform's own per-day usage counters (Postgres/SQLite) through the
  `UsageMeter`. This works self-hosted with nothing extra — flip `id-analytics.enabled` on
  to show the console.
- **ClickHouse (SaaS scale).** Set a ClickHouse HTTP DSN and the sink and reader switch
  over to it automatically. ClickHouse is referenced **only** inside this plugin, behind
  the contracts, and only when a DSN is configured. The event table is a
  `ReplacingMergeTree` keyed on the event id, so at-least-once re-delivery collapses to one
  row.

## Install (SaaS)

```bash
composer require cboxdk/laravel-id-analytics
```

Reads usage from Postgres out of the box. To use ClickHouse, set the DSN and install the
schema:

```bash
# .env
ID_ANALYTICS_CLICKHOUSE_DSN=http://clickhouse:8123
ID_ANALYTICS_CLICKHOUSE_DATABASE=analytics

php artisan id-analytics:install         # creates the ReplacingMergeTree table
php artisan id-analytics:install --print # or print the DDL to run by hand
```

Requires `cboxdk/laravel-id` (the outbox + usage meter) and a host console that has adopted
`laravel-console-kit`.

## License

**Commercial / proprietary.** © Cbox, all rights reserved. Private, SaaS-internal; use
requires a written commercial agreement with Cbox. See [LICENSE](LICENSE).
