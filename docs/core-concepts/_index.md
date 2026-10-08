---
title: Concepts
weight: 20
description: The model behind Cbox ID — workspace, project, environment, organizations and users; the hosts and planes; keys versus delegated tokens; actions; approvals; and the two audit records.
---

# Concepts

Six pages that explain how Cbox ID is put together. Read the first one before anything
else; the rest in any order, when you need them.

- [Workspaces & organizations](workspaces-and-organizations.md) — **start here.** Workspace →
  project → environment → organizations → users, your workspace versus your customers'
  organizations, and which console you are in.
- [Planes and hosts](planes-and-hosts.md) — the platform root versus an environment's own
  host, and the four planes of the management API (environment, workspace, account,
  platform) that live on them.
- [Keys and tokens](keys-and-tokens.md) — secret, publishable, agent, member and workspace
  keys, app credentials, and the tokens a person delegates; which to reach for.
- [Actions](actions.md) — one action, four doors: every change is the same action from the
  console, REST, MCP and the `cbox` CLI.
- [Approvals](approvals.md) — when a person has to say yes first, and where they say it.
- [Audit log and App audit logs](audit.md) — the record Cbox ID keeps of itself versus the
  events your app sends about its customers.

Design records, for when you want the reasoning:

- [Unified identity](unified-identity.md) — one person, one identity, across the planes.
- [Console modules](modules.md) — the in-tree capability areas and why they register
  themselves the way an external plugin would.
- [Entitlements](entitlements.md) — what an unset capability flag means.
