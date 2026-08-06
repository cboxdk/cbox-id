---
title: Agent approvals
weight: 120
description: What it means when an app or AI agent asks to act on your behalf, how to check the request is really yours, and when to deny.
---

# Agent approvals

**Console page:** Overview › Agent approvals

Sometimes an app or an AI agent needs your go-ahead to act as you, and cannot ask
on the screen in front of you — it is running on a server, in a terminal, or on a
device with no browser. It asks here instead, and waits.

Each request names the app, and lists exactly what it is asking to be allowed to
do. Nothing happens until you approve; deny, and the app is told no.

## Before you approve

**Did you start this?** That is the whole question. A request you did not initiate,
arriving out of nowhere, is someone trying to get you to authorise something on
their behalf — deny it. This is the same reflex as an unexpected two-factor prompt.

**Does the code match?** Where the request shows a code, check it against the one
displayed on the device or terminal that asked. If they differ, you are not
approving what you think you are.

**Do the permissions match the task?** A request asking for more than the thing you
were doing needs an explanation before it gets an approval.

## Things worth knowing

- **Approval is tied to you.** It is your consent, recorded against your account —
  approving another person's request is not possible, and a request that does not
  belong to you is refused rather than silently approved.
- **Requests expire.** If one has been sitting here a while, deny it and start over
  rather than approving something stale.
- **Every decision is recorded** in the [activity log](activity-log.md), including
  denials.

## Related

- [Token vault](token-vault.md) — the credentials agents use once approved.
- [Apps & API keys](apps-and-api-keys.md).
