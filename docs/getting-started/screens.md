---
title: Screens
weight: 3
description: A tour of the three consoles (workspace, organization and environment), their areas, and the sign-in surface, with screenshots dated 2026-07-13.
---

# Screens

A tour of the Cbox ID app: the consoles and the sign-in surface. It follows the
console's own structure, with areas declared once in
[`ConsoleArea`](https://github.com/cboxdk/cbox-id/blob/main/app/Platform/Console/ConsoleArea.php)
and rendered by the same components on every console.

> **The screenshots below are dated 2026-07-13 and are stale.** They were taken against a
> flat page list (Members / SSO connections / Directory sync / Roles / API clients /
> Webhooks / Audit / Settings) that the console no longer has, and most pages have since
> been renamed and moved. The prose is current; the images are not, and nothing here has
> been re-shot. There is also **no screenshot of the Platform areas** at all, which is
> the part of the console the person who runs the deployment spends their time in.

## Three consoles, one shell

The same shell serves three kinds of administrator. Which one you get depends on where
you signed in and what you administer:

| Console | Where | Who it is for |
|---|---|---|
| **Workspace console** | The platform root host of a hosted deployment | Your own Cbox workspace: projects, team, keys, billing |
| **Organization console** | An organization's host, or a single-tenant install | One organization: its members, sign-in, apps |
| **Environment console** | `/admin` on an environment's own host | One environment: every organization in it, and their users |

The organization and environment consoles offer the same capabilities; the environment
console adds **Organizations** and an acting-organization picker, because it administers
many organizations rather than one. A health check (`cbox-id:doctor`) fails if the two
ever offer different capabilities. [Workspaces &
organizations](../core-concepts/workspaces-and-organizations.md) explains the difference
between a workspace and an organization.

Every page has **one URL**, the same on both consoles; the environment console's is that
path under `/admin` (`/apps` and `/admin/apps`). Old paths answer with a 301, so a
bookmark still works. [Upgrading](https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md)
lists them.

The deployment's own pages (workspaces, environments, organizations, operators) are the
**Platform**, **Insights** and **Administration** areas at the bottom of the rail, shown
to whoever has authority over the deployment.

## The rail

The rail is 64px wide and shows one icon per area; every area has its own icon. Hover it
and an overlay opens with the labels; **pin** it to keep the labels open. Below the `lg`
breakpoint it becomes an off-canvas drawer behind the menu button in the top bar.

Every page has a **"?"** beside its title with a short explanation, and a **Read the
guide** link to the [admin guides](../guides/_index.md) where there is one.

## Sign-in surface

### Login

Password sign-in, plus **passwordless options**: email magic link and **passkey**
(WebAuthn) sign-in. Social buttons appear when a provider is configured. Organizations
get a branded variant at `/o/{slug}/login`.

![Login screen](../screenshots/login.png)

### Signup

Create a new organization and its first owner. Risk scoring runs on submit
(monitor mode by default). Availability depends on `CBOX_ID_SIGNUP_MODE` — see
[Security](../security/_index.md#self-service-signup-modes).

![Signup screen](../screenshots/signup.png)

### Whose name is on the door

On the platform root the sign-in pages carry Cbox ID's own panel beside the form. On a
customer's environment every door — sign-in, sign-up, password reset, magic link, an
invitation, the organization picker and the create-a-team step — carries that
environment's brand instead: the name and logo its Appearance page previews, over its
colours, with no Cbox ID panel. An organization's own door (`/o/{slug}/login`) carries the
organization's. The consoles on that host stay Cbox ID's.

### Joining by invitation

The link in an invitation opens a page that says who is inviting whom, and every role
accepting will grant, in the console's words: the **built-in role** (Member, Admin, …) and
the **roles in each app** and **custom roles** the inviter ticked. A role that became
staff-only or was retired while the invitation waited is left off, because accepting would
withhold it.

## The workspace console

What a workspace member sees at the platform root of a hosted deployment. The rail is the
workspace and nothing else:

- **Workspace:** Projects, Team, Keys, Environment domains, Billing, Workspace settings.
- **Team sign-in:** Single sign-on, Sign-in rules. How your own team signs in to Cbox, not
  how your product's users sign in.
- **Logs:** Activity log.
- **My account:** Security, Sessions & activity.

There is no Overview page and no setup guide here. The pages that administer end users
(roles, apps, webhooks and the rest) are hidden from this rail and still reachable by
URL, with a notice that says the page manages the workspace's own record in Cbox, not
your product. [Workspaces &
organizations](../core-concepts/workspaces-and-organizations.md#the-workspace-console)
explains why they are hidden rather than redirected.

After signing in, a member with exactly one environment they may administer lands in that
environment's console; everyone else lands on **Projects**, where each environment has
**Open console**.

## The organization console, area by area

### Overview

*Overview · Usage · Approve agent requests.* The home page: member count, enterprise-SSO
status, your role, a live **recent activity** feed from the tamper-evident audit log, and
an onboarding checklist. **Approve agent requests** is where you approve or deny a
request from an app or agent to act as you.

![Dashboard / overview](../screenshots/dashboard.png)

### People

*Members · Roles · Permissions.* The people in this organization and what they may do:
invite, change roles, remove, every change audited. Each person's **Roles** control holds
exactly one built-in role (what they may administer in the console) and any number of
roles your apps understand. The role and permission model itself is org-scoped and
hierarchy-aware.

![Members](../screenshots/members.png)
![Roles](../screenshots/roles.png)

### Sign-in

*Single sign-on · Social sign-in · Sign-in rules · Sync users in · Sync users out.*
Everything about how people get in and how their accounts arrive. Single sign-on
connects an organization's own IdP (SAML / OIDC); social sign-in is picked from a
catalogue rather than described from memory; **Sign-in rules** are the password, MFA and
session policy (they used to sit under Settings); "sync users in" is inbound SCIM
provisioning (deprovision revokes sessions immediately) and "sync users out" pushes the
same directory to downstream apps.

The two SCIM directions are named as a pair on purpose: "Directory sync" beside
"Outbound sync" gave no clue which way either moved people. The screenshots below predate
that rename.

![Single sign-on](../screenshots/connections.png)
![Sync users in](../screenshots/directories.png)

### Access control

*Access reviews · Role conflicts.* Certification campaigns (a snapshot of who holds what,
certified or revoked line by line, with the revokes applied on close) and
separation-of-duties rules that refuse a combination of roles nobody should hold at once.
No screenshot.

### Developers

*Apps · Webhooks · Inline hooks · Token vault.* OAuth clients registered against this
instance (including MCP clients self-registering through Dynamic Client Registration);
HMAC-signed event delivery with retries and delivery history; synchronous inline hooks
that run *during* a flow rather than after it; and the vault holding third-party tokens.
Machine keys are on the [Keys](../guides/keys.md) page of the workspace and environment
consoles.

The screenshots below are from when this area was "API clients" and "Webhooks" as two
separate top-level pages.

![Apps](../screenshots/clients.png)
![Webhooks](../screenshots/webhooks.png)

### Connectors

Third-party integrations, contributed by the connectors module rather than written into
the console. No screenshot.

### Logs

*Activity log · Log streaming.* The append-only, hash-chained audit trail, filterable and
exportable to your SIEM. The compliance and risk modules append their pages here rather
than minting areas of their own.

![Activity log](../screenshots/audit.png)

### Settings

*Settings · Appearance.* Organization details, and the branding an organization's own
sign-in page inherits.

![Settings](../screenshots/settings.png)

### My account

*Security · Sessions & activity.* The signed-in person's own credentials: two-factor
authentication, **passkey** enrolment, and sessions (auth methods, expiry,
sign-out-everywhere). Shown to members and admins alike; every area above is role-gated,
this one is not.

No screenshot of its own. The 2026-07-13 image above filed these settings under
*Settings*, which is where they used to live.

## The environment console

The same areas as the organization console, plus the ones that only make sense for a
whole environment:

- **Overview:** Overview, Usage, **Review agent requests** (every pending agent request
  in the environment, so an administrator can deny one that looks like abuse).
- **Organizations:** your customers' organizations.
- **People:** Users, Roles, Permissions. A user's page has **Staff roles**: roles granted
  across the whole environment.
- **Sign-in:** Single sign-on, Social sign-in, Sign-in rules, SAML applications, Sync
  users in, Sync users out.
- **Access control:** Access reviews, Role conflicts.
- **Developers:** Apps, Keys, Legacy login, Webhooks, Inline hooks, Token vault.
- **Connectors**, **Logs** (Activity log, Log streaming) and **Settings** (Settings,
  Appearance, and Branding when the whitelabel module is on).

It has no My account area. Your own password, passkeys and sessions belong to your
workspace, so **My account** and **Switch user** in the account menu open on the
workspace host.

The topbar reads `← <Workspace> / <Environment> [badge] / <acting organization>`. The
first crumb goes back to the workspace's Projects page.

## Chrome

### Organization switcher

A signed-in user who belongs to several organizations switches the active organization
from the sidebar card. The switch is server-verified against membership (you can only
switch into an org you actually belong to) and the role updates with it (here: Owner in
Acme, Admin in Globex). The security model is described in
[Security](../security/_index.md#organization-switcher).

The environment console has a second, unrelated picker in its topbar: which organization
the console is **acting on**. It is a search rather than a list, because an environment
with four thousand organizations is a real one.

![Organization switcher](../screenshots/org-switcher.png)

### Switch user

**Switch user** in the account menu moves between the people signed in on this device.
It does not change organization; the organization switcher does that.

### Responsive (mobile & tablet)

Below the `lg` breakpoint the rail collapses into an off-canvas **navigation drawer**
(menu button in the top bar) holding the full nav, org context, theme toggle and
sign-out; content stacks to a single column and wide tables scroll within their card.
The sign-in split-screen collapses to a centered form. Verified at phone (390px) and
tablet (768px) widths.

![Console on mobile](../screenshots/mobile-dashboard.png)

## Notes

- Inertia + React over server-rendered props, session-cookie auth, no tokens in
  the browser — chosen because this *is* the login surface. See the framework
  [security model](https://github.com/cboxdk/laravel-id/blob/main/docs/security/_index.md).
- **Accessibility:** the auth and console pages pass an automated axe-core
  WCAG 2.1 A/AA audit (guarded by a regression test); keyboard-navigable with a
  skip link, labelled landmarks and controls.
- To reproduce these locally: `php artisan migrate`, seed a demo org
  (`php artisan db:seed --class=DemoSeeder`), then sign in.
