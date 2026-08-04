<?php

declare(strict_types=1);

return [

    /*
     * Master switch for the Compliance console surface. The area also lights up on
     * its own whenever a real export sink is wired (i.e. `export.sink` is not
     * `null`), so a deployment that ships the trail somewhere gets the console
     * automatically. Flip this on to show the console (audit search, retention, DSR)
     * without configuring an export destination.
     */
    'enabled' => (bool) env('CBOX_ID_COMPLIANCE_ENABLED', false),

    /*
     * Audit-trail export. The engine reads new entries incrementally from a persisted
     * cursor and ships them to the chosen sink; it is idempotent and resumable.
     *
     * `sink` selects the destination, kept behind the AuditExportSink contract so the
     * open framework never hard-depends on a SIEM or object store:
     *   - `null` (default) — inert; nothing is exported.
     *   - `jsonl`          — append newline-delimited JSON bundles to a Storage disk.
     *   - `http`           — POST batches to a SIEM ingest endpoint.
     */
    'export' => [
        'sink' => (string) env('CBOX_ID_COMPLIANCE_SINK', 'null'),

        'batch_size' => (int) env('CBOX_ID_COMPLIANCE_BATCH_SIZE', 500),

        'jsonl' => [
            'disk' => (string) env('CBOX_ID_COMPLIANCE_JSONL_DISK', 'local'),
            'path' => (string) env('CBOX_ID_COMPLIANCE_JSONL_PATH', 'compliance/audit'),
        ],

        'siem' => [
            'endpoint' => (string) env('CBOX_ID_COMPLIANCE_SIEM_ENDPOINT', ''),
            'token' => (string) env('CBOX_ID_COMPLIANCE_SIEM_TOKEN', ''),
            'timeout' => (int) env('CBOX_ID_COMPLIANCE_SIEM_TIMEOUT', 10),
        ],
    ],

    /*
     * Retention policy. HONEST semantics: the audit trail is append-only and
     * hash-chained, so retention NEVER deletes entries — deleting a row would break
     * `verifyChain` and the checkpoint anchor. Applying retention signs a fresh
     * checkpoint per chain (so retained history stays externally verifiable) and
     * relies on the export sink to archive entries to cold storage.
     */
    'retention' => [
        'checkpoint_on_apply' => (bool) env('CBOX_ID_COMPLIANCE_RETENTION_CHECKPOINT', true),
    ],

    /*
     * Scheduled work — the half that makes the rest of this file mean anything. Both
     * are on by default and register only when the module is active (a real sink is
     * wired, or `enabled` is set), so an install that never configured compliance
     * schedules nothing.
     *
     * Turning one off removes that command from the schedule with no other signal, so
     * do it only when something else drives it. `export` off with a SIEM configured is
     * the exact state this module spent its whole life in: active, backlogged, and
     * shipping nothing.
     */
    'schedule' => [
        'export' => (bool) env('CBOX_ID_COMPLIANCE_SCHEDULE_EXPORT', true),
        'retention' => (bool) env('CBOX_ID_COMPLIANCE_SCHEDULE_RETENTION', true),
    ],
];
