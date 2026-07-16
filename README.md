# Cbox ID Risk-Plus (commercial)

**`cboxdk/laravel-id-risk-plus`** — premium adaptive-risk for Cbox ID, as a drop-in
plugin. It contributes two high-signal checks to
[`laravel-risk`](https://github.com/cboxdk/laravel-risk)'s pipeline and lights up a
**Security** console to review what fired — data **and** UI in one package, with zero
edits to the host.

Install it and the signals join the risk score and the console appears; leave it out and
the pipeline falls back to laravel-risk's free-core signals. Nothing in the open,
self-hostable app depends on it.

## What it adds

- **Impossible-travel signal** (`geo.impossible_travel`) — flags an account seen from two
  locations too far apart to have travelled between in the elapsed time (default: an
  implied speed over 900 km/h). The geo source is yours to wire (MaxMind, an IP-intel
  API, an edge header) via the `GeoLocator` contract; until one is bound it's inert, so
  there are no false positives out of the box.
- **New-device signal** (`device.new`) — flags a sign-in from a device fingerprint this
  account hasn't been seen on. The first device is enrolment (never punished); only
  *subsequent* new devices score. Fingerprints are hashed, bounded, and TTL'd — no raw
  device data is stored.
- **Security console** — a gated **Risk events** page listing elevated assessments, a
  recording listener behind it, and a dashboard card (elevated events, last 24h).

Both signals fail open at every step (no subject, no IP, no geo result, non-positive
interval → no signal, never a block) and store only HMAC'd, TTL'd keys — never raw
emails, IPs, or locations.

## How it plugs in

- Signals register on **laravel-risk's `SignalRegistry`** (`Risk::signals()->register(...)`)
  from the provider's `boot()` — the framework hook that needs no host config edit. Host
  operators can still re-weight or disable either signal from `config('risk.weights')`.
- Console (nav, gate, dashboard card) plugs into
  [`laravel-console-kit`](https://github.com/cboxdk/laravel-console-kit), gated on the
  `risk-plus` feature.

## Install (SaaS)

```bash
composer require cboxdk/laravel-id-risk-plus
php artisan migrate
```

Bind a real `GeoLocator` to switch on impossible-travel:

```php
$this->app->bind(\Cbox\Id\RiskPlus\Contracts\GeoLocator::class, MaxMindGeoLocator::class);
```

Requires `laravel-risk` in the app's request pipeline and a host console that has adopted
`laravel-console-kit`.

## License

**Commercial / proprietary.** © Cbox, all rights reserved. Private, SaaS-internal; use
requires a written commercial agreement with Cbox. See [LICENSE](LICENSE).
