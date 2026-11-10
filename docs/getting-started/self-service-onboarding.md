---
title: Start with your app and your users
---

# Start with your app and your users

Start on Free without a credit card or billing details. Login, customer SSO, SCIM and
audit are included; plan allowances limit usage and capacity. Signals has included
usage, with additional capacity available through an explicit upgrade. Creating an
account does not authorize payment or automatically start a paid subscription.
During early access, product admission may still require an invitation.

## Your team and your customers are different scopes

Your **organization** is the team managing Cbox ID. A **project** is your product; its
**environments** separate identity data and configuration. A **customer organization**
is a tenant inside that product. Do not invite your customers into your administrative
organization to let them use your app.

Individuals, your own staff and customer organizations can use the same product.
One person can belong to several organizations with different roles. Your application
must check the current organization and permission on every protected request. Cbox
provides identity and membership; it cannot infer which business records belong to
which tenant. An organization switch needs a newly verified access context.

## Get a login working first

Open your project's environment and choose **Get started**. Pick the framework,
create an app, register its exact callback and logout addresses, and follow the
quickstart. Keep confidential client credentials on the server. Test login and logout
with a non-production account before connecting real users.

The checklist observes an issued person token or session participation. That confirms
an identity milestone, not your app's entire integration. Also verify local account
mapping, session handling, logout, token revocation and denial of another tenant's data.
A configured HTTPS redirect alone is not proof of production readiness.

## Already have users?

Keep local user IDs, relationships and existing tenant memberships. Sanctum manages
sessions/API tokens; Passport is an OAuth server. Neither is the user database itself.
You can change the login entry point while retaining those existing responsibilities.
Moving Passport's clients, scopes and tokens is a separate transition.

Use a trusted mapping or an authenticated account-linking flow. Never merge accounts
solely because email addresses match. For gradual migration, **Legacy login** can call
your approved signed HTTP endpoint on the first login of a not-yet-migrated identity.
Your endpoint must apply the old account's status and security policy, not just check
a password. Plan how existing MFA is preserved or replaced, and rehearse recovery.
Once migrated, subsequent login is verified by Cbox ID; the old password does not
remain a fallback. Check the current SDK's supported PHP/Laravel versions first.

## Let a customer use their workplace login

**Single sign-on (SSO)** lets a customer's employees sign in using their existing
Microsoft Entra, Okta or other company account. Configure the connection inside that
customer organization. Customer IT supplies its provider settings; you verify the
connection before requiring it. Other customers can retain ordinary login.

This is different from your own team's access to the Cbox management console.
A verified email domain alone must not grant organization membership or admin rights.

## Let customer IT manage joiners and leavers

**Directory provisioning (SCIM)** lets customer IT add and remove memberships from its
own directory. It manages access; it does not log people in. SSO and SCIM are independent
choices. Test both adding a member and removing access, including sessions/API access
in your app. Removing one customer membership must not erase unrelated memberships.

## Understand audit and detection

Audit tells you what happened: identity and access changes inside Cbox are recorded.
Application actions need their own events. Security detection uses available signals
to identify suspicious activity; an audit record is not itself breach detection.

Review supported SDK events, identifiers and retention before enabling application
signals. Never send passwords, tokens or raw request bodies. Included quota does not
mean unlimited usage or that collection and all detectors are already active.
Check actual coverage, freshness and policies before relying on an assessment.

## Get help without losing your place

Use the in-product Assistant for a guide or your current setup status. It should
read only environments you can access and cite the relevant documentation. If you
need a person, continue through the configured human-support handoff. An incomplete
or unavailable status must not be represented as successful setup.

When onboarding emails are enabled, they should refer to the specific project's next
step and stop when that shared goal is completed, including by a teammate. Product
help and tips are separate from necessary account/security mail and follow their
communication preferences. Upgrading remains a deliberate action.
