---
title: Admin guides
weight: 25
description: Task-oriented guides for the people who administer an organization or an environment in the Cbox ID console — one per console page, linked from the "?" beside each page title.
---

# Admin guides

These are for the **administrator** — the person in the console who connects an
identity provider, invites the team, registers apps, wires up an agent and answers to an
auditor. Building *against* Cbox ID lives in the framework docs that ship with
[`cboxdk/laravel-id`](https://github.com/cboxdk/laravel-id/blob/main/docs/index.md),
and deploying the platform lives under [operations](../operations/_index.md).

Each guide is the long form of the **"?" beside a page title in the console**. The
console explains itself in two or three sentences; when that is not enough, the
"Read the guide" link lands here. The link text below is the console's own name for the
page.

If you are a customer's IT admin who was sent a setup link, you want
[For your customers' IT admins](../for-it-admins/_index.md) instead.

## People and access

- [Members](members.md) — invite the team, send them back to your app, hand ownership over.
- [Roles](roles.md) — decide who can do what, including the Admins & support roles held across an environment.
- [Permissions](permissions.md) — what a role is made of, written here rather than in each app.
- [Fine-grained authorization](fine-grained-authorization.md) — who may do what to which of your app's own resources: documents in folders, owners, editors and viewers, with inheritance.
- [Access reviews](access-reviews.md) — certify who still needs what.
- [Role conflicts](role-conflicts.md) — roles that must never be combined.
- [Support access](support-access.md) — signing in to one of your apps as one of its users, for a reason and at most an hour.

## Sign-in

- [Enterprise SSO](single-sign-on.md) — people sign in with the company account they already have, and the email Domains that route them there.
- [Directory Sync](sync-users-in.md) — the company's directory creates and deactivates people for you, over SCIM.
- [HR system sync](hris.md) — Workday, BambooHR, Rippling, HiBob or Personio: accounts follow employment, departments become groups.
- [Admin Portal](admin-portal.md) — hand Enterprise SSO, Directory Sync, Domains, Log streams and more to the customer's own IT admin with a single-use link.
- [Social login](social-sign-in.md) — Google, GitHub, Apple and the rest, and how connecting one to an existing account works.
- [Radar](radar.md) — allow, challenge or block each sign-in and sign-up: credential stuffing, impossible travel, new devices, throwaway addresses, your own rules and lists, monitor before you enforce.
- [SMS as a second factor](sms-mfa.md) — text-message codes: when to turn them on, which countries, and what they do not protect against.
- [Outbound provisioning](sync-users-out.md) — push your people into your other SaaS products.
- [Custom domains](custom-domains.md) — serve an environment's sign-in on your own address, such as `login.example.com`.
- [Languages](languages.md) — which language sign-in, consent, the Admin Portal and their emails are shown in.
- [Sign in on TVs and devices](sign-in-on-tvs-and-devices.md) — a code and a QR code on the screen, approved on a phone: the device grant end to end.

## Building on an environment

- [Applications](apps-and-api-keys.md) — register the apps people sign in to.
- [APIs](apis.md) — the services your apps call, and which app may be given which of their scopes.
- [API keys](keys.md) — Secret, Workspace and Publishable keys, for your own code.
- [My API keys and Member API keys](api-keys.md) — keys people create for your apps' APIs, and how admins see and revoke them.
- [Webhooks](webhooks.md) — get told when something happens.
- [Hooks](inline-hooks.md) — have a say while it happens.
- [Feature flags](feature-flags.md) — turn a feature on for named customers, people or a share of everyone, read in the token or from the API.
- [Token vault](token-vault.md) — credentials your apps use elsewhere.
- [Pipes](pipes.md) — let people connect their GitHub, Google or Slack account, and call those APIs as them.

## Agents

- [Agents and MCP](agents-and-mcp.md) — connect Claude Code, Cursor or another MCP client to an environment or your whole workspace.
- [Step-up approvals](step-up-approvals.md) — make a key's dangerous calls wait for a person, and how an agent retries once approved.
- [Approvals](agent-approvals.md) — approving a request to act as you, and reviewing every pending request in an environment.
- [Trusted devices](trusted-devices.md) — a phone as the authenticator that answers those approvals.

## The record

- [Audit log](activity-log.md) — Cbox ID's own tamper-evident record of every change.
- [App audit logs](audit-logs.md) — your app's own audit events, per customer: send, validate, retain, export, and let each customer read theirs.
- [Log streams](log-streams.md) — mirror the Audit log into your SIEM as it is written.

## Getting a new organization running

A sensible order, the first time:

1. [Roles](roles.md), then [Members](members.md).
2. [Applications](apps-and-api-keys.md) — the first app people will sign in to.
3. [Enterprise SSO](single-sign-on.md) and [Directory Sync](sync-users-in.md), or an
   [Admin Portal](admin-portal.md) link so the customer's IT admin does both.
4. [Log streams](log-streams.md), if a security team wants the trail in its own tools.
