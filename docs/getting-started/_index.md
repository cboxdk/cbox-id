---
title: Getting started
weight: 10
description: Building on Cbox ID beyond the first sign-in — register apps, run your tenancy from your backend, organizations, CLIs, customer API keys and enterprise self-serve.
---

# Getting started

The [quickstarts](../quickstarts/_index.md) get one framework to a first sign-in. These
pages are the next layer: the decisions behind that first app, and the things most
products add next.

## Your app

- [Integrate your app](integrate-your-app.md) — where a `client_id` comes from: register an
  application, copy its credentials, find your issuer, point an SDK at them; publishable
  keys and drawing your own sign-in form.
- [Organizations in your app](organizations-in-your-app.md) — ask for an organization, let
  people pick, switch or create one, and let strangers sign themselves up.
- [Sign in from a CLI](sign-in-from-a-cli.md) — the device grant, for a terminal, a CI job
  or anything without a browser of its own.
- [Let your customers create API keys](let-your-customers-create-api-keys.md) — keys for
  your own app's API, created on a hosted page and verified with your app's credentials.

## Your backend

- [Run your tenancy from your backend](management-api.md) — the environment management
  API by task: organizations with owners, members, invitations with your app's roles,
  admin & support roles, apps, APIs and support sessions.
- [Enterprise self-serve](enterprise-self-serve.md) — hand a customer's IT admin an Admin
  Portal link so they set up Enterprise SSO and Directory Sync themselves.

## The console

- [Finding your way](finding-your-way.md) — where everything is in the environment
  console, and the clicks each common task takes: social login, SSO for a customer,
  Directory Sync, a user's sessions and MFA, roles, apps and agents.
- [Screens](screens.md) — the workspace, organization and environment consoles and the
  sign-in surface, area by area.

## Running Cbox ID yourself

- [Installation & first run](installation.md) — set up the app, create the first platform
  operator, and provision your first environment and organization. The short version is
  the [self-hosting quickstart](../self-hosting/quickstart.md); for production hardening,
  see [Deployment](../operations/deployment.md).
