---
title: Accounts & organizations
weight: 5
description: The five layers, why the word "organization" appears at two of them, and how to tell which one you are looking at.
---

# Accounts & organizations

The single thing worth understanding before anything else in this product, because one
word does two jobs and nothing on screen tells you which one you have in front of you.

## The chain

```
Account  →  Project  →  Environment  →  Organization  →  Subject
(you, the       (one       (that product's   (one of YOUR      (a person who
 customer)    IdP product)  prod/sandbox)     customers' teams)  signs in)
```

Read left to right, it is one sentence: *you* own *products*, each product has *stages*,
each stage holds *your customers' teams*, and each team has *people*.

| Layer | What it is | Who it belongs to |
|---|---|---|
| **Account** | You. The billing customer, and the login umbrella your colleagues join. | Cbox ID |
| **Project** | One identity product you run. The plan and the environment allowance live here, so two products bill separately from one login. | You |
| **Environment** | A stage of that product — production, sandbox. A hard boundary: its own users, signing keys, issuer, branding and sign-in page. | Your project |
| **Organization** | One of your customers' companies or teams. An arbitrary-depth tree, so a group can hold companies and a company divisions. | Your environment |
| **Subject** | A person who signs in to your product. | Your environment's user pool |

## The word that does two jobs

**Underneath, an account and an organization are the same kind of row.** There used to be
a separate `Account` model above the organization, carrying the same name and status with
its own members and its own role vocabulary — two rows for one customer, and therefore two
answers to "who may act for them". They kept disagreeing, so the account row was removed
rather than reconciled. A customer *is* an organization, living in the platform-root
environment, and their people are ordinary subjects holding a membership of it.

That merge is why one login gets you everything, and it is the right shape. The cost is
that the word now appears at two altitudes:

- In the **platform root** it names **a customer of Cbox ID** — your account.
- In **your own environment** it names **one of your customers' teams**.

**The tell: an account owns projects. A tenant organization does not.** If the thing in
front of you has projects, environments and a bill, it is an account. If it has members,
roles and SSO connections and lives inside one environment, it is one of your customers.

The console says **Account** wherever the meaning is "the customer" — Account settings,
Administrators, the account management API — and **Organization** wherever the meaning is
"one of your customers". The database still calls both an `organizations` row; only the
word you read changes.

## Which one you are looking at, on screen

You administer the two through two doors, and the rail tells you which you came through:

**The account console** (`cboxid.com`) — signed in as yourself. It has *Identity platform*
with **Projects**, **Administrators**, **Account settings** and **Billing**. Everything
here is about what *you* own and what *you* pay for.

**An environment console** (`/admin` on that environment's host) — entered by opening an
environment from your project. It has **Tenants → Organizations**, which are *your
customers*, plus their users, roles, SSO connections and apps. Nothing here is about your
Cbox ID account.

## If you have used another identity platform

Every one of them has these layers. They just do not reuse a word across two of them:

| Cbox ID | WorkOS | Auth0 | Clerk |
|---|---|---|---|
| Account | your WorkOS team | your Auth0 account | your Clerk account |
| Project | — | — | Application |
| Environment | Environment | **Tenant** | Instance (dev/prod) |
| Organization | **Organization** | **Organization** | **Organization** |
| Subject | User | User | User |

So if you arrived expecting *organizations* to mean your users' teams: you were right, and
that is exactly what they are inside an environment. The layer above them is your account.

## Related

- [Unified identity](unified-identity.md) — the design record for why the account row was
  removed rather than kept alongside.
- [Integrate your app](../getting-started/integrate-your-app.md) — registering an app
  inside an environment.
