---
title: Log streams
weight: 40
description: Send your organization's audit log to your own SIEM, to Datadog, or to an Amazon S3 or Google Cloud Storage bucket through the setup portal — the destinations supported, setting up each, the signing key, and testing a destination.
---

# Log streams

**Page:** Log streams

A log stream sends your organization's audit log (sign-ins, membership changes, settings,
and the audit events the product records about your organization) to your own SIEM, to
Datadog, or into your own storage bucket as it happens. You get your organization's
entries only, never anyone else's.

## Add a destination

Under **Add a destination**:

1. **Name** it, for example "Splunk — production".
2. Choose the **Destination**:

   | Destination | Use for |
   |---|---|
   | Splunk (HTTP Event Collector) | Splunk. An endpoint with no path gets `/services/collector/event` added. |
   | Elastic (ECS) | Elastic, in Elastic Common Schema |
   | Graylog (GELF over HTTP) | Graylog's GELF HTTP input |
   | CEF over HTTP | SIEMs that read ArcSight CEF |
   | JSON over HTTP | Anything else that accepts an HTTPS POST of JSON |
   | Datadog | Datadog Logs ([below](#datadog)) |
   | Amazon S3 | An S3 bucket, or MinIO or Cloudflare R2 ([below](#amazon-s3)) |
   | Google Cloud Storage | A Cloud Storage bucket ([below](#google-cloud-storage)) |

3. For the first five, enter the **Endpoint URL**: a public `https` address your SIEM
   accepts events at. Addresses on private networks are refused. Then choose the
   **Authentication**:

   | Authentication | Sent with every delivery |
   |---|---|
   | None | Nothing |
   | Bearer token | `Authorization: Bearer <token>` |
   | Splunk HEC token | `Authorization: Splunk <token>` |
   | HMAC signature | `X-Cbox-Timestamp` and `X-Cbox-Signature: t=<timestamp>,v1=<signature>` |

   For a bearer or Splunk token, paste it into **Token**. With **HMAC signature**, leave
   **Token** blank: a signing key is generated for you.
4. Choose **Add destination**.

With HMAC, the page then shows the **Signing key**. **Copy it now**; it is shown only once.
To check a delivery, compute HMAC-SHA256 over the timestamp, a `.`, and the raw request
body, keyed with the signing key, and compare the hex result with `v1` in
`X-Cbox-Signature`. Reject deliveries whose timestamp is more than a few minutes old.

A token, API key or service-account key you paste is stored encrypted and never shown
again, not even to you.

### Datadog

1. In Datadog, go to **Organization Settings › API Keys** and create a key. Use an
   *API key*, not an application key.
2. Choose the **Datadog site** your account is on: the domain of the Datadog URL you sign
   in at, for example `datadoghq.eu` for EU1. A key only works on its own site.
3. Paste the key as the **API key**.
4. Optionally set **Service**, **Source**, **Tags** (`key:value`, separated by commas,
   for example `env:prod`) and **Hostname**. They are added to every log.

### Amazon S3

Each batch of entries is written as one file in your bucket, at
`prefix/year/month/day/hour/batch.ndjson.gz`, with one event per line. Files are only ever
added, so the access you grant can be write-only.

1. Create the bucket, with your own retention rules.
2. Give the **Bucket**, its **AWS Region** (for example `eu-west-1`), and optionally a
   **Prefix**.
3. Choose **How we sign in to AWS**:
   - **Access key**: create an IAM user with only the permissions policy below, create an
     access key for it, and give its **Access key ID** and **Secret access key**.
   - **Assume an IAM role**: no secret is stored. Give the **IAM role ARN**. After you
     choose **Add destination**, the destination's **AWS setup** opens with an
     **External ID** and a ready **Trust policy**: set it as the role's trust policy in
     IAM (Roles › your role › Trust relationships). Nothing is written to the bucket until
     you have. This option is shown as unavailable when the product does not offer it.
4. Optionally choose **Server-side encryption**: SSE-S3 (`AES256`), or SSE-KMS
   (`aws:kms`) with an **AWS KMS key**.
5. For MinIO or Cloudflare R2, give the store's `https://` address as the **Custom
   endpoint** (R2's region is `auto`).

**AWS setup** on the destination also shows the **Permissions policy** to attach to the
role or user. It allows writing new files under the prefix and nothing else:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::acme-audit-logs/cbox/audit/*"
    }
  ]
}
```

With SSE-KMS on your own key, the policy also allows `kms:GenerateDataKey` on that key.

### Google Cloud Storage

1. Create the bucket and a **service account** for this product.
2. Give the service account the **Storage Object Creator** role
   (`roles/storage.objectCreator`) on the bucket only:

   ```bash
   gcloud storage buckets add-iam-policy-binding gs://acme-audit-logs \
     --member="serviceAccount:audit-writer@acme-project.iam.gserviceaccount.com" \
     --role="roles/storage.objectCreator"
   ```

3. Create a **JSON key** for the service account and paste the whole file as the
   **Service account key (JSON)**.
4. Give the **Bucket**, and optionally a **Prefix**.

Files are written the same way as for Amazon S3.

## Test it

Choose **Send test entry**. One marked event (`siem.stream.test`, safe to ignore) is sent
straight away, in the same format and with the same credentials as real ones. The page
says either "Your endpoint accepted the test entry" or what your destination answered
instead, for example `destination responded with HTTP 403`.

## What to expect

- **Each entry is delivered at least once.** After a timeout an entry can arrive twice.
  Every entry has a unique `id`; deduplicate on it.
- **Entries are batched** and sent within a minute or so of being written. Earlier entries
  are not backfilled.
- **Your destinations** shows each destination's status and its last delivery:

  | Status | Meaning |
  |---|---|
  | On | Delivering. |
  | Retrying | Recent deliveries failed in a way that may pass, such as a timeout. They are retried for a few hours. |
  | Paused after failures | Several deliveries failed in a row; it tries again after a few minutes. |
  | Action needed | Your destination refused the credentials or the settings, for example a revoked key or a missing bucket. Entries wait until it is fixed. The **Last error** says why. |
  | Paused | Switched off by the product's administrators. |

  To fix **Action needed**, correct the key, policy or bucket on your side and choose
  **Send test entry**: a successful test resumes delivery. To change a destination's
  settings, remove it and add it again, or ask the person who sent you the link.

## Remove a destination

**Remove** stops delivery to that destination at once. You are asked to type its name to
confirm. You cannot pause a destination from the portal; remove it and add it again, or
ask the person who sent you the link.

## Related

- [Audit logs](audit-logs.md) — read and export the product's audit events in the portal instead.
- [For IT admins](_index.md) — how the setup link works.
