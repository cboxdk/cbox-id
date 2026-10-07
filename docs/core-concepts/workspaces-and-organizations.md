---
title: Workspaces & organizations
weight: 5
description: The five layers, the difference between your workspace and the organizations inside your product, and which console you are in.
---

# Workspaces & organizations

Read this before the rest. Two things in Cbox ID are organizations underneath, and the
console gives them different names so you can tell them apart:

- your **workspace** is your own Cbox account: it owns projects, environments, a team
  and a bill;
- an **organization** is a team of *your* customers, inside one of your environments.

## The chain

```
Workspace  →  Project  →  Environment  →  Organization  →  Subject
(you, the      (one IdP      (that product's    (one of YOUR      (a person who
 Cbox account)  product)      prod/sandbox)      customers' teams)  signs in)
```

Read left to right, it is one sentence: *you* own *products*, each product has *stages*,
each stage holds *your customers' teams*, and each team has *people*.

| Layer | What it is | Who it belongs to |
|---|---|---|
| **Workspace** | You. The billing customer of Cbox, and the team your colleagues join to administer your products. | Cbox ID |
| **Project** | One identity product you run. The plan and the environment allowance live here, so two products bill separately from one workspace. | Your workspace |
| **Environment** | A stage of that product: production, sandbox. A hard boundary with its own users, signing keys, issuer, branding and sign-in page. | Your project |
| **Organization** | One of your customers' companies or teams. An arbitrary-depth tree, so a group can hold companies and a company divisions. | Your environment |
| **Subject** | A person who signs in to your product. | Your environment's user pool |

## One kind of row, two names

**Underneath, a workspace and an organization are the same kind of row.** There used to
be a separate `Account` model above the organization, with its own name, status, members
and role vocabulary: two rows for one customer, and two answers to "who may act for
them". They kept disagreeing, so that row was removed rather than reconciled. A workspace
*is* an organization, living in the platform-root environment, and its team are ordinary
subjects holding a membership of it.

That is why one sign-in reaches everything you administer. The database calls both an
`organizations` row; the console never does:

- In the **platform root**, the row is **a workspace**: a customer of Cbox.
- In **your own environment**, the row is **an organization**: one of your customers'
  teams.

**The tell: a workspace owns projects. An organization does not.** If the thing in front
of you has projects, environments and a bill, it is your workspace. If it has members,
roles and SSO connections and lives inside one environment, it is one of your customers.

## Which console you are in

You administer the two through two consoles.

### The workspace console

On the platform root host of a hosted deployment (`cboxid.com`), signed in as yourself.
Its rail holds the workspace and nothing else:

| Area | Pages |
|---|---|
| **Workspace** | Projects, Team, Keys, Environment domains, Billing, Workspace settings |
| **Team sign-in** | Enterprise SSO, Authentication policy: how your own team signs in to Cbox |
| **Logs** | Audit log |
| **My account** | Security, Sessions & activity |

The pages that administer end users (roles, permissions, applications, webhooks, hooks,
token vault, connectors, access reviews and the rest) are not on this rail. At the
platform root they would administer your workspace's own record in Cbox's environment,
which nobody signs in to except your team. Your product's users, apps and roles live in
an environment console.

Those pages are **hidden from the rail, not redirected.** Three reasons:

- **Nothing is stranded.** If you registered an app or a webhook there before this
  change, you can still open it by URL and delete it. A redirect would have made it
  unreachable without making it go away.
- **One gate decides access.** The pages stay authorized by the same check as every other
  console page. A redirect would be a second gate that has to agree with the first on
  every route, forever; a filter on what the rail shows cannot lock anybody out.
- **The page says what it is.** Reached by URL, it shows a notice above the content: the
  page manages your workspace's own record in Cbox, not your product, with a link to
  Projects.

The workspace console also has no Overview page (`/dashboard`) and no setup guide
(`/get-started`). Both described the workspace's own record, not your product.

An operator and a single-tenant install (where the root environment *is* the product)
keep the full organization console. Your customers get the narrower one below.

### Your customer's organization console

On your environment's own host, signed in as one of your customers' administrators: a
person who signs in to your product and administers their company's organization in it.
This console is an admin portal for their IT department, plus their own pages:

| Area | Pages |
|---|---|
| **Overview** | Overview (their organization's numbers and recent activity), Approve agent requests |
| **People** | Members, Roles, Permissions |
| **Sign-in** | Enterprise SSO (with its verified domains), Directory Sync |
| **Logs** | Audit log |
| **My account** | Security, Sessions & activity, API keys (when one of your apps offers them), Trusted devices (when the devices module is on) |

Everything else is your product's administration and lives in the environment console:
applications and APIs, webhooks, hooks, the token vault, access reviews, role conflicts,
outbound provisioning, log streams, social login, authentication policy, appearance and branding,
usage, settings, member API keys, the setup guide and the module pages (sign-in activity,
compliance, connectors, trusted-device inventory, risk events).

Those pages are **not there** on this console, not just hidden from the rail: their URLs
answer 404, writes included. Unlike the workspace console there is nothing to strand, since
the same records stay reachable from your environment console at `/admin`. One list decides
both the rail and the 404, so the two cannot disagree
([`CustomerConsole`](https://github.com/cboxdk/cbox-id/blob/main/app/Platform/Console/CustomerConsole.php)).

The organization console and the environment console used to be required to offer the
same capabilities, with a doctor check that failed if they did not. That rule is gone:
the consoles differ on purpose now.

### An environment console

At `/admin` on that environment's own host, reached from **Projects** with **Open
console**, or from the environment switcher in any console's topbar. Its rail is about
your product, in the words other identity platforms use for the same things. Nothing on
it is about your Cbox workspace, and everything your customers' own console leaves out is
here.

| Area | Pages |
|---|---|
| **Home** | Overview |
| **Users & orgs** | Users, Organizations (your customers), Roles, Permissions |
| **Authentication** | Authentication policy, Social login, Enterprise SSO, Directory Sync, Trusted devices (devices module) |
| **Developers** | Applications, APIs, API keys, Webhooks, Hooks |
| **Connectors** | Catalog, Connections (connectors module) |
| **AI agents** | Approvals |
| **Branding** | Appearance, Branding (white-label module) |
| **Monitoring** | Audit log, Log streams, Usage, Sign-in activity, Audit trail, Exports & retention, Risk events (each from its module) |
| **Advanced** | Admins & support, Access reviews, Role conflicts, Token vault, Outbound provisioning, SAML apps, Legacy login |
| **Settings** | Settings |

**Authentication** holds every way people come *in*; the outbound directions (SAML apps
that trust this environment, provisioning out to other systems) are under **Advanced**
with the rest of what is set up once and rarely revisited. The URLs did not change when
the pages were renamed, so bookmarks and links keep working.

## Moving between them

Every console has the same topbar: where you are on the left, search and your account on
the right.

```
Acme ▾  /  Checkout ▾  /  Production ▾ [PRODUCTION]  /  All organizations ▾
```

- **Workspace ▾** lists the workspaces you belong to. On the workspace console choosing
  one switches to it; on an environment console it opens the workspace console, where
  your workspace session is. An organization that owns no projects shows its name here
  as **Organization**, with nothing after it.
- **Project ▾** lists your workspace's projects. Choosing one opens the environment there
  that matches the one you are in: production for production.
- **Environment ▾** lists the environments of this project you may administer, each with
  its type. Choosing one opens the **same page** there: Users in sandbox becomes Users in
  production. A page about one record (a user, an app) opens that list instead, and a
  page the other environment does not have opens its Overview.
- On the workspace console no environment is current, so the second crumb is
  **Environments ▾**: every environment you may open, grouped by project.
- **All organizations ▾**, on an environment console only, filters every page to one of
  your customers. It is a search, because an environment can hold thousands.

Only what you may open is listed. A Viewer sees no environments, and a member limited to
some environments sees those. Another workspace's projects appear only once you switch to
it. Each menu gets a search box once it holds more than eight entries.

Switching environment goes through the same signed handoff as **Open console**; the page
to land on travels with it, and the environment refuses any target that is not one of its
own console pages.

**The account menu** is the avatar top right: **Workspace settings** (if you may change
them), **My account**, **Switch user**, **Platform admin** (operators only), **Theme** and
**Sign out**. On an environment console the account links open on the workspace host,
because your own sign-in belongs to the workspace, not to the environment.

**The rail shows its labels by default.** The pin at the top of the rail collapses it to
icons; the choice is remembered in this browser.

### Platform admin

Whoever runs the install reaches the platform pages (workspaces, environments, usage,
queues, operators) from **Platform admin** in the account menu, not from the rail. Inside
it the rail holds only the platform areas, and a strip across the top says **Platform
admin** with **Exit platform admin** beside it, because a click there acts on every
workspace on the install. Re-pointing the platform pages at another environment is done
on **Platform › Environments**.

## Where you land after signing in

A workspace member lands where they work:

- If exactly one active environment is reachable and your built-in role may administer
  environments (Owner, Admin or Developer), you go straight into that environment's
  console. It is the same signed handoff **Open console** on Projects uses.
- Otherwise you land on **Projects**, which lists every environment with **Open console**
  beside it.

The workspace crumb in an environment console links to Projects, not to this landing, or
it would send you straight back into the environment.

## Related

- [Unified identity](unified-identity.md): the design record for why the separate
  account row was removed rather than kept alongside.
- [Keys](../guides/keys.md): the keys each console issues.
- [Integrate your app](../getting-started/integrate-your-app.md): registering an app
  inside an environment.
