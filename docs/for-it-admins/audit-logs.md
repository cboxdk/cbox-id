---
title: Audit logs
weight: 60
description: Read, filter and export what the product recorded about your organization, through a read-only setup link.
---

# Audit logs

**Page:** Audit logs · *your organization*

This task shows what the product recorded about your organization, newest first: who did
what inside the product, to what, and when. It is **read-only**: nothing here can be
changed or deleted. It is often sent to a security team during an investigation or an
audit.

## Reading it

Each row shows the **Time**, the **Action** (for example `invoice.voided`), the actor, the
**Targets**, the **Location** it came from, and **Details** the product recorded with it.
Use **Older events** and **Newest events** to page.

## Filtering

Narrow the list with any of:

- **Action** — the exact action, such as `user.signed_in`;
- **Actor ID** — everything one person or system did;
- **Target ID** — everything done to one thing;
- **From** and **To** — a time range.

Choose **Filter**, or **Clear filters** to start over.

## Exporting

**Export CSV** downloads the events that match your filters, newest first, up to 50,000 of
them. For more, export a narrower time range at a time. Cells that start with `=`, `+`,
`-` or `@` are written with a leading `'`, so a spreadsheet does not run them as formulas.

Each export is recorded on the product's side, as a download through the Admin Portal.

## Finishing

Choose **Done** when you have what you need. The link is then closed for good.

## Related

- [Log streams](log-streams.md) — receive the audit log in your SIEM continuously instead.
- [For IT admins](_index.md) — how the setup link works.
