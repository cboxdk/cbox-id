# Cbox ID Whitelabel (commercial)

**`cboxdk/laravel-id-whitelabel`** — per-tenant white-label branding for Cbox ID, as a
drop-in plugin. It themes the admin console **and** the hosted sign-in surface from each
tenant's own palette, logo, favicon, app name, custom domain and email sender — data
**and** UI in one package, with zero edits to the host.

Install it and every environment (and, optionally, every organization) can brand its own
console and login; leave it out and the shell falls back to the static Cbox theme.
Nothing in the open, self-hostable app depends on it.

## What it adds

- **Tenant palette → CSS tokens** — a validated `token → colour` map (hex or
  `oklch(...)` only) mapped to the same CSS custom properties Cbox ID's stylesheet reads
  (`--primary`, `--accent`, `--ring`, `--foreground`, `--background`). Everything is
  validated server-side and re-validated by console-kit's `Branding` VO before it ever
  reaches a `<style>` tag — deny-by-default, so an injected value is dropped, never echoed.
- **Logo & favicon** — uploaded through the `BrandAssetStore` contract. The default
  `LocalBrandAssetStore` writes to a Laravel disk (no S3 dependency); an
  `ObjectStorageBrandAssetStore` is a drop-in for a horizontally-scaled deployment.
- **Custom domain** — sets `environments.domain`, which laravel-id's existing
  `DatabaseEnvironmentResolver` already consults, so a vanity host is served with no new
  routing. Hosts are validated and run through `cboxdk/laravel-ssrf`'s guard (reused, never
  loosened): IP literals and private/reserved hosts are refused.
- **App name & email sender** — per-tenant product name and email `from` name, plus a
  simple email-template override.
- **Console** — a gated **Settings → Branding** page and a dashboard card, both keyed off
  the `whitelabel` feature.

## How it plugs in

- Branding registers on **console-kit's `BrandingResolver`**
  (`bind(BrandingResolver::class, TenantBrandingResolver::class)`) from the provider's
  `register()` — it replaces the inert null resolver, so the shell's `@consoleBrandingStyle`
  starts emitting the current tenant's palette with no host edit.
- Profiles are hard-scoped to their environment by laravel-id's `BelongsToEnvironment`, so a
  brand in one environment is never visible or writable from another.
- Console (nav, gate, dashboard card) plugs into
  [`laravel-console-kit`](https://github.com/cboxdk/laravel-console-kit), gated on the
  `whitelabel` feature.

## Install (SaaS)

```bash
composer require cboxdk/laravel-id-whitelabel
php artisan migrate
```

Swap the asset backend for object storage in a service provider:

```php
$this->app->bind(\Cbox\Id\Whitelabel\Assets\BrandAssetStore::class, /* ObjectStorageBrandAssetStore */);
```

Requires laravel-id's tenancy kernel and a host console that has adopted
`laravel-console-kit` (≥ 0.2.1, for the `BrandingResolver` hook).

## License

**Commercial / proprietary.** © Cbox, all rights reserved. Private, SaaS-internal; use
requires a written commercial agreement with Cbox. See [LICENSE](LICENSE).
