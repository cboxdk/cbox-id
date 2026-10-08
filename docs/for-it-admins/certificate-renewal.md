---
title: SAML certificate renewal
weight: 50
description: Replace your identity provider's SAML signing certificate before it expires, through the setup portal, without interrupting sign-in.
---

# SAML certificate renewal

**Page:** SAML certificate renewal

Your identity provider signs every SAML sign-in with a certificate, and certificates
expire. When yours does, sign-in through it stops. This task lets you renew it ahead of
time, with no interruption for your people. The product's administrators get a warning 30
days and again 7 days before a certificate stops working, which is often why you were sent
this link.

## How renewal works

1. Upload the new certificate, or your identity provider's metadata. It is checked, then
   trusted **alongside** the current one.
2. In your identity provider, switch to signing with the new certificate. Both work in the
   meantime.
3. Activate the new certificate here. The old one stops being trusted.

## What the page shows

One card per SAML connection, with each certificate's **Subject**, **SHA-256
fingerprint** and **Valid until**, and its state: **Current**, or **New — not yet active**,
and **Valid**, **Expiring soon** ("Stops working in 12 days") or **Expired**.

If the page says "This organization has no SAML connection", there is nothing to renew.

## 1. Upload the new certificate

Under **Upload the new certificate**, choose how:

- **Metadata URL or XML** — your provider's metadata URL, or the metadata file's contents.
  The certificate is read from it. The metadata must belong to the same identity provider
  as the connection.
- **Certificate (PEM)** — paste the whole PEM block, including
  `-----BEGIN CERTIFICATE-----`.

Choose **Check and upload**. **What we checked** lists the checks it passed: it is an X.509
certificate, it has not expired, its key is strong enough (at least 2048 bits), it is not
already uploaded, and it is valid from today. "Not valid yet" is fine if your provider has
not switched to it.

Most providers let you create the new certificate in advance and keep signing with the
old one until you choose; do it that way.

## 2. Switch your provider over

In your identity provider, make the new certificate the active signing certificate. For
example, in Microsoft Entra ID, under the app's **SAML Certificates**, make the new
certificate active; in Okta, activate the new certificate on the app's **Sign On** tab.
Sign-ins keep working, signed with either.

## 3. Activate it here

Choose **Activate**. Do this once your provider signs with the new certificate: sign-ins
signed with the old one are refused from then on. You are asked to confirm.

## If something goes wrong

| Message | What it means |
|---|---|
| "That isn't a certificate." | Paste the whole PEM block, including the BEGIN and END lines. |
| "That certificate has already expired." | Download the current one from your provider. |
| "That certificate's key is too weak." | Use a certificate with a key of at least 2048 bits. |
| "That metadata belongs to a different identity provider than this connection." | You pasted another app's or provider's metadata. |
| "Upload the new certificate before activating it." | Step 1 first. |

## Related

- [Enterprise SSO](sso.md) — setting up the connection in the first place.
- [Okta](idp/okta.md), [Microsoft Entra ID](idp/entra-id.md), [Google Workspace](idp/google-workspace.md) — where the certificate lives in each.
