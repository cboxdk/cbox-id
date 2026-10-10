---
title: Finding your way
weight: 4
description: Where everything is in the environment console — sign-in methods, SSO, Directory Sync, domains, users, organizations, roles, apps and agents — task by task, with the clicks it takes.
---

# Finding your way

The environment console (`/admin` on your environment's host) is grouped by task, in the
words other identity platforms use. If you have used WorkOS, Clerk or Auth0, most things
are where you would look first. This page shows the map, then walks the common tasks
click by click.

**Three ways to get anywhere:**

- **The rail** on the left: one icon per area. Clicking an area opens its first page, and
  its other pages are listed beside it.
- **⌘K** (Ctrl+K on Windows and Linux): type a page's name, or the word you would use for
  it elsewhere. *SAML*, *OIDC*, *SCIM*, *HR system*, *Google login*, *tenant*, *RBAC*,
  *passkeys*, *MCP* and *logo* all find the right page. It also finds a user by email, an
  organization or app by name, and any record by a pasted id.
- **Breadcrumbs** on every detail page, such as *Authentication / Enterprise SSO* above a
  connection. They lead back to the list the page belongs to.

## The map

| Area | Pages | What it is for |
|---|---|---|
| **Home** | Overview, Get started | What is set up, and the next step |
| **Users & orgs** | Users, Organizations | The people who sign in, and the customer organizations they belong to |
| **Authentication** | Sign-in methods, Authentication policy, Social login, Enterprise SSO, Domains, Directory Sync, Radar | How people get in |
| **Authorization** | Roles, Permissions, Fine-grained authorization, Access reviews, Role conflicts | What people may do once they are in |
| **Developers** | Applications, APIs, API keys, Webhooks, Hooks, Feature flags, Pipes | What your code talks to |
| **AI agents** | Agents, Approvals, Connect | Software that acts on this environment, and the MCP server |
| **Branding** | Branding | How the hosted sign-in page looks, the logo and favicon, and the name your mail is sent under |
| **Monitoring** | Audit log, App audit logs, Log streams, Usage | What happened |
| **Advanced** | Admins & support, Token vault, Outbound provisioning, SAML apps, Legacy login | Set up once and rarely revisited |
| **Settings** | Settings | The environment's id, issuer and discovery URL |

Modules add pages to these areas when they are switched on. Examples are *Connectors*,
*Sign-in activity*, *Risk events* and *Trusted devices*.

### One organization's page

Open an organization from **Users & orgs › Organizations**. Its page has the
**Admin Portal link** button in the header and nine tabs:

**Overview · Members · SSO · Directory Sync · Domains · Roles · Authentication policy · Audit
log · Settings**

Some tabs hold more than one page. Those pages appear as a second, smaller row:

- **Members:** Members, Invitations.
- **Audit log:** Audit log, App audit logs.
- **Settings:** General, Branding, API keys, Support access.

Enterprise SSO, Domains, Directory Sync, Roles and the Audit log each appear in two places:

- On the organization's tab, showing only that organization's rows.
- In the rail, showing every organization's rows, with an **Organization** filter.

### What changed

The console used to file these pages differently. Every URL still works, so bookmarks and
links in older notes keep working.

| Before | Now |
|---|---|
| Users & orgs held Roles, Permissions and Fine-grained authorization | They are in a new **Authorization** area, beside Authentication |
| Access reviews and Role conflicts were under Advanced | They are under **Authorization**, beside the roles they govern |
| Password rules, two-factor, SMS, social and SSO were on separate pages, and passkeys, magic links and sessions were on none | **Authentication › Sign-in methods** shows every way in, whether it is on, and where it is changed |
| Social login was set up per organization, so turning on Google meant doing it for each one | A provider is set up once for the environment and every organization inherits it; an organization can still use its own |
| Passkeys, magic links and session lifetime were decided by the deployment only | They are on **Authentication policy**, for the environment, within the deployment's limits |
| An organization's domains were only on its own page | **Authentication › Domains** lists every organization's domains as well |
| An organization had 14 tabs in one row, and the last ones were cut off on a laptop | 9 tabs, with related pages grouped under one tab |
| Detail pages had a bare "‹ Back" link | A breadcrumb shows the area and the list |
| ⌘K matched only page names, so "SAML" found SAML apps (the outbound direction) and "SCIM" found nothing | ⌘K also matches the words people use elsewhere |

## Task by task

The clicks below start from the environment's home page. `tests/Feature/Crawl/FindingYourWayTest.php`
checks that each task stays within these limits.

### Turn on Google or GitHub login

1. **Authentication › Social login**.
2. **Google** (or GitHub) under **Add a provider**. **Who is it for?** is already
   **The whole environment**.
3. Copy the redirect URI it shows into your own Google or GitHub OAuth app, paste the client
   ID and secret back, and press **Turn on Google for everyone**.

That is three clicks from the environment's home page. Every organization's sign-in page now
offers it. To give one organization its own credentials instead, choose it under **Who is it
for?**; to take the button off one organization's page, open Social login filtered to that
organization and use **Turn off here**. [Social login](../guides/social-sign-in.md).

### Set up enterprise SSO for a customer, verify their domain, and hand over the setup

1. **Users & orgs › Organizations**, then open the organization, for example Acme Corp.
2. **SSO** tab, then **New connection**. Choose SAML 2.0 or OpenID Connect, and paste the
   identity provider's metadata.
3. **Domains** tab: add `acme.com`, publish the TXT record it shows, then **Verify**. Turn
   on **Capture** to send everyone on that domain to Acme's SSO.
4. If Acme's IT administrator should do steps 2 and 3 themselves, use **Admin Portal link**
   in the organization's header instead. Pick what the link sets up and send it.

Every connection and every claimed domain across all organizations is also listed under
**Authentication › Enterprise SSO** and **Authentication › Domains**.
[Enterprise SSO](../guides/single-sign-on.md),
[Admin Portal](../guides/admin-portal.md).

### Turn on Directory Sync (SCIM or an HR system)

On the organization's page, open the **Directory Sync** tab, then **New directory**.
Alternatively, use **Authentication › Directory Sync › New directory**. Choose one of:

- **SCIM**, if the identity provider pushes changes to Cbox ID;
- **Google Workspace** or **Entra**, which Cbox ID pulls from;
- an HR system: **Workday, BambooHR, Rippling, HiBob or Personio**.

[Directory Sync](../guides/sync-users-in.md), [HR system sync](../guides/hris.md).

### Find a user: their organizations, sessions and two-factor, and why a sign-in was challenged

**Users & orgs › Users**, then search by name or email. Or press ⌘K and type the email.
The user's page shows:

- their organizations and roles;
- their active sessions, with **Revoke** and **Revoke all**;
- their two-factor status, with **Reset 2FA**.

**Sign-in decisions** opens Radar filtered to that user's address. Each decision shows
the rule that fired and the signals it read. [Radar](../guides/radar.md).

### Create an organization, invite someone, change a member's role

1. **Users & orgs › Organizations › New organization**.
2. On its page, open **Members**, then **Invite by email**. **Add** on the same page is for
   people who already have an account in this environment.
3. To change someone's role, use **Edit roles** on their row in **Members**. That covers
   the one built-in role and any app or custom roles.

### Create a custom role, and know when you need something else

**Authorization › Roles › New role**. Name it, choose which apps it applies to, and pick
its permissions. Assign it from an organization's **Members** tab. Every Authorization page
ends with the same short comparison of four things that are easy to mix up:

| You want to say | Use |
|---|---|
| "An Accountant may `invoices:write`" | **Roles & permissions**: what a person may do in an organization |
| "Ada may edit the Q3 folder" | **Fine-grained authorization**: who may do what to which record |
| "The new dashboard is on for Acme" | **Feature flags**: a rollout, not access control |
| "Acme's plan includes SSO" | **Entitlements**: set by billing, read from the token, nothing to manage here |

[Roles](../guides/roles.md), [Permissions](../guides/permissions.md),
[Fine-grained authorization](../guides/fine-grained-authorization.md),
[Feature flags](../guides/feature-flags.md), [Entitlements](../core-concepts/entitlements.md).

### Register an app, set its redirect URIs, get its keys, and connect an AI agent

1. **Developers › Applications › New app**. Answer what kind of app it is, and enter the
   redirect URIs. Its client ID and secret are on the next screen.
2. You can change the redirect URIs later on the app's **Settings** tab.
3. Browser-side keys are under **Developers › API keys**.
4. **AI agents › Connect** gives the MCP server URL and setup for Claude Code, Claude
   Desktop, Cursor or VS Code. **Create a key for this agent** issues the key it uses.

[Integrate your app](integrate-your-app.md), [Agents and MCP](../guides/agents-and-mcp.md).

### See where every sign-in method is configured

Open **Authentication**. Its first page, **Sign-in methods**, lists each method with
whether it is on and where it is changed:

- password
- passkeys
- magic link
- social login
- enterprise SSO
- two-factor
- text-message codes
- self-service sign-up
- Radar
- bot challenge
- session lifetime

Three kinds of setting appear there:

- **Set for this environment:** changed on Authentication policy or Social login. This now
  includes passkeys, magic links, the bot challenge and session lifetime, which used to be
  the deployment's alone.
- **Set per organization:** SSO connections, and an organization's stricter password and
  two-factor rules.
- **Set by the deployment:** a method the deployment switched off (for example
  `CBOX_ID_PASSKEYS_ENABLED=false`) stays off whatever the environment says, and the row
  names the variable. Rows with a deployment limit, such as the longest session, show it.

On a single-tenant install there is no environment console: the same page is under
**Sign-in › Sign-in methods** in the organization console, and its owners and admins change
the environment's settings there too — passkeys, magic links, sessions and text-message
codes on **Authentication policy**, and the environment's providers on **Social login**. [Authentication policy and sign-in methods](../guides/authentication-policy.md).

### Brand the sign-in page

**Branding**. Choose a preset, set the colours, corners and typeface, upload the logo and
favicon, and check the live preview; the product name and email sender are further down the
same page. To give one organization its own look, open its page, then its **Branding** tab.
