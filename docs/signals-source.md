# Identity source for Signals

This source is separate from authentication risk enforcement. `SIGNALS_DATA_ENVIRONMENTS`
explicitly opts environments into capture; `SIGNALS_AUTH_ENABLED` retains its existing
meaning and deadline. No remote request is made on the sign-in path by the source.

`GET /api/v1/signals/source` requires an environment-owner key carrying
`signals_source:read`. Organization event grants do not authorize this endpoint. The
host selects the environment; no query parameter can widen it. Every page authenticates
again, including after revocation.

The source wraps actual subject, session and refresh-token mutations. The owner transaction writes a durable, unassigned outbox fact. A separate bounded
publication transaction locks the ledger head and assigns positions only to committed
facts. It scans unassigned rows rather than advancing over their creation IDs; a delayed
commit cannot fall behind its cursor. Rollback removes both the mutation and its fact. The cursor is authenticated and bound to environment
and generation. Pages are bounded to 1000 records and less than 1 MiB, with a 60 KiB
payload limit per record. Equal occurrence timestamps do not affect pagination.

Refresh rotation observes the issuer's successful mutation. Within-grace replay returns
the existing successor and emits no second issue/rotation. A reuse fact requires both
the issuer's explicit reuse refusal and its committed family revocation; an invalid
request alone is not reuse. Token values, digests and encrypted successors are not exported.

Device IDs reuse the first-party, environment-keyed device cookie. Only coarse browser
and OS fields are retained from the user agent. Cached Radar enrichment is reused; no
additional network lookup runs on login. Locations require a reported accuracy radius,
are rounded, and expand that radius to cover rounding. Unknown stays unknown.

The producer retains immutable replay records for at most seven days, bounded by raw-IP
retention. It never changes a payload under an existing retry ID. A consumer may retain
accepted non-IP fields longer under its own grant. `signals:source-maintain` prunes in
bounded batches and publishes an independent heartbeat every minute. Reads also enforce
retention, so stalled maintenance cannot export expired raw data. Erasure removes source
rows and installs hashes that block replay; reads filter retained tombstones after restore.

**Qualification is in progress.** The source reports `coverage: partial`. Current tests
cover the public password login/logout and export boundary, session revocation, refresh
issue/rotation/reuse, scope denial, cursor integrity, rollback, erasure and retention.
This is not a declaration that every identity flow, historical relationship, forwarding
connector, deletion propagation or production gate is qualified. No learned detector is
introduced by this change.

## Authentication completion and paused capture

A framework session may be temporary during magic-link or SSO verification.
`identity.session.started` records that lifecycle fact; only PlatformAuth accepting
the final session publishes `identity.authentication` with `outcome=succeeded`.
A refused second-factor submission has its own failed attempt and leaves the
original sign-in pending, so a later correct proof completes the original attempt.
Hosted magic-link and passkey failures use the frozen coarse failure classes.
No factor code, credential handle, link bearer or raw verifier error is exported.

Subject-scoped PostgreSQL transaction locks serialize source writes and erasure.
Unrelated identities keep writing concurrently. Membership state also checks the
permanent tombstone before it can be recreated. The independent erasure endpoint
and bounded raw retention remain available after capture is paused; pausing never
publishes a fresh healthy heartbeat. The maintenance command includes existing
source heads as well as enabled environments.

Qualification includes a real hosted magic-link → refused TOTP → accepted TOTP
flow, paused erasure/retention, and independent PostgreSQL connections proving
that deletion cannot overtake an uncommitted source write. Live qualification
and remaining identity flow/relationship coverage are still open.

Retention removes expired positions even when a delayed transaction published
an old fact behind newer retained positions. The replay floor advances only to
the first remaining position, so a hole never hides a valid earlier row.
Maintenance also visits paused environments with only unpublished outbox data.
It expires raw facts and their subject index in bounded passes, preserves the
independent erasure journal, and never marks paused capture as healthy.

## Existing memberships

After every old application/worker instance has drained, enable live capture for
an owner environment, then run `php artisan signals:source-memberships ENV --limit=500`.
Continue with the returned `next` value in `--after` while `has_more` is true.
Each invocation reads at most 1000 active memberships, with one short owner-row
transaction per membership; rerunning a page emits no duplicate assertion.

The snapshot records membership from the observation time, never `created_at` or
an inferred earlier date. It does not grant a current team access to older owner
activity. A subsequent leave/removal retracts that same observed interval. Owner
row locks serialize observation with deletion; source privacy locks and retained
tombstones prevent an erased subject from returning. Inactive members and other
environments are excluded. A bounded scan's end is not proof of historical
completeness, and overall source coverage remains partial until its doors qualify.

## Machine-readable capture limits

Every record page now includes `meta.capture` version `source-capture/1` and
`meta.history.retained_after` / `retention_days`. The capture object reports the
actual journal strategy and explicit reasons that event-time coverage is still
unqualified. `event_time_watermark`, `lateness_bound_seconds` and
`clock_skew_bound_seconds` remain null. A drained commit cursor, fresh heartbeat
or journal creation timestamp cannot replace these guarantees. The family list
declares supported record types, not proof that every public path emitted them.

This additive metadata does not change record identity, replay, retention,
authority or source activation. Older consumers may ignore it. Consumers must
retain the observation time and represent an empty caught-up page as unknown
coverage, never a measured zero or a qualified normal-behavior baseline.
