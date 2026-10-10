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

**They are retaken on every release.** The *Docs screenshots* workflow
(`.github/workflows/docs-screenshots.yml`) runs on each release tag, and on demand from
the Actions tab. It builds the app in the same Linux image and Chromium CI uses, takes
every picture below, and opens a pull request against `main` with the ones that changed;
it never pushes to `main`. Review the image diff in that PR before merging.

The run is also a smoke test of every page pictured: it fails, and proposes nothing, if a
page answers an error status, loads anything that fails, shows an error page, or the
browser reports an uncaught exception, a console error or warning, or a broken image.

To retake them locally:

```bash
npm run build
vendor/bin/pest --group=docs-screenshots
```

The run overwrites the files below in place. The group is excluded from every normal test
run (`phpunit.xml` and CI both exclude it). Pictures taken on macOS render text slightly
differently from the workflow's, so prefer the workflow's PR for anything you commit.

**The list is fixed.** `tests/Support/DocsScreenshots.php` names every picture, and the
normal test suite holds this folder to it: each file is taken by the generator, embedded
by at least one page and listed below, and every picture a page embeds exists. To add one,
add its name there, take it in `tests/Browser/DocsScreenshotsTest.php`, embed it in the
page it illustrates, and add its row here.

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
| `roles.png` | Authorization › Roles (`/admin/roles`) |
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
