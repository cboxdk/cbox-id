---
title: Activity log
weight: 110
description: The tamper-evident record of every administrative change in your organization — what it captures, why it is hash-chained, and what it is not.
---

# Activity log

**Console page:** Logs › Activity log

Every administrative change in your organization lands here: who did it, what they
did it to, and when. Members added and removed, roles granted, connections created
and activated, apps registered, secrets stored and rotated, reviews closed.

It records itself. There is nothing to switch on, and nothing you can do to make it
skip an entry.

## Tamper-evident

Entries are **hash-chained**: each one commits to the one before it, so removing or
editing an entry after the fact breaks the chain and is detectable.

Be precise about what that buys you. It is **tamper-evident, not tamper-proof** —
it does not stop someone with database access from rewriting history, it makes the
rewrite visible. For an auditor, that is usually the useful property: nobody has to
trust that the log was not edited, because the log can be checked.

## Using it

- **Filter by action** to answer a specific question — every `member.removed` last
  month — rather than reading chronologically.
- **The log is read-only, on purpose.** There is no edit, no delete, no retention
  slider in the console. A log an administrator can prune is not evidence.
- **It answers "who", not "why".** For sensitive changes, the reason belongs in
  your own change process; the log will tell you it happened and who was signed in.

## Related

- [Access reviews](access-reviews.md) — periodic certification, recorded here.
- [Webhooks](webhooks.md) — if you want the same events streamed to your own
  systems as they happen.
