---
title: Authenticator apps
weight: 33
description: Add independent TOTP apps to your account and remove one without affecting the others.
---

# Authenticator apps

Open **My account → Authenticator apps → Add authenticator app**. Give the app a
recognisable name, such as “Personal phone”, then show the QR code. Scan it with the
app you are adding and enter its six-digit code to finish. Complete setup within ten
minutes, or cancel and start again.

Repeat these steps for each app. Every setup creates a different key. Scanning the
same QR code into several apps copies one key, so those copies cannot be removed
independently. You can have up to ten apps, including unfinished setups. Expired
setups are cleared when you start another.

Each confirmed app appears by name. **Rename** changes its label. **Remove** stops
accepting its codes, after confirming it is you; your other apps keep working. Add a
replacement before removing your last app. If you have lost every app, use a recovery
code to sign in or ask your administrator for an account recovery.

Recovery codes belong to your account. Adding or removing an app does not change
them. Store the initial set safely. **Generate codes** or **Regenerate codes** creates
a new set and invalidates the previous set.

These are standard TOTP codes. A Cbox ID phone connection for approving requests is a
separate enrolment under [Trusted devices](trusted-devices.md). Removing one of those
connections does not remove a TOTP key saved on the phone.

Existing TOTP keys keep working after the server upgrade and appear as
“Authenticator app” until renamed. The server upgrade requires the named-credential
version of `cboxdk/laravel-id` and its database migration; rolling back the migration
is refused while any person has multiple credentials.
