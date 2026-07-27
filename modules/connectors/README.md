# Cbox ID Connectors (commercial)

**`cboxdk/laravel-id-connectors`** — a unified connector catalog and console for Cbox ID,
as a drop-in plugin. It plugs a single **Connectors** area OVER the platform's existing
provisioning, webhook, directory-sync and federation modules — one catalog of what the
platform can integrate with, and one per-organization view of every live connection —
with zero edits to the host and no new schema.

Install it and the Connectors area appears; leave it out and the open, self-hostable app
is entirely unaffected. Nothing in the framework depends on it.

## What it adds

- **Connector catalog** — a browsable list of the four connector TYPES the platform speaks,
  each described by a `ConnectorDescriptor` that names the public module contract behind it:
  outbound SCIM provisioning (`ProvisioningConnections`), event webhooks (`WebhookRegistry`),
  inbound directory sync (`Directories`) and upstream IdP federation (`Connections`).
- **Unified connections view** — one normalised, per-organization list of live connections
  across the modules, with lifecycle status and (when wired) delivery health, plus a
  dashboard card counting active connectors.

## How it plugs in

- It reads the **existing laravel-id module contracts** — never their concrete models — the
  same seam the host's own pages use. No new framework hook is added.
- Console (nav area, feature gate, dashboard card) plugs into
  [`laravel-console-kit`](https://github.com/cboxdk/laravel-console-kit), gated on the
  `connectors` feature.
- It **coexists** with the host's existing per-module pages; it does not replace or remove
  them.

## Delivery health is pluggable — no column store required

`ConnectorAnalytics` is a contract that lives in this plugin, so the open framework carries
no analytics dependency:

- **Default (inert).** `NullConnectorAnalytics` reports no health; the connections view
  still renders lifecycle status straight from the module contracts, just without health
  badges. Installing with no backend is safe and free.
- **Wired (SaaS).** The host binds a real `ConnectorAnalytics` (e.g. a ClickHouse
  delivery-log reader) and the health badges light up — with no UI change. The column store
  is referenced only behind the contract.

## Honest scope

The plugin stands strictly on each module's PUBLIC contract, so what it can enumerate is
bounded by that surface:

- **Provisioning** and **federation** connections are listed from `active()` /
  `forOrganization()`.
- **Webhook** endpoints are recovered by unioning `matching()` over a configurable set of
  candidate event types; paused endpoints are never returned by the contract and so are not
  shown.
- **Inbound directory sync** has no per-organization listing on its public contract, so its
  live directories are catalogued as a type but managed on the host's Directory pages, not
  enumerated here.

Connector credentials are never touched: registration continues to flow through each
module's own sealed-secret path (Crypto `SecretBox`), and this plugin stores no secrets.

## Install (SaaS)

```bash
composer require cboxdk/laravel-id-connectors
```

Requires `cboxdk/laravel-id` (the connector modules) and a host console that has adopted
`laravel-console-kit`.

## License

**Commercial / proprietary.** © Cbox, all rights reserved. Private, SaaS-internal; use
requires a written commercial agreement with Cbox. See [LICENSE](LICENSE).
