---
title: SMS as a second factor
weight: 36
description: Let people receive sign-in codes by text message — off until you turn it on, limited to the countries you choose — and what SMS does and does not protect against.
---

# SMS as a second factor

**Console page (environment console):** Authentication › Authentication policy › Text-message codes

People can add a phone number in **My account › Security** and get a code by text message
as the second step when they sign in, next to an authenticator app, a passkey and recovery
codes. It is **off in every environment until you turn it on**, and it sends only to the
countries you list.

## Should you turn it on?

Turn it on when some of your people cannot use an authenticator app or a passkey: no
smartphone they can install apps on, a shared or locked-down device, or a workforce that will
otherwise not use a second factor at all. A texted code is much better than a password alone.

Leave it off if everyone can use an authenticator app or a passkey, because SMS is the
weakest second factor on offer:

- **SIM swap and port-out.** Whoever controls the phone number receives the code. Carriers
  can be talked, bribed or tricked into moving a number to another SIM or another carrier.
- **Interception.** Weaknesses in the phone network (SS7) let a well-resourced attacker read
  texts in transit, and malware on the phone can read them too.
- **Phishing.** A fake sign-in page can ask for the code and use it at once. A passkey
  cannot be phished like this; an authenticator code can, and so can a texted one.
- **Cost and abuse.** Every text costs money. Attackers drive "send me a code" forms at
  premium-rate numbers they earn money from — SMS pumping. See [Costs and limits](#costs-and-limits).

A session completed with a texted code is recorded with the sign-in method `sms` (not
`otp`), so an app that needs a stronger second factor can tell the difference and ask for
one.

## Turn it on

1. Make sure the deployment has an SMS provider. The operator sets `CBOX_ID_SMS_DRIVER`
   (Twilio, MessageBird, Bird or 46elks) and its credentials — see
   [Text messages (SMS)](../configuration/environment-variables.md#text-messages-sms).
2. In the environment console, open **Authentication › Authentication policy** and find
   **Text-message codes**.
3. Add the **countries** your people's phone numbers are in. Only numbers in those countries
   can be added or texted. Keep the list short: each country is a place you pay to send texts
   to, and the place an attacker would pump.
4. Tick **Accept text-message codes as a second factor** and save.

The same setting is in the management API (`GET` / `PATCH /api/v1/sign-in/sms`, actions
`signin.sms.get` and `signin.sms.update`, scopes `signin:read` / `signin:write`) and as MCP
tools. Saving SMS as on with no country is refused.

If the deployment has its own country list (`CBOX_ID_SMS_ALLOWED_COUNTRIES`), a country
outside it is never texted even when you list it; the console warns you.

## Administrators

**Administrators need an authenticator app or a passkey too** is on by default, and we
recommend keeping it on. An owner or admin of an organization:

- can add a phone number only after setting up an authenticator app or a passkey, so SMS is
  their backup, never their only second factor;
- who ends up with SMS as their only factor — promoted after adding it, or after removing
  their app — can still sign in with it, but is asked to set up a stronger factor before
  they can carry on, even where a second factor is otherwise optional.

Note what this does not do: an administrator who has both can still choose SMS at sign-in.
Somebody who has taken over their phone number can therefore get past the second step. If
that matters for your administrators, leave SMS off.

## What people see

- **My account › Security** gets a **Text-message codes** panel. People type their number
  (international format, such as `+45 12 34 56 78`, works anywhere), receive a code and
  enter it. The first second factor anybody sets up also gives them recovery codes.
- At sign-in, the second-factor page offers **Text me a code**. The text is sent only when
  they press it, never just by opening the page. The page is in the person's language
  (English, Danish, German, French, Norwegian or Swedish), and so is the text.
- The number is shown masked everywhere after it is added: `+45 ******78`.

Apps using the embedded sign-in (a publishable key) get `factors` with the `mfa_required`
answer and a call to send the text — see [Integrate your app](../getting-started/integrate-your-app.md).

## Removing a phone number

- The person: **My account › Security › Remove phone number** (asks for their password).
- An administrator: **Users › *a person* › Remove phone number** — for a lost or ported
  number. Their authenticator app, passkeys and recovery codes stay. **Reset 2FA** removes
  every factor, the phone number included.
- The API: `DELETE /api/v1/users/{id}/mfa/sms` (`users.mfa.sms.remove`), or for yourself
  `DELETE /api/v1/me/mfa/sms` (`account.mfa.sms.remove`).

Each removal is recorded as `user.mfa_sms_removed`, naming who did it.

## Turning it off again

Turning SMS off, or removing a country, takes effect at once: no more texts are sent, codes
already sent stop working, and nobody can add a number. People keep their numbers on file
in case you turn it back on. Somebody whose only second factor was SMS is then signed in
with their password alone; if your policy requires a second factor, they are asked to set up
another one.

## Costs and limits

Every text passes these checks before the provider is called. When one says no, nothing is
sent and nothing is charged:

| Check | Default |
|---|---|
| The number's country is on your list (and the deployment's) | — |
| One text per number every 30 seconds | `CBOX_ID_SMS_COOLDOWN_SECONDS` |
| 10 texts per number per day | `CBOX_ID_SMS_PER_NUMBER_PER_DAY` |
| 10 texts per network address per hour | `CBOX_ID_SMS_PER_IP_PER_HOUR` |
| 1,000 texts per environment per day | `CBOX_ID_SMS_PER_ENVIRONMENT_PER_DAY` |
| 5,000 texts for the whole deployment per day | `CBOX_ID_SMS_DAILY_CAP` |

The usual limits on codes apply as well: a code lasts 5 minutes and allows 5 tries, and a
sign-in allows 5 attempts across all its second-factor methods. Satellite and other
non-geographic number ranges are always refused.

Every text is in the audit log: `sms.sent` (with the provider's message id), `sms.refused`
(with the reason) or `sms.failed`, with the number masked, its country and the network
address that asked. A run of refusals for one country, or many numbers from one address, is
what pumping looks like.

Set spend limits and geo-permissions with your SMS provider too. They are the last line of
defence and do not depend on this platform being configured correctly.

## How the number is stored

The phone number is encrypted at rest, bound to the person it belongs to, and decrypted only
to send a text. The codes are stored only as keyed hashes. Neither the audit log nor the
table of sent codes contains the number.
