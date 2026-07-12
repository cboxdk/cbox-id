# Deployment

From a fresh server to a running, hardened Cbox ID instance. This is an identity
provider — the guidance here is deliberately security-first.

## Requirements

- **PHP 8.4+** with `ext-sodium` and `ext-openssl` (the crypto layer needs both;
  `cbox-id:doctor` fails loudly if either is missing).
- A database — **PostgreSQL or MySQL** in production (not SQLite).
- A cache/queue backend — **Redis** recommended (sessions, rate limits, queues).
- **TLS terminated in front of the app.** Passkeys (WebAuthn) and secure cookies
  require HTTPS; the platform assumes it.

## 1. Install the code

```bash
composer install --no-dev --optimize-autoloader
```

## 2. Bootstrap

The guided installer generates the crypto master key, asks the few questions that
matter (issuer URL, passkey domain/origin), writes them to `.env`, runs migrations,
and mints the first signing key:

```bash
php artisan cbox-id:install
```

Prefer a non-interactive deploy? Set the environment variables yourself (see
[Configuration](configuration.md)) and run `php artisan migrate --force` instead —
`cbox-id:install` is the convenience path, not a requirement.

## 3. Optimize for production

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

Re-run these on every deploy after the code and `.env` are in place.

## 4. Create the first administrator

Onboarding provisions the first org and admin. Until the onboarding UI is wired for
your deployment, create the first subject/admin via your seeding path, then
immediately enroll MFA/passkey on that account — the first admin is the most
sensitive account on the system.

## 5. Run the workers

The platform relies on the queue (webhook delivery, the event outbox, async audit
work) and the scheduler (key retirement, cleanups):

```bash
php artisan queue:work --tries=3        # under a supervisor (systemd/supervisord)
php artisan schedule:run                # from cron, every minute
```

## 6. Verify

```bash
php artisan cbox-id:doctor
```

In production this also checks the **hardening** posture: `APP_DEBUG` off, secure +
encrypted session cookies. Treat any ✗ as release-blocking. A green doctor plus a
reachable `/.well-known/openid-configuration` means you're live.

## Reverse proxy notes

- Terminate TLS; forward the real scheme/host (`X-Forwarded-Proto`/`-Host`) and
  configure Laravel's trusted proxies so issuer URLs and cookie `Secure` flags are
  correct.
- The discovery, JWKS, token, introspection, SCIM and SAML ACS endpoints are all
  served by the app — no separate service to route.

## Where to go next

- [Configuration](configuration.md) — the env reference and secure defaults.
- [Operations](operations.md) — backups, key rotation, upgrades, break-glass.
