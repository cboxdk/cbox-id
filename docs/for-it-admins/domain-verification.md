---
title: Domain verification
weight: 30
description: Prove you own your company's email domains in the setup portal by publishing a DNS TXT record, so the people on them are sent to your single sign-on.
---

# Domain verification

**Page:** Verify your domains

A verified domain is how the product recognises `someone@example.com` as one of yours, and
sends that person to your [single sign-on](sso.md). You prove you own the domain by
publishing a DNS record only its owner could publish.

This page is reached from the Domain verification card, or from step 4 of Enterprise SSO.

## Verify a domain

1. Enter the **Domain** your team's email addresses use, for example `example.com`, not an
   email address or a URL. Choose **Add domain**.
2. The page shows a TXT record to publish:

   | Type | Name | Value |
   |---|---|---|
   | TXT | `_cbox-id-challenge.example.com` | a 32-character code, for example `4f1c…` |

   Use the copy buttons beside **Name** and **Value**. Some DNS providers add your domain
   to the name for you; if yours does, enter only `_cbox-id-challenge`.
3. Add the record at your DNS provider.
4. Choose **Check DNS**. DNS changes usually show up within minutes, but can take up to an
   hour. The domain shows **Waiting for DNS** until the record is found, then **Verified**.

The check asks your domain's own nameservers, so you do not have to wait for caches to
expire. Once verified, the domain stays verified; you can remove the TXT record later.

Repeat for every domain your people have email addresses on.

## Remove a domain

**Remove** drops the domain. Anyone signing in with an address at that domain stops being
routed to your single sign-on. You are asked to type the domain to confirm.

## If something goes wrong

| Message | What it means |
|---|---|
| "Enter a valid domain, e.g. acme.com." | Enter the bare domain, without `@`, `https://` or a path. |
| "We couldn't find the TXT record yet." | It has not propagated, or the name or value differs. Check the name is `_cbox-id-challenge.` plus your domain, and that the value has no quotes or spaces added. |
| "That domain is already claimed by another organization." | Another organization in the product has verified it. Ask the person who sent you the link. |

## Related

- [Enterprise SSO](sso.md) — what a verified domain is for.
- [For IT admins](_index.md) — how the setup link works.
