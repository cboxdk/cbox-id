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
| **Team sign-in** | Single sign-on, Sign-in rules: how your own team signs in to Cbox |
| **Logs** | Activity log |
| **My account** | Security, Sessions & activity |

The pages that administer end users (roles, permissions, apps, webhooks, inline hooks,
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

Everyone else keeps the full console: an operator, a single-tenant install (where the
root environment *is* the product) and every organization on any other host.

### An environment console

At `/admin` on that environment's own host, reached from **Projects** with **Open
console**. Its rail is about your product: **Organizations** (your customers), their
users, roles, sign-in, apps and keys. Nothing on it is about your Cbox workspace.

The topbar says where you are and how to get back:

```
← Acme / Production [Live] / Acting organization ▾
```

The first crumb names your workspace and goes back to its **Projects** page. **My
account** and **Switch user** in the account menu open on the workspace host, because
your own sign-in belongs to the workspace, not to the environment.

## Where you land after signing in

A workspace member lands where they work:

- If exactly one active environment is reachable and your built-in role may administer
  environments (Owner, Admin or Developer), you go straight into that environment's
  console. It is the same signed handoff **Open console** on Projects uses.
- Otherwise you land on **Projects**, which lists every environment with **Open console**
  beside it.

The first crumb in an environment console links to Projects, not to this landing, or it
would send you straight back into the environment.

## Related

- [Unified identity](unified-identity.md): the design record for why the separate
  account row was removed rather than kept alongside.
- [Keys](../guides/keys.md): the keys each console issues.
- [Integrate your app](../getting-started/integrate-your-app.md): registering an app
  inside an environment.
