---
title: Support access
weight: 15
description: Sign in to one of your apps as one of its users, for a stated reason and at most an hour, with every token naming who is really there and the organization's activity log recording it.
---

# Support access

**Console page (environment console):** People › Users › *a person* › Support access

Support access lets an environment administrator see an app exactly as one of its users
does. You sign in to the app **as them**, in one of their organizations, for a reason you
write down and for at most an hour. It is the answer to "the invoice totals look wrong on
my screen" when the only way to know what is on their screen is to look.

The person is not asked, which is why it is bounded this tightly:

- **Only your own apps.** The app must be first-party and owned by the environment, and
  sign people in with a redirect (the authorization code grant). A third-party app, or an
  app one organization registered for itself, never receives a person's data on somebody
  else's say-so. Those apps are not offered, and the console refuses them if asked.
- **Only an organization they are an active member of.** Not one they were invited to and
  have not joined, and not one they are suspended from.
- **A reason, always.** It is shown to the organization.
- **At most an hour.** The deployment's `CBOX_ID_SUPPORT_SESSION_MAX_TTL` (seconds) can
  lower that; nothing raises it. The console offers nothing longer than the maximum.
- **A fresh password.** Starting one asks you to confirm your password, like setting
  somebody's password does.

## Start one

1. Open the person under **People › Users**.
2. Under **Support access**, pick the app, the organization, a reason and how long.
3. Press **Sign in to *app* as *person***.

Your browser goes to the app, and the app signs in the way it always does. You arrive
signed in as the person. You stay signed in to the console, in the tab you came from.

**If the app already has you signed in as yourself,** sign out of the app first. The app
decides whether to start a new sign-in, and one that finds you already signed in will not.

## While it is open

- **The app knows it is you.** Every token it receives carries `act: {"sub": "<your id>"}`
  (RFC 8693), and so does the ID token. The person's own id is still the subject, so the
  app shows what they would see.
- **No way to stay signed in.** The app gets no refresh token, even if it asks for one,
  and no token outlives the session.
- **One app.** Supporting the same person in two apps is two sessions, each recorded.
- **One organization — the one you chose.** The app's sign-in is answered with a code for
  that organization, whatever picker or hint it asks for (`prompt=select_organization`,
  `organization_hint`). If the app names a *different* organization with `organization`,
  or asks to create one (`prompt=create_organization`), it gets `error=access_denied`
  with a sentence saying why, and the session stays open for a sign-in it can answer.

The session is listed on the person's page and on the organization's page in the
environment console, with **End now**.

## End one

**End now** stops it at once: no new sign-in can use it, and every token it issued is
revoked, so an app that checks tokens with the introspection endpoint sees them as
inactive straight away. An app that checks tokens itself (validating the signature
offline) keeps accepting one until it expires, which is never later than the session's
own end.

A session also ends by itself when its time is up.

## Who sees it

- **The organization.** Its activity log records `support_session.started` and
  `support_session.ended`, with your name, the person, the app and the reason, and its
  webhooks receive `support_session.started`.
- **The person.** Their own **Sessions & activity** page shows that support signed in to
  an app as them, which app, and why.
- **The environment.** The environment's activity log records the same two entries.

## What an app should do with `act`

A token with `act` means somebody else is there as this user. Show it (a banner is
enough), and refuse what a support person must never do on a customer's behalf:
changing their password, moving money, accepting terms. Cbox ID says who is there; the app
decides what they may do.

## Staff who are not administrators

An app can let its own support people start sessions without making them environment
administrators. It declares a `support:impersonate` permission in its manifest, and you
put that permission in a [staff role](roles.md#staff-roles) for the app. The framework then
lets holders of that role start a session for that app only. See the framework's
[staff roles & support access](https://github.com/cboxdk/laravel-id/blob/main/docs/core-concepts/staff-and-support-access.md).

## Not the same as "Support impersonation"

The **Support impersonation** panel further down the same page signs you in to **this
console** as the person, to see their account settings. Support access signs you in to
**one of your apps** as them. Each is recorded separately.

## Related

- [Roles](roles.md) — staff roles, and the `support:impersonate` permission.
- [Activity log](activity-log.md) — where the organization reads the session back.
- The framework's [threat model for support sessions](https://github.com/cboxdk/laravel-id/blob/main/docs/security/support-sessions.md).
