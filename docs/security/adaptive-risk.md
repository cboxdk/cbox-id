---
title: Adaptive risk (risk-based authentication)
weight: 41
description: How the app scores each sign-in and adapts — allow, step-up, or deny — using cboxdk/laravel-risk, and how to set an enforcement threshold from the recorded evidence.
---

# Adaptive risk

Sign-in is **risk-aware**. Every authentication attempt is scored, and under
enforcement the app adapts the flow to the risk: let it through, demand an extra
factor (step-up), or block it. Scoring is an **app-layer** concern — the
`cboxdk/laravel-id` framework deliberately ships no risk engine; the app composes
[`cboxdk/laravel-risk`](https://packagist.org/packages/cboxdk/laravel-risk) for it,
bridged by `App\Platform\RiskGuard`.

## What is scored

`RiskGuard::assess()` runs the configured signals over the request — velocity
(credential-stuffing / bot rate per IP), IP reputation (ipsum blocklists), Tor exit
nodes, user-agent anomalies, and (on signup) disposable-email / MX / honeypot. The
signals produce a score that maps to an outcome: `Allow → Flag → Challenge →
StepUp → Reject` (increasing severity). Every assessment is **recorded to the
database** with its reasons and per-signal points — see
[the decision trail](#the-decision-trail).

## How the app adapts

Behaviour depends on `RISK_MODE`:

- **`monitor` (default)** — score and log only. Nothing is blocked. Ship here and
  **calibrate thresholds against real traffic first**, so you don't lock out
  legitimate users on day one.
- **`enforce`** — the app acts on the outcome:
  - **Reject** → the sign-in is hard-blocked. This gate covers **all** entry points:
    password, magic-link (blocked *before* the single-use token is consumed, so a
    user can retry from a safer network), and passkey.
  - **Challenge / StepUp** → an **additional factor** is required before the session
    is established:
    - if the account has an authenticator (TOTP), it goes through the normal MFA
      challenge (`/mfa`);
    - if it has no second factor, the app **emails a one-time code** (`/login/step-up`)
      — possession of the inbox — rather than letting a risky sign-in through or
      locking the account out. The resulting session records `amr: ['pwd','otp']`, so
      it counts as a two-factor (aal2) login downstream.
  - **Flag / Allow** → the attempt proceeds; Flag is recorded for review.

Because magic-link and passkey are themselves possession / phishing-resistant
factors, elevated-but-not-reject outcomes only trigger a step-up on the **password**
path; those two paths honour the Reject block but need no additional factor.

## Signup, specifically

Signup is scored by the same guard, with two extra signals the form supplies — a
honeypot field a human never fills, and the time between render and submit — and it
acts on the outcome differently from sign-in, because there is no account to step up
*into* yet.

### Challenge → CAPTCHA (Cloudflare Turnstile)

Under enforcement, a **Challenge / StepUp** outcome on signup demands a CAPTCHA before
the account is created. The widget is Cloudflare Turnstile: for almost everyone it is
non-interactive (no images to label), it sets no advertising cookies, and it renders
**only on a submission the scorer already flagged** — an unconditional CAPTCHA would
tax every legitimate signup to stop the small share that isn't one.

- The browser's own success callback is never trusted: the token is verified
  **server-side** against Turnstile's `siteverify` with the secret key.
- A missing, replayed or rejected token is a **field error** on the form (with the
  widget now shown, so the person can satisfy it) — never a 500 and never a silent pass.
- If Cloudflare cannot be reached the submission is refused rather than waved through.
  Only already-elevated submissions reach that path, and they can retry.
- **With no keys configured the feature is inert**: no widget, no third-party script, no
  CSP exception, and signup behaves exactly as it did before. Self-hosters who don't
  want a Cloudflare dependency simply don't set the keys.

The **CSP** is opened for `https://challenges.cloudflare.com` in `script-src` and
`frame-src` **only when Turnstile is configured** — the sole third-party origin this app
ever allows, and only on deployments that asked for it.

### Verification before provisioning

Independently of risk mode, a self-serve signup on the platform root no longer
provisions an environment up front. It creates the **account, its home organization,
its owner member and its first project**; the **environment** — the routable IdP whose
signing key is warmed on creation — is released by `App\Platform\SignupProvisioner`
only when the owner opens the emailed verification link.

This is the control that actually removes the incentive. A CAPTCHA makes bulk signup
harder; deferring the environment makes it **pointless**, because what an unverified
signup walks away with is a row nobody routes to. It is also idempotent: a replayed
link, or a second address verified on the same account, never mints a second
environment, and a suspended account is not un-suspended by clicking a link.

## The decision trail

Every call to `RiskGuard::assess()` writes one row to **`risk_decisions`**
(`App\Models\RiskDecision`, written by `App\Platform\RiskTrail`). That table is the
evidence base for everything in the next section, and it is the only reason
`RISK_MODE=enforce` can be a measured decision rather than a guess.

It replaces a `Log::info` line. Production runs `LOG_CHANNEL=stderr` with no
aggregation and no `log_streams` sink, so every risk decision went to pod stdout and
was destroyed by the next rollout — monitor mode ran for weeks and produced nothing
anyone could query. A control you cannot measure cannot responsibly be turned on.

### What a row holds

| Column | Why it is there |
| --- | --- |
| `action` | `login` or `register` — thresholds are tuned per action. |
| `score`, `outcome` | The verdict, as `decimal(8,2)` so aggregates don't drift. |
| `mode` | The mode the decision was made **under**. Without it a row is uninterpretable after a mode flip: a `reject` recorded under `monitor` was let through. |
| `reasons` | The human-readable explanation of a single decision. |
| `signals` | Per-signal **weighted points**. This is what lets you re-*weight* a signal instead of only moving the threshold. |
| `ip_hash`, `email_hash` | Keyed HMAC-SHA256 pseudonyms (see below). |
| `email_domain` | Mail domain **in the clear**. |
| `environment_id`, `assessed_at` | Scope and time. |

### Personal data

The IP was already HMAC-hashed under `app.key`, per the risk package's GDPR
guidance; the **email is treated the same way**. The row carries a keyed pseudonym,
never the address. This table takes a row per *pre-authentication* attempt, driven
by unauthenticated traffic — making it the platform's largest plaintext store of
personal data would be a poor trade for the convenience of `WHERE email = …`.

It costs nothing in practice: an operator investigating an address they already know
hashes it and looks it up. The pseudonyms are **keyed**, not bare digests, so the
table is not brute-forceable from itself — reversing one needs `app.key`, the same
secret that already protects sessions and encrypted columns. IP and email pseudonyms
are domain-separated (`ip:` / `email:` prefixes) so they cannot be joined to each
other by accident.

The mail **domain** is deliberately in the clear: it names a provider, not a person,
and disposable-provider and provider-abuse patterns are unreadable without it.

Canonicalisation before hashing is `trim` + `lowercase` and nothing else. Gmail's
dot and `+tag` equivalences are **not** folded in — most providers treat dots as
significant, and guessing wrong merges two different people into one pseudonym. To
chase a dot-abuse family, hash each variant, or query by `email_domain` and score,
which is what that pattern looks like in aggregate anyway.

### Retention

Bounded by `CBOX_ID_RISK_TRAIL_RETENTION_DAYS` (default **90 days**), swept daily by
Laravel's `model:prune`, scheduled in `routes/console.php`. Set the variable empty to
keep the trail indefinitely.

Ninety days rather than the 30 the framework's own growing tables get: tuning means
comparing a week against the weeks around it, and a month is too short to tell a
seasonal false-positive spike from a trend. The sweep is *not* the framework's
`cbox-id:prune` — that command's table list is an enum inside `cboxdk/laravel-id`
that an application cannot extend.

### Why not the audit log

`Cbox\Id\Kernel\Audit\Contracts\AuditLog` is durable, environment-scoped, retained
and already streamed to SIEM sinks, so it was the obvious candidate. It was measured
before being ruled out, against a real PostgreSQL 16 (the deployed engine):

| | plain insert (`risk_decisions`) | `AuditLog::record()` |
| --- | --- | --- |
| 1 writer, local SQLite (no network) | p50 **0.32 ms**, p95 0.58 ms | p50 **0.55 ms**, p95 0.85 ms |
| 1 writer, PostgreSQL | p50 **10.3 ms**, p95 15.6 ms | p50 **12.3 ms**, p95 18.4 ms |
| 2 concurrent writers × 100 | 200 ok, **0 failed** | 100 ok, **100 failed** |
| 8 concurrent writers × 100 | 800 ok, **0 failed** | 100 ok, **700 failed** |

The single-writer overhead alone (three extra round trips: `BEGIN`, `SELECT … FOR
UPDATE`, `COMMIT`) would have been tolerable. The concurrency result is not.

Each append takes `SELECT … ORDER BY sequence DESC LIMIT 1 FOR UPDATE` on its
`(environment, scope)` chain head. Under PostgreSQL's `READ COMMITTED`, a blocked
`FOR UPDATE` re-checks the *locked row*, not the `ORDER BY … LIMIT 1` that chose it —
so the waiter wakes holding the old head, computes a sequence that has just been
taken, and the write dies on
`audit_logs_environment_id_scope_sequence_unique` (SQLSTATE 23505).
`DB::transaction(…, attempts: 3)` does not save it: Laravel retries only *deadlock
and serialization* failures, and a unique violation is neither.

That is a bad enough outcome on the console's own low-rate audit writes. On the
sign-in path it would be a security problem, not just an availability one: these
writes happen **before** authentication, so any unauthenticated client — a
credential-stuffing burst, exactly the traffic the scorer exists to catch — could
drive contention on the tamper-evident chain and starve the audit writes of everyone
authenticated. And `audit_logs` is deliberately never pruned, so pre-auth telemetry
would grow forever in a structure that cannot shed it.

Genuine security *events* — an actual block, an actual step-up demand — still belong
on the audit chain when enforcement lands. Those are rare and post-decision. Scoring
every attempt is not.

Routing application logs to a durable channel was the third option and is the weakest
for this purpose: a log aggregator cannot answer "what score distribution did signups
get last week" without the structure this table gives for free.

### Cost on the sign-in path

One indexed insert on the connection the request already holds: **p50 0.32 ms**. For
comparison, the bcrypt verification the same login performs costs ~190 ms at the
configured 12 rounds — the trail is under **0.2%** of the password hash alone. The
write is also **fail-open**: a failed insert is logged at `warning` and the sign-in
proceeds. Observability may degrade; authentication may not.

### One write, one attempt

The write lives in `RiskGuard::assess()` and nowhere else. `shouldBlock()` and
`shouldStepUp()` are pure predicates over an assessment that has already been made,
and the password path calls **both** on the same assessment. Recording from either
would count one sign-in twice and silently double every number below.

## Setting a threshold

Run from `php artisan tinker` or a read-only SQL session.

Query 1 below is portable. **Queries 2–5 are PostgreSQL-only** — they use
`COUNT(*) FILTER (WHERE …)`, `generate_series`, a `VALUES` CTE and
`jsonb_each_text`, none of which MySQL accepts. MySQL 8 can express all of them
(via `SUM(CASE WHEN …)`, a recursive CTE and `JSON_TABLE`) but those rewrites are
not yet written or tested, so on MySQL treat 2–5 as sketches of the *question*,
not as SQL to paste. Date arithmetic differs throughout: PostgreSQL's
`now() - interval '7 days'` is `NOW() - INTERVAL 7 DAY` on MySQL.

**1. What does the current traffic actually look like?**

```sql
-- FLOOR, not a cast: MySQL has no INTEGER cast target (it spells it SIGNED), and
-- `score::int` is PostgreSQL-only syntax. FLOOR is the one spelling all three
-- supported engines accept.
SELECT FLOOR(score / 10) * 10 AS band, COUNT(*) AS decisions
FROM risk_decisions
WHERE action = 'register'
  AND assessed_at >= NOW() - INTERVAL 7 DAY   -- PostgreSQL: now() - interval '7 days'
GROUP BY band
ORDER BY band;
```

Swap `'register'` for `'login'` for the sign-in side. A healthy monitor-mode
histogram is heavily bottom-loaded with a thin, well-separated tail; a smooth ramp
with no gap means no threshold will separate cleanly and the **signals** need work
before the threshold does.

**2. Where do the current thresholds land?**

```sql
SELECT outcome, COUNT(*) AS decisions,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (), 1) AS pct
FROM risk_decisions
WHERE action = 'register'
  AND assessed_at >= now() - interval '7 days'
GROUP BY outcome
ORDER BY MIN(score);
```

Under `monitor` this is what enforcement *would* have done. `pct` for `reject` is the
share of signups you would have blocked outright.

**3. The actual threshold decision — score a candidate against known bots.**

Get the pseudonyms for the accounts you know were bots:

```php
// php artisan tinker
collect(['f.o.o@gmail.com', 'f.oo@gmail.com', /* … */])
    ->map(fn ($e) => app(App\Platform\RiskTrail::class)->emailPseudonym($e));
```

Then sweep candidate thresholds, with those hashes in the `bots` CTE:

```sql
WITH bots(email_hash) AS (VALUES ('<hash1>'), ('<hash2>'), ('<hash3>'))
SELECT t.threshold,
       COUNT(*) FILTER (WHERE d.score >= t.threshold AND b.email_hash IS NOT NULL) AS bots_caught,
       COUNT(*) FILTER (WHERE d.score >= t.threshold AND b.email_hash IS NULL)     AS others_caught
FROM generate_series(10, 100, 5) AS t(threshold)
CROSS JOIN risk_decisions d
LEFT JOIN bots b ON b.email_hash = d.email_hash
WHERE d.action = 'register'
  AND d.assessed_at >= now() - interval '30 days'
GROUP BY t.threshold
ORDER BY t.threshold;
```

This is the deliverable. `bots_caught` is what a threshold buys; `others_caught` is
what it costs. Take the **highest** threshold that still catches the whole known-bad
cohort, then read `others_caught` at that row and decide whether that many legitimate
signups can absorb a CAPTCHA (`risk.thresholds.challenge`) or must never see a hard
block (`risk.thresholds.reject`). Set `reject` above the point where `others_caught`
stops being zero, and let `challenge`/`step_up` cover the ambiguous middle.

**4. Which signal is doing the work?**

```sql
SELECT s.key, COUNT(*) AS fired, ROUND(AVG(s.value::numeric), 1) AS avg_points
FROM risk_decisions d, jsonb_each_text(d.signals::jsonb) AS s(key, value)
WHERE d.action = 'register'
  AND d.assessed_at >= now() - interval '7 days'
GROUP BY s.key
ORDER BY avg_points DESC;
```

If one signal dominates the score on traffic you believe is legitimate, lower its
`risk.weights` entry rather than raising the threshold — a threshold raise blunts
every signal to fix one.

**5. Provider-level abuse (the Gmail dot-abuse shape).**

```sql
SELECT email_domain, COUNT(*) AS signups, ROUND(AVG(score), 1) AS avg_score,
       COUNT(*) FILTER (WHERE outcome IN ('step_up', 'reject')) AS elevated
FROM risk_decisions
WHERE action = 'register'
  AND assessed_at >= now() - interval '30 days'
GROUP BY email_domain
HAVING COUNT(*) > 5
ORDER BY avg_score DESC;
```

A single provider with a signup count far above its usual share and a raised average
score is the aggregate signature of one actor cycling addresses at that provider.

## Enabling and tuning

1. **Stay in `monitor` until the queries above have data.** They need a few weeks;
   that is the whole point of the default.
2. Tune signals, weights, thresholds and allowlists in the risk package config
   (`RISK_MODE`, `risk.thresholds`, `risk.signals`, `risk.allow`) using the queries in
   [Setting a threshold](#setting-a-threshold). Start permissive and prefer friction
   (step-up) over a hard reject.
3. Set `RISK_MODE=enforce` only once step 2 has a threshold whose `others_caught` you
   can defend. Then re-run query 2 weekly — the trail keeps recording under
   `enforce`, with `mode` on each row, so a regression is visible.
4. Keep the reputation feeds fresh: `risk:refresh-ipsum` and `risk:refresh-tor`
   (schedule them).

## Honest scope

- **Step-up requires a deliverable factor.** The emailed code assumes the account's
  email is reachable; email OTP is a *possession-of-inbox* check, not a strong factor
  — TOTP / passkey remain stronger. Encourage authenticator enrolment.
- **SSO logins are gated by the upstream IdP.** A federated (SAML/OIDC) sign-in is
  vouched for by the customer's IdP, which owns that risk decision; the app's risk
  gate covers the local factors (password, magic-link, passkey), not delegated SSO.
- **Monitor first.** The default is deliberately non-blocking. Enforcement with
  untuned thresholds is the main way to lock out real users.
- **The signup CAPTCHA only bites under `enforce`.** In `monitor` mode a challenged
  signup is recorded and let through, exactly like every other outcome — the widget
  never appears. Deferred environment provisioning is the half that works regardless
  of mode.
- **The decision trail does not stream to SIEM.** `audit_logs` does; `risk_decisions`
  does not, and that is the price of not putting pre-auth scoring on the audit chain.
  Export it with a query if your SIEM needs it.
- **The trail is tamper-*evident* of nothing.** It is a plain table, not a hash chain.
  Anyone with write access to the database can alter it undetected. It is tuning
  evidence, not an attestable record — do not cite it as one.
- **A pseudonym is not anonymisation.** `email_hash` and `ip_hash` are keyed HMACs,
  which makes the trail personal data under GDPR: it is re-identifiable by anyone
  holding `app.key`. Retention (default 90 days) is the control that bounds it, and a
  subject-erasure request that names an address can be satisfied by deleting the rows
  matching its pseudonym.
