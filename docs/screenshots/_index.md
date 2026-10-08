---
title: Screenshots
weight: 90
description: Console and hosted-auth screenshots referenced throughout the documentation, and how to regenerate them.
---

# Screenshots

This folder holds the pictures embedded in the rest of the documentation. They are assets
referenced by other pages, not standalone reading — start from the
[documentation index](../index.md).

## Where they come from

Every picture here is taken from the running app by `tests/Browser/DocsScreenshotsTest.php`,
over the demo world `database/seeders/DemoEnvironmentSeeder.php` builds: the workspace
**Lovelace Labs** (owner Ada Lovelace), its project **Ledger** and environment of the same
name, four apps, the customer organizations Acme Corp (Enterprise SSO and a verified
domain), Globex (Directory Sync) and Initech, agents with approvals waiting, audit events, a
webhook and a log stream. Light theme, 1440×900, viewport only.

To regenerate them all:

```bash
vendor/bin/pest --group=docs-screenshots
```

The run overwrites the files below in place, so review the image diff before you commit it.
The group is excluded from every normal test run (`phpunit.xml` and CI both exclude it).
The pictures come from your local Chromium; the frontend must be built first
(`npm run build`), or the pages render against stale assets.

| File | Page |
| --- | --- |
| `hosted-sign-in.png` | An environment's hosted sign-in page (`/login` on its host) |
| `workspace-sign-up.png` | Workspace sign-up on the account host (`/signup`) |
| `workspace-projects.png` | Workspace › Projects (`/projects`, account host) |
| `environment-overview.png` | Environment console › Home › Overview (`/admin`) |
| `get-started.png` | Home › Get started (`/admin/get-started`) |
| `context-switcher.png` | The topbar's environment switcher, open |
| `mobile-overview.png` | The overview at phone width (375×812) |
| `users.png` | Users & orgs › Users (`/admin/users`) |
| `organizations.png` | Users & orgs › Organizations (`/admin/organizations`) |
| `organization-overview.png` | An organization's hub, Overview tab |
| `organization-members.png` | An organization's Members tab |
| `organization-enterprise-sso.png` | An organization's SSO tab (SAML connection + verified domain) |
| `organization-domains.png` | An organization's Domains tab |
| `organization-directory-sync.png` | An organization's Directory Sync tab |
| `directory-detail.png` | A SCIM directory's page (`/admin/sync-in/{id}`) |
| `roles.png` | Users & orgs › Roles (`/admin/roles`) |
| `applications.png` | Developers › Applications (`/admin/apps`) |
| `application-detail.png` | An application's Overview (`/admin/apps/{id}`) |
| `api-keys.png` | Developers › API keys (`/admin/keys/frontend`) |
| `webhooks.png` | Developers › Webhooks (`/admin/webhooks`) |
| `agents.png` | AI agents › Agents (`/admin/agents`) |
| `approvals.png` | AI agents › Approvals (`/admin/approvals`) |
| `audit-log.png` | Monitoring › Audit log (`/admin/audit`) |
| `app-audit-logs.png` | Monitoring › App audit logs (`/admin/audit-logs`) |
| `log-streams.png` | Monitoring › Log streams (`/admin/log-streaming`) |
| `environment-settings.png` | Settings (`/admin/settings`) |
| `admin-portal-link.png` | An Admin Portal link, as the customer's IT admin opens it (`/setup/{token}`) |
| `admin-portal.png` | The Admin Portal's task list after **Open setup** |
