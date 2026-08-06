<?php

declare(strict_types=1);

return [

    /*
     * Master switch for the console surface and the CIBA push decorator. Off by
     * default: a deployment with no mobile app has no devices to notify, and the
     * decorator should not sit in the CIBA path doing nothing. Turning this on
     * without configuring a transport below is still safe — pushes are recorded
     * and dropped by the NullPushTransport, which is what the test suite runs.
     */
    'enabled' => (bool) env('CBOX_ID_DEVICES_ENABLED', false),

    /*
     * How a push actually leaves the building.
     *
     *   none  (default) — NullPushTransport. Notification rows are written and
     *                     immediately settled as Skipped. Nothing is sent, nothing
     *                     fails, and the console still shows the delivery history.
     *   fcm             — Firebase Cloud Messaging HTTP v1. Covers Android natively
     *                     and iOS by relaying to APNs, which is why there is no
     *                     separate APNs driver: one integration, both platforms.
     *
     * `fcm` requires `fcm.credentials` and `fcm.project_id` below. If either is
     * missing the transport stays Null rather than failing at send time — a
     * misconfigured push must not be able to break a login.
     */
    'transport' => (string) env('CBOX_ID_DEVICES_TRANSPORT', 'none'),

    /*
     * The Google service-account JSON used to mint FCM access tokens, as an absolute
     * path. This key can push to EVERY device you have ever enrolled — it belongs on
     * the server and must never be bundled into the mobile app.
     *
     * The minted access token is cached for its lifetime minus a safety margin. Under
     * CACHE_STORE=array every worker re-exchanges the JWT on every single send, which
     * Google's token endpoint will rate-limit long before FCM itself complains — the
     * provider logs a warning at boot when that combination is detected.
     */
    'fcm' => [
        'credentials' => (string) env('CBOX_ID_DEVICES_FCM_CREDENTIALS', ''),
        'project_id' => (string) env('CBOX_ID_DEVICES_FCM_PROJECT_ID', ''),
        'timeout' => (int) env('CBOX_ID_DEVICES_FCM_TIMEOUT', 10),
    ],

    /*
     * Delivery retry policy, mirroring the webhook dispatcher's: exponential backoff
     * of min(60, 2^attempt) minutes, capped at `max_attempts` before the notification
     * dead-letters as Exhausted.
     *
     * Note this only governs TRANSIENT failures. A permanent FCM error (UNREGISTERED,
     * INVALID_ARGUMENT) retires the device token on the first attempt instead — a
     * token that FCM has told us is dead will not become alive again in eleven hours
     * of retries.
     */
    'max_attempts' => (int) env('CBOX_ID_DEVICES_MAX_ATTEMPTS', 12),

    /*
     * A notification still Pending this long after it was written is presumed to have
     * lost its queue job, and the retry sweep re-enqueues it. This is what makes
     * "durable row before enqueue" actually true rather than aspirational.
     *
     * DeliverPushNotification::$uniqueFor is deliberately the SAME value: while the
     * unique lock holds, the sweep's re-enqueue is correctly suppressed as a
     * duplicate; the instant a row counts as stranded the lock has expired, so the
     * rescue can fire. Raising one without the other wedges the rescue shut.
     */
    'stranded_after_seconds' => (int) env('CBOX_ID_DEVICES_STRANDED_AFTER_SECONDS', 900),

    /*
     * How many due notifications one sweep re-enqueues. Bounds the work a single
     * scheduler tick can create.
     */
    'retry_limit' => (int) env('CBOX_ID_DEVICES_RETRY_LIMIT', 50),

    /*
     * Per-device circuit breaker. A device that has failed `failure_threshold` times
     * in a row stops being attempted for `cooldown_seconds`, then gets a single
     * half-open probe. State lives on the device row, not in cache, so it survives a
     * cache flush and is visible in the console.
     *
     * While the breaker is open a notification is parked WITHOUT incrementing its
     * attempt count: the trip is the device's fault, not this notification's, and
     * charging it would burn a dead phone's backlog through all 12 attempts during a
     * single cooldown.
     */
    'circuit_breaker' => [
        'failure_threshold' => (int) env('CBOX_ID_DEVICES_CB_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('CBOX_ID_DEVICES_CB_COOLDOWN_SECONDS', 300),
    ],

    /*
     * Where delivery jobs run. Approval pushes are on the CIBA critical path against a
     * 300-second TTL, so give them a queue that is not sharing a worker pool with slow
     * bulk work. Leave null to use the default connection/queue.
     *
     * On QUEUE_CONNECTION=sync (the test suite, and some self-hosted installs) the FCM
     * call runs inline inside the CIBA request, bounded by the transport's own
     * connect/read timeouts. The webhook dispatcher accepts the same trade.
     */
    'queue_connection' => env('CBOX_ID_DEVICES_QUEUE_CONNECTION'),
    'queue' => env('CBOX_ID_DEVICES_QUEUE'),

    /*
     * How long a security alert stays worth delivering.
     *
     * Every notification needs a deadline, and not only because a stale alert is
     * pointless. A notification parked by an open circuit breaker does NOT get charged
     * an attempt (see `circuit_breaker` above), so without a deadline it can never reach
     * `max_attempts` and never terminalises. One permanently soft-failing handset would
     * then accumulate an unbounded backlog of Failed rows which — being the oldest in the
     * table, and the sweep being ordered oldest-first — would occupy every slot of
     * `retry_limit` forever, starving every other tenant's approval retries.
     *
     * Approvals get their deadline from the CIBA request's own TTL instead.
     */
    'alert_ttl_seconds' => (int) env('CBOX_ID_DEVICES_ALERT_TTL_SECONDS', 86400),

    /*
     * How long settled notification rows are kept for the console's delivery history
     * before the module's own scheduled prune removes them. Only terminal rows are
     * pruned; anything still Pending or Failed is left alone regardless of age.
     *
     * This table grows with TRAFFIC, not with tenants — one row per enrolled device per
     * alerted event — so the prune is not optional housekeeping.
     */
    'retention_days' => (int) env('CBOX_ID_DEVICES_RETENTION_DAYS', 30),

    /*
     * Per-minute request budget for the device API, keyed on a fingerprint of the
     * presented access token. Each user's token is distinct, so this is a per-user
     * bucket; keying on client_id would put every mobile user in one bucket and let
     * one busy account throttle the whole fleet. The host's existing
     * `api.ip_ceiling_multiplier` still applies as the per-IP abuse backstop.
     */
    'rate_limit' => (int) env('CBOX_ID_DEVICES_RATE_LIMIT', 60),

    /*
     * Which OAuth clients may cause a push to a user's phone via CIBA.
     *
     * Empty (the default) means every client holding the CIBA grant may. That is the
     * permissive setting and it is deliberate — the grant itself is already an
     * operator decision. Populate this with client ids to narrow it further.
     *
     * This is enforced as a hard refusal to notify, not a log line: CIBA's whole value
     * is the human in the loop, and an attacker who can spray approval prompts at a
     * phone is attacking exactly that. The CIBA request itself still succeeds — the
     * client can poll — it simply produces no push.
     */
    'ciba' => [
        'client_allowlist' => array_values(array_filter(
            explode(',', (string) env('CBOX_ID_DEVICES_CIBA_CLIENT_ALLOWLIST', '')),
        )),
    ],

    /*
     * Whether the approval request id travels in the FCM data payload.
     *
     * True (default) lets a notification tap deep-link straight to the right approval.
     * The id is an unguessable ULID and confers no capability on its own — approving
     * still needs a DPoP-bound token whose subject matches — but it does transit
     * Google's and Apple's infrastructure, which is a real if minor metadata
     * exposure. Set false and the tap opens the pending list instead, which costs
     * deep-link precision when two approvals are waiting.
     */
    'include_request_id_in_push' => (bool) env('CBOX_ID_DEVICES_INCLUDE_REQUEST_ID_IN_PUSH', true),

    /*
     * Domain event types that raise a security alert on the user's enrolled devices.
     *
     * These ride the ordinary once-a-minute event relay rather than the synchronous
     * path approvals use: "your password changed" is worth knowing about, but not
     * worth putting on the login critical path.
     *
     * Only types actually emitted to the `events` outbox can appear here. Audit-only
     * actions are NOT reachable this way — see the module docblock.
     */
    'alerts' => [
        'user.login',
        'user.session_started',
    ],
];
