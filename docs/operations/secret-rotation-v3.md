---
title: Rotating app client secrets to cbid_cs_
weight: 7
description: The v3 runbook for moving every Cbox app's client secret from csec_ to cbid_cs_ without a failed sign-in. Mint a second secret, wait until the app uses it, then revoke the old one.
---

# Rotating app client secrets to `cbid_cs_`

Cbox ID v3 issues client secrets as `cbid_cs_…`. Secrets issued before v3 start with
`csec_…`. No migration revokes them: a `csec_` secret keeps working until somebody rotates
it, and v3.1 refuses the ones that are still left.

Every Cbox app has one `csec_` secret in its `CBOX_ID_CLIENT_SECRET`. If that secret is
revoked before the app has the new one, the app's code exchange and its machine tokens
stop working at the same moment. Each app therefore holds **two** live secrets for a
while: the old one until the app is running with the new one, and the new one from then on.

Do not run this until the laravel-id release that mints `cbid_cs_` is deployed. If a
secret you mint starts with `csec_`, stop: the new laravel-id is not live yet.

## Where to do it

The Cbox apps' OAuth clients live in the **`cbox` environment** (the one `cbox.cboxid.com`
serves). Use either:

- **The console**: the `cbox` environment › **Apps** › the app › **Secrets**. The page lists
  every live secret with its last four characters, when it was created, when it was last
  used and when it stops working.
- **The API**, with an environment key for the `cbox` environment (scope `apps:write`):

  | Step | Call |
  |---|---|
  | List secrets | `GET /api/v1/apps/{id}/secrets` |
  | Mint a new one | `POST /api/v1/apps/{id}/secrets` with `{"grace_seconds": 604800}` |
  | Revoke the old one | `DELETE /api/v1/apps/{id}/secrets/{secret_id}` |

  `{id}` is the app's id or its `client_id`. After step 5a the same calls are also served
  as `/v1/apps/…` on the API host.

## For each app

1. **Mint a second secret.** Choose **Rotate secret** and keep the old one working **for
   another 7 days** (`grace_seconds: 604800`). Never choose "Immediately" here: that
   is for a leaked secret, and it breaks the app at once. Copy the new `cbid_cs_…` value.
   It is shown once.
2. **Give it to the app.** Hand the new value to the infra process for that app's Secret
   (`CBOX_ID_CLIENT_SECRET`). The cbox-hosting skill says how. The app picks it up on its
   next rollout. Do not edit `cbox-infra` yourself.
3. **Confirm the app uses it.** After the rollout, sign in to the app once (or wait for
   its next machine token). On the Secrets page, or in `GET …/secrets`:
   - the new secret's `last_used_at` is **after** the rollout;
   - the old secret's `last_used_at` no longer moves. Check again after an hour.

   If the new secret is never used, the app is still running with the old value. Fix the
   rollout before you go on. The old secret keeps working until its 7 days are up.
4. **Revoke the `csec_` secret.** On the Secrets page choose **Revoke** on the old row
   (`DELETE …/secrets/{secret_id}`). The app's only remaining secret is now the
   `cbid_cs_` one.
5. **Tick the row** below, with the date and the last four characters of the new secret.

A public app (PKCE, no secret) has no Secrets page. Mark its row "n/a".

## Checklist

One row per `client_id` in `infra/sites/*.json`, plus cbox-cli and the Authenticator. Step 6
of the v3 plan is done when every row is ticked.

| App | 1 Minted `cbid_cs_` | 2 In the app's Secret | 3 New `last_used_at` seen | 4 `csec_` revoked | Date / hint |
|---|---|---|---|---|---|
| cbox-work | ☐ | ☐ | ☐ | ☐ | |
| cbox-engage | ☐ | ☐ | ☐ | ☐ | |
| cbox-brain | ☐ | ☐ | ☐ | ☐ | |
| cbox-infra | ☐ | ☐ | ☐ | ☐ | |
| cbox-telemetry | ☐ | ☐ | ☐ | ☐ | |
| cbox-tax | ☐ | ☐ | ☐ | ☐ | |
| cbox-billing | ☐ | ☐ | ☐ | ☐ | |
| cbox-assistant | ☐ | ☐ | ☐ | ☐ | |
| cbox-sites | ☐ | ☐ | ☐ | ☐ | |
| cbox-stache | ☐ | ☐ | ☐ | ☐ | |
| cbox-status | ☐ | ☐ | ☐ | ☐ | |
| cbox-signals | ☐ | ☐ | ☐ | ☐ | |
| cbox-domains | ☐ | ☐ | ☐ | ☐ | |
| cbox-cli | ☐ | ☐ | ☐ | ☐ | |
| Cbox ID Authenticator | ☐ | ☐ | ☐ | ☐ | |

## If something goes wrong

- **The app fails its code exchange after step 4.** It was still using the `csec_`
  secret. Mint another secret with **Rotate secret**, put it in the app's Secret and roll
  the app out. A revoked secret cannot be brought back.
- **The old secret ran out before the app moved.** Same fix. Next time choose a longer
  grace. The API allows up to `cbox-id.oauth.client_secrets.max_rotation_grace` seconds
  (30 days by default).
- **Somebody else's integration uses the same client.** `last_used_at` on the old secret
  keeps moving after the app's rollout. Find the caller before you revoke. The audit log
  shows the client's token requests.
