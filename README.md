# Cbox ID Compliance (commercial)

**`cboxdk/laravel-id-compliance`** — compliance tooling for Cbox ID, as a drop-in
plugin. It ships the platform's append-only, hash-chained **audit trail** to your
SIEM or cold archive, verifies the chain, and lights up a **Compliance** console for
audit search, retention, and data-subject exports — data **and** UI in one package,
with **zero edits to the host**.

It plugs over laravel-id's existing read seams (the `AuditReader` pull cursor and the
`AuditLog` chain/checkpoint API); nothing in the open, self-hostable app depends on
it. Install it and the console appears and exports start flowing; leave it out and the
audit trail is still recorded — you just don't get the export pipeline or the console.

## What it adds

- **Audit export engine** — reads new audit entries incrementally from a persisted,
  per-chain cursor (via the existing `AuditReader::since()` seam) and ships them to a
  pluggable **`AuditExportSink`**. Export is **idempotent and resumable**: the cursor
  advances only after a batch is accepted, so re-running never re-ships an entry and a
  sink outage degrades to export lag, never to dropped entries.
- **Pluggable sinks** — a `jsonl` bundle sink (newline-delimited JSON per chain on any
  Storage disk) and an `http` SIEM sink (POSTs batches to an ingest endpoint). Both
  sit behind the `AuditExportSink` contract, so the framework never hard-depends on a
  SIEM or object store. The default **`NullAuditExportSink`** is inert — installing
  without a destination is safe and ships nothing.
- **Honest retention** — the trail is append-only and hash-chained, so retention here
  **never deletes entries** (a deleted row breaks `verifyChain` and the checkpoint
  anchor). Applying retention signs a fresh **checkpoint** per chain and relies on the
  export sink to archive to cold storage. The console and docs say so plainly:
  entries are archived, not erased.
- **Compliance console** — a gated **Audit trail** page (scoped search + live
  hash-chain verification status) and an **Exports** page (export runs, retention
  actions, and a data-subject lookup), plus a dashboard card (pending entries + last
  run).
- **Data-subject export (GDPR access)** — aggregates a subject's audit trail (the
  actions they performed, across every chain) into a portable JSON bundle via the
  authorized `AuditReader` query seam.

## Scope & honesty

- **Tamper-evident, not tamper-proof.** The export preserves each entry's
  `sequence`/`prev_hash`/`hash`, so a JSONL bundle or SIEM can re-verify the chain
  independently — but the guarantee is the audit kernel's: anchor the signed
  checkpoints externally for anti-tamper.
- **Erasure is intentionally out of scope in v0.1.** GDPR erasure over a hash-chained
  trail would mean redacting fields in place, which recomputes (breaks) the chain and
  defeats its tamper-evidence. Compliant redaction needs a redaction-aware
  canonical-hash seam in laravel-id (a framework change), so it is scoped out here
  rather than faked. DSR **access/portability** is supported today.
- **Subject lookup is by `actor_id`** (actions the subject performed). Records where
  the subject is only the target need a target filter the reader does not yet expose —
  a framework-seam follow-up.

## Configuration

Everything is driven by `config/compliance.php` (env-overridable). Select a sink with
`ID_COMPLIANCE_SINK` (`null` | `jsonl` | `http`); the console also lights up on its own
whenever a real sink is wired. Schedule `id-compliance:export` from the host's console
kernel to keep the trail flowing.

## License

Proprietary and commercial. See [LICENSE](LICENSE). Use requires a written agreement
with Cbox.
