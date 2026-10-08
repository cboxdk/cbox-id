---
title: Languages
weight: 90
description: Which languages the sign-in pages, consent screen, Admin Portal and their emails are shown in, how the language is chosen for each visitor, and how to narrow or change the default.
---

# Languages

The pages **your users and your customers' IT admins** see can be shown in six
languages:

| Code | Language |
|---|---|
| `en` | English |
| `da` | Dansk |
| `de` | Deutsch |
| `sv` | Svenska |
| `nb` | Norsk bokmål |
| `fr` | Français |

That covers sign-in, sign-up, two-factor and step-up prompts, password reset,
invitations and email confirmation, the OAuth consent screen and organization picker,
the Admin Portal, the error pages, and the emails those flows send.

The **admin console stays in English**, whatever language your browser prefers. So do
the device-approval and "confirm it's you" screens, which sit inside the console.

## How a visitor's language is chosen

Each sign-in page asks these sources in order. The first one that names a language
switched on for the environment wins:

1. **`ui_locales` from your app.** OpenID Connect lets the app that sends someone to
   sign in say which languages it wants, most preferred first, separated by spaces:
   `/oauth/authorize?…&ui_locales=da en`. The choice lasts for the whole sign-in, so
   the MFA prompt and the consent screen after it stay in that language.
2. **The visitor's own pick.** The language menu in the footer of every sign-in page
   remembers the choice in a cookie for a year. Picking a language there also overrides
   `ui_locales` for the rest of that sign-in.
3. **The browser's language list** (`Accept-Language`), in the browser's own order.
   `da-DK` counts as Danish, and `no` counts as Norwegian Bokmål.
4. **The environment's default language.**

A language that is not on the list above is skipped, and so is a supported language that
the environment has switched off. Either way the next source is asked.

There is no per-user language setting. The cookie is what remembers someone's choice,
because most people use the menu before they have signed in.

## Which language emails are sent in

- **Emails a person asks for themselves**, such as a sign-in link, a password reset or a
  sign-up confirmation, use the language of the page they asked from.
- **Emails an administrator sends**, such as an invitation, a password they set or a
  reset they triggered, use the environment's default language. The console the
  administrator works in says nothing about the reader's language. The page the email
  links to then follows the reader's own browser.

Emails and error pages carry your deployment's product name (`CBOX_ID_BRAND_NAME`), not
"Cbox ID".

## Changing the default and the list

**For the whole deployment**, set two environment variables. See
[environment variables](../configuration/environment-variables.md#languages).

```dotenv
CBOX_ID_DEFAULT_LOCALE=da
CBOX_ID_LOCALES=da,en,sv,nb
```

**For one environment**, set `default_locale` and `enabled_locales` in that
environment's settings. These are stored with its sign-in appearance and override the
deployment's values:

```json
{ "default_locale": "de", "enabled_locales": ["de", "en", "fr"] }
```

The console has no screen for these two settings yet. Write them the same way as other
environment settings, for example from `php artisan tinker`:

```php
$environment = \Cbox\Id\Organization\Models\Environment::find('…');
$environment->settings = [...$environment->settings, 'default_locale' => 'de', 'enabled_locales' => ['de', 'en', 'fr']];
$environment->save();
```

The default is always kept inside the list. If you name a default that the list leaves
out, the first language in the list becomes the default. If only one language is left
on, the language menu is hidden.

## For developers: adding or changing text

The text lives in `lang/<code>/*.php`, one file per group: `hosted` (the shared page
frame), `auth`, `oauth`, `portal`, `mail` and `errors`. English is the source:

1. Add or change the key in `lang/en/…`.
2. Run `php artisan i18n:types`. This regenerates `resources/js/i18n/keys.ts`, so React
   pages can only ask for keys that exist in English. CI fails if that file is out of date.
3. Add the same key, with the same `:placeholders`, to the other five languages.
   `LangParityTest` fails if any language is missing a key, has an extra key, or changes
   a placeholder.

In React, use `useTranslator()` from `@/i18n`. It gives you `t(key, params)` for text,
`rich(key, { name: <b>…</b> })` when a placeholder is a React element, and
`choice(key, count)` for two-form plurals. A page receives only its own group's text.

## Danish terminology

The Danish text (`lang/da`) is written the way a Danish SaaS product would write it, not
word for word from English. Use these terms, so the same thing has the same name on every
page and in every email:

| English | Danish | Note |
|---|---|---|
| sign in (verb) | log ind | "Log ind", "Log ind med adgangsnøgle", "logge ind" |
| sign-in (noun) | login | "Tilbage til login", "Gennemfør login" |
| sign-in link, magic link | loginlink | One word |
| sign out | log ud | |
| password | adgangskode | Never "password" |
| passkey | adgangsnøgle | As Apple and Google say it |
| access key (AWS) | access key | Kept in English, so it is not confused with a passkey |
| two-factor verification | totrinsbekræftelse | The form Google and Microsoft use in Danish |
| authenticator app | godkendelsesapp | |
| recovery code | gendannelseskode | |
| verify, confirm | bekræft | |
| check (running text) | tjek | "Tjek din indbakke" |
| check (a button that runs a check) | kontroller | "Kontroller DNS", "Kontroller og upload" |
| email | e-mail, e-mailadresse | "Arbejdsmail" for a work email field |
| account | konto | |
| organization | organisation | |
| workspace | arbejdsområde | Never "workspace" |
| environment | miljø | |
| identity provider | identitetsudbyder | |
| single sign-on, SSO | single sign-on, SSO | Kept in English |
| directory sync | katalogsynkronisering | |
| directory | katalog | |
| log streams | logstreaming | The feature; the targets are "destinationer" |
| audit log | revisionslog | Never "audit-log" |
| tamper-evident | der ikke kan ændres ubemærket | |
| API key | API-nøgle | |
| token, bearer token | token, bearer-token | "et token", "tokenet" |
| app (the one being authorized) | app | "applikation" only where the identity provider's own screen says application |
| authorize (consent) | giv adgang | Permission lines are verbs after "Dette giver … lov til at": "Se dit navn" |
| Cancel | Annuller | |
| Optional | Valgfrit | "(valgfri)" after a common-gender noun, as in "Signeringsnøgle (valgfri)" |

**Address and tone.** Always "du". In the Admin Portal and the mails to a customer's IT
contact, the reader is "du" and the organization's things are "jeres" ("jeres
identitetsudbyder"). On the sign-in pages the person's employer is "din organisation".

**Protocol words and identity provider labels stay as they are.** "Entity ID", "ACS URL",
"Client secret", "Single sign-on URL", "Trust policy" and every button or field name in
an identity provider's own admin screens are written exactly as that screen shows them.
Only the sentences around them are translated.

**Writing conventions.**

- Sentence case in headings and on buttons: "Sæt jeres organisation op", "Opret arbejdsområde".
- Imperatives without an accent: "Eksporter", "Filtrer", "Kontroller".
- No comma before a restrictive subclause ("den enhed der venter", "en URL der starter med
  https://"). A comma after a subclause that opens a sentence is kept ("Hvis du ikke har
  bedt om det, kan du …"), as is the comma between two main clauses ("Vent lidt, og prøv igen").
- Quotation marks are “ ”, never « » or » «. The dash between clauses is a spaced en dash
  ( – ).
- "én" with an accent when it means the number one ("kan kun bruges én gang").
- Plurals use both forms: "om :count sekund|om :count sekunder", "om :count dag|om :count dage".
- A date in running text takes "den": "udløber den :date".

## Related

- [Admin Portal](admin-portal.md) — the hosted pages an IT admin opens in their own language.
- [For your customers' IT admins](../for-it-admins/_index.md) — what those IT admins read.
- [Enterprise SSO](single-sign-on.md) and [Social login](social-sign-in.md) — the sign-in pages these languages apply to.
