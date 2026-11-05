---
title: User preferences and app metadata
description: Configure private app groups and synchronize versioned user properties through webhooks.
weight: 35
---

# User preferences and app metadata

Each namespace holds an independent, versioned JSON document on a user within one environment. Access is explicit per OAuth app. `preferences` has a Cbox-defined schema; other namespaces carry application-defined JSON contracts.

## Configure app groups

Use an environment management key with `user_property_namespaces:write` (and `user_property_namespaces:read` for GET). This administrative scope grants access to user data and must not be given to ordinary consuming apps. The action uses the existing approval, audit, idempotency and MCP machinery.

```http
PUT /api/v1/user-property-namespaces/preferences
Authorization: Bearer ENVIRONMENT_MANAGEMENT_KEY
Content-Type: application/json

{
  "readers": ["calendar-client", "tasks-client"],
  "writers": ["calendar-client", "tasks-client"],
  "webhooks": {"CALENDAR_ENDPOINT_ID": "calendar-client", "TASKS_ENDPOINT_ID": "tasks-client"}
}
```

`GET` on the same URL reads the grants. `PUT` replaces all grants; omitted or empty lists revoke access without deleting documents or resetting versions. Writers must be readers. Apps and endpoints must be environment-wide and belong to this environment. Use another namespace, such as `planning`, with fewer reader/writer apps for a private group. Names use 1–64 lowercase letters, digits, underscores or hyphens and start with a letter.

Webhook endpoints must also subscribe to `user.preferences.updated` or `user.metadata.updated`. Wildcard subscriptions do not bypass namespace access. Grants are checked again before queued delivery.

## Read and write

Give the OAuth app `user_properties:read` and/or `user_properties:write`, then acquire a token for the issuer with those scopes. User tokens address only their own user; explicitly granted environment-wide apps may use client credentials for synchronization. DPoP-bound tokens require their proof.

```http
GET /oauth/users/USER_ID/properties/preferences
Authorization: Bearer APP_ACCESS_TOKEN
```

```http
PATCH /oauth/users/USER_ID/properties/preferences
Authorization: Bearer APP_ACCESS_TOKEN
Content-Type: application/json

{"version":0,"set":{"theme":"dark","language":"en","locale":"da-DK","timezone":"Europe/Copenhagen"},"remove":[]}
```

Responses contain `user_id`, `namespace`, `data` and `version`. An unwritten document has version 0 and `{}`. Stale patches return 409: fetch, reconcile and retry. `set` replaces only named top-level values; `remove` deletes keys explicitly. Null is a custom value, not a deletion. Keys start with a letter and contain at most 128 ASCII letters, digits, dots, underscores or hyphens. Documents allow 100 keys, 16 KiB of JSON and 16 levels of nesting. Apps sharing custom fields own their meaning and types.

The reserved `preferences` namespace accepts:

| Key | Values |
| --- | --- |
| `language` | Interface language tag, e.g. `en` |
| `locale` | Regional format tag, e.g. `da-DK` |
| `timezone` | IANA timezone, e.g. `Europe/Copenhagen` |
| `date_format` | `locale`, `yyyy-MM-dd`, `dd/MM/yyyy`, `MM/dd/yyyy`, `dd.MM.yyyy` |
| `time_format` | `locale`, `12h`, `24h` |
| `week_start` | Integer 1–7, Monday–Sunday |
| `theme` | `system`, `light`, `dark` |

Unknown keys and null values are rejected for preferences. Remove a key to restore the app's fallback. Date-format values are shared identifiers that each app maps to its formatter. Missing theme follows the system; missing date/time formats follow the chosen locale.

## Synchronize

Signed change webhooks contain `event_id`, `user_id`, `namespace`, `changed_keys`, `version`, originating `client_id` and `occurred_at`. They contain no values. Verify signatures, deduplicate event ids, ignore older versions, then fetch and cache the latest authorized document. Refresh on login or periodically too: restoring grants does not replay missed events. Each app updates open browsers through its own realtime transport.

Changes enter the transactional outbox and wake the relay on the webhook queue after commit. Keep asynchronous queue workers and the scheduler running; the scheduled relay recovers lost wake-ups. Delivery latency depends on backlog and workers. User erasure removes property documents and blocks pending property notifications.

## Deployment

This change spans Cbox ID and `laravel-id`. The app requires `laravel-id ^1.27.1`. Run its additive migration before rolling out the new application image. No production grants are provisioned automatically.

Before a package release, local integration tests can use a Composer path repository or temporary autoload overlay for the sibling package. Do not deploy a local path dependency. Consuming apps still need to apply the values to their UI.

## Console language

The signed-in console language picker changes `preferences.language` for the
current person. It preserves every other preference, increments the document
version only when the language changes, and emits `user.preferences.updated`
through the same transactional outbox and webhook relay as API writes. The
originating `client_id` is null because the person acts directly in Cbox ID.
Existing namespace reader, writer and webhook grants are preserved.

Console pages use that shared preference before any browser fallback. Regional
tags normalize to a supported language; an unsupported saved language renders
English without rewriting the document. With no saved choice, the browser's
supported language is used, then English. Cookies from a previous account are not
used as signed-in preferences, and reading a page never seeds a default.

An environment administrator's preference belongs to their own identity in the
platform root, not the environment they administer. Both console save endpoints
run their normal session guard and require the displayed user's ID, so an old tab
cannot change a different account after switching users. Support impersonation
remains read-only. Anonymous and OAuth-hosted pages retain their separate locale
policy and hosted cookie.
