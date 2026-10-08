---
title: Audit log and App audit logs
weight: 18
description: The two records with "audit" in the name — the Audit log Cbox ID keeps of itself, and the App audit logs your app sends about its own customers — plus where Log streams, webhooks and events fit.
---

# Audit log and App audit logs

Two products have "audit" in the name. They answer different questions, and mixing them up
is the most common wrong turn in this part of the docs.

| | Audit log | App audit logs |
|---|---|---|
| The question it answers | What happened **in Cbox ID**? | What happened **in your app**? |
| Who writes it | Cbox ID itself, on every change, through every door | your app, with `POST /api/v1/audit-logs/events` |
| What is in it | members, roles, connections, keys, settings, approvals — every [action](actions.md) | whatever your app reports: "exported invoices", "changed the billing contact" |
| Who reads it | administrators in the console, `audit.list` over the API | your app, and each organization's own admins, in the console or the Admin Portal |
| Kept | for good; it cannot be pruned | a retention you choose per environment |
| Tamper evidence | one hash chain for the platform, with signed checkpoints | one hash chain per organization |
| Guide | [Audit log](../guides/activity-log.md) | [App audit logs](../guides/audit-logs.md) |

**The Audit log is not something you switch on.** Every action records itself, with the real
actor: the person in the console, the key over REST, the person behind a delegated token,
and who approved it when it was [held for approval](approvals.md).

**App audit logs are a product you build on.** Your customers' security teams will ask what
happened in your product; you send those events per organization, optionally against a
schema, and each customer reads their own — or you hand their IT admin an
[Admin Portal](../guides/admin-portal.md) link that shows them.

## Getting events out

Three ways to get facts out of Cbox ID, for three different jobs:

| | What it carries | Use it to |
|---|---|---|
| [Log streams](../guides/log-streams.md) | Audit log entries, mirrored as they are written | feed a SIEM, so an investigation starts in the tools your security team already uses |
| [Webhooks](../guides/webhooks.md) | domain events: a user was created, a member joined | react in your app's backend |
| `events.list` | the same domain events, as a list you poll | catch up without a public endpoint |

Log streams and webhooks both deliver at least once: expect the occasional duplicate, and
make whatever consumes them safe to run twice.

## Related

- [Audit log](../guides/activity-log.md): reading and exporting the platform's own trail.
- [App audit logs](../guides/audit-logs.md): sending, schemas, retention, export.
- [Compliance](../security/compliance.md): the system-level view an auditor asks for.
