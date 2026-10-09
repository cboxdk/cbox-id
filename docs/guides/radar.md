---
title: Radar
weight: 45
description: Adaptive protection at sign-in and sign-up — credential stuffing, bot-like velocity, impossible travel, new devices, anonymising networks and throwaway addresses — with your own rules, allow and deny lists, and a monitor/enforce switch per environment.
---

# Radar

**Console page:** Authentication › Radar in an environment console, with three tabs —
**Decisions**, **Rules** and **Allow & deny lists**.

Radar judges every sign-in and every sign-up in an environment and decides one of three
things:

- **Allow** — the attempt goes ahead.
- **Challenge** — the person proves it is them before a session exists. On a password
  sign-in that is their authenticator app if they have one, and a one-time code emailed to
  them if they do not. On a sign-up it is the CAPTCHA (Cloudflare Turnstile) when one is
  configured, and otherwise a one-time code emailed to the address they signed up with,
  before their first session. A passkey or a magic link already proves possession of
  something, so a challenge adds no step to those.
- **Block** — the attempt is refused with one generic sentence, *"We could not process this
  request. Please try again later."*, in the visitor's language. The sentence never says
  which rule fired: telling an attacker that is telling them what to change.

Every verdict is recorded — with the rule that decided it, every other rule that fired, the
reasons, and the facts it was decided on — and shown on the **Decisions** tab.

## Monitor first, then enforce

Each environment has a mode:

- **Monitor** — every verdict is recorded and **none is acted on**. A blocked attempt goes
  ahead; the decision row says `monitor only`. This is what you read before you enforce.
- **Enforce** — blocks refuse and challenges ask for proof.

Until an environment chooses, it follows the deployment's default, `RISK_MODE` (which is
`monitor` unless the operator set it). Switching is a **critical** change: the console asks
for your password first, and over the API it needs the `radar:manage` scope — a key that
may only tune rules (`radar:write`) cannot turn protection off.

Run in monitor for at least a week of real traffic. Filter the Decisions tab by
**Verdict: Block** and **Challenge** and read who would have been stopped. If it is people
you know, fix the rule before you enforce.

## What Radar looks at

| Fact | Where it comes from |
| --- | --- |
| Country, network (ASN) and owner | IP intelligence — see [below](#ip-intelligence). Unknown without it. |
| Tor exit node | The local Tor exit list (`risk:refresh-tor`), and IP intelligence that reports it. |
| VPN, open proxy, hosting network | IP intelligence that reports them (MaxMind's paid Anonymous IP database, IPinfo's paid privacy data). |
| New device | The account's own history of browsers it has **successfully** signed in from. |
| Impossible travel | The distance and time since the account's last **successful** sign-in that could be placed on a map. |
| Velocity | Attempts per IP (last minute, last hour), different addresses tried per IP (last 10 minutes), failures per IP and per address (last hour), attempts per device (last hour). |
| Disposable email | A bundled list of throwaway mail providers, refreshable. |
| Risk score | The scored signals that came before Radar — IP reputation, honeypot, user agent, MX — see [Adaptive risk](../security/adaptive-risk.md). |

A fact Radar cannot establish is **unknown**, and an unknown fact matches no condition. With
no IP intelligence configured, a rule on country never fires — it does not challenge
everyone because nobody can be placed.

## The order Radar asks in

1. **Deny list.** A match blocks; nothing else is asked.
2. **Allow list.** A match allows; no rule is asked — built-in rules included.
3. **Your rules**, top to bottom. The first whose conditions **all** hold decides, `allow`
   included — which is how you carve an exception out of the built-in rules.
4. **Built-in rules**, all of them. The strictest action among those that fire decides.
5. Nothing matched: **allow**.

An address or IP on both lists is refused: deny wins.

The built-in rules are evaluated even when a list or one of your rules decided, and the
decision shows them under **triggered**. "Allowed by *Office network*, though *Credential
stuffing* fired" is exactly what you need to see after writing that rule.

## Built-in rules

| Rule | Fires when | Default |
| --- | --- | --- |
| Credential stuffing | 10 or more different addresses tried from one IP in 10 minutes (sign-in) | on, **block** |
| Bot-like velocity | 20 or more attempts from one IP in a minute | on, **block** |
| Repeated failures on one account | 10 or more failed sign-ins on one address in an hour, from anywhere | on, challenge |
| Impossible travel | travel faster than 900 km/h since the last successful sign-in (sign-in) | on, challenge |
| New device | a browser this account has never signed in from (sign-in) | **off**, challenge |
| Tor, VPN or open proxy | the address is one | on, challenge |
| Hosting or data centre network | the address belongs to one | **off**, challenge |
| Disposable email domain | a sign-up with a throwaway address | on, **block** |
| Risk score: reject | the risk score reached its reject threshold | on, **block** |
| Risk score: elevated | the risk score reached its challenge or step-up threshold | on, challenge |

Each can be switched off, given another action, and — where it counts — another threshold,
on the **Rules** tab or with `radar.settings.update`. *New device* and *Hosting network* start
off because under enforcement each would send a code to everybody on a new laptop or behind
a corporate egress; their facts are recorded anyway, so the Decisions tab shows what switching
them on would do.

The two *Risk score* rules are the behaviour the app had before Radar: a reject blocked, an
elevated score asked for a second factor. With nothing else configured, enforcing Radar does
what `RISK_MODE=enforce` did, plus the velocity, travel, network and disposable-address rules.

## Your own rules

A rule is **when every condition holds, then allow / challenge / block**, on sign-in, sign-up
or both. A condition compares one fact with a value:

```json
{
  "name": "Challenge sign-ins from outside the Nordics",
  "action": "challenge",
  "applies_to": "sign_in",
  "conditions": [
    { "field": "country", "operator": "not_in", "values": ["DK", "SE", "NO"] }
  ]
}
```

| Type | Fields | Operators |
| --- | --- | --- |
| text | `email`, `email_domain`, `user_agent`, `as_organization`, `method` | `eq`, `neq`, `in`, `not_in`, `contains`, `not_contains`, `starts_with`, `ends_with` |
| country | `country` (ISO 3166-1 alpha-2) | `eq`, `neq`, `in`, `not_in` |
| IP | `ip` | `eq`, `neq`, `in`, `not_in`, `in_cidr`, `not_in_cidr` |
| number | `asn`, `risk_score`, `travel_kmh`, `ip_attempts_1m`, `ip_attempts_1h`, `ip_distinct_emails_10m`, `ip_failures_1h`, `email_attempts_1h`, `email_failures_1h`, `device_attempts_1h` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in` |
| yes/no | `is_hosting`, `is_vpn`, `is_proxy`, `is_tor`, `disposable_email`, `new_device`, `impossible_travel` | `eq`, `neq` |

`value` is one value written as text (`"DK"`, `"20"`, `"true"`); `values` is a list, for `in`,
`not_in`, `in_cidr` and `not_in_cidr`. Text compares case-insensitively. Rules are checked
when they are saved — an operator a field does not take, a country that is not two letters, a
range that is not CIDR is refused with the reason. Nothing in a rule is ever executed: there is
no expression syntax, no regular expression, and evaluation is a handful of comparisons. An
environment holds at most 100 rules of up to 10 conditions each.

Some rules that work well:

- **Allow your office past velocity rules** — `ip in_cidr [203.0.113.0/24]` → allow, placed
  first. A load test from CI is the same shape with your runner's range or ASN.
- **Challenge outside where you operate** — `country not_in [DK, SE, NO]` → challenge.
- **Block a headless browser** — `user_agent contains headlesschrome` → block.
- **Challenge new devices for your admins only** — `email_domain eq yourcompany.com` and
  `new_device eq true` → challenge.

## Allow and deny lists

An entry is an IP address or CIDR range (IPv4 or IPv6), an email address, a mail domain
(which covers its subdomains), or a device — the 64-character device id a decision shows,
which the **Deny this device** button on a decision fills in for you. An entry may expire.
Use the allow list for your own networks and test accounts, not for a whole country: an allow
entry skips every rule, the ones that stop credential stuffing included.

A device entry recognises the device **cookie**. Somebody who clears their cookies is a new
device; deny their IP or their address too.

## IP intelligence

Off by default (`CBOX_ID_RADAR_IP_INTELLIGENCE=none`): nothing is looked up and nothing about
an address leaves the deployment. The operator can choose:

- **`maxmind`** — MaxMind database files on the deployment's disk, read locally; nothing is
  fetched while signing in. Download them under MaxMind's licence (GeoLite2 is free with an
  account and attribution; GeoIP2 is paid) and point at them:
  `CBOX_ID_RADAR_MAXMIND_CITY_DB` (country and location — GeoLite2-City),
  `CBOX_ID_RADAR_MAXMIND_ASN_DB` (network — GeoLite2-ASN) and optionally
  `CBOX_ID_RADAR_MAXMIND_ANONYMOUS_DB` (VPN, hosting, proxy and Tor — GeoIP2 Anonymous IP,
  paid). Keep them updated with MaxMind's `geoipupdate`; Cbox ID does not download them.
- **`ipinfo`** — IPinfo's API with your token (`CBOX_ID_RADAR_IPINFO_TOKEN`). One HTTPS request
  per address the cache has not seen, with a 1.5-second timeout. **The address is sent to
  IPinfo** — that is what choosing this driver means; say so in your privacy notice. VPN,
  proxy, Tor and hosting flags need IPinfo's paid privacy data.

Lookups are cached for a day (`CBOX_ID_RADAR_IP_CACHE_TTL`) under a keyed pseudonym of the
address. Private and reserved addresses are never looked up. A lookup that fails or times out
is "unknown" — it never blocks a sign-in. To use another source, bind your own
`App\Platform\Radar\IpIntelligence\IpIntelligence`.

## Throwaway mail domains

Radar ships a curated list of about 600 disposable mail providers, and the risk score's
`email.disposable` signal reads the same list. New providers appear weekly, so for real
coverage run `php artisan radar:refresh-disposable-domains`, which fetches the
community-maintained [disposable-email-domains](https://github.com/disposable-email-domains/disposable-email-domains)
blocklist (CC0) to `storage/app/radar/disposable-domains.txt` and merges it with the bundled
one. It is **not scheduled** for you — it is an outbound request, and whether the deployment
makes one is yours to decide; schedule it weekly if you want it. A response that is not a
domain list, or holds fewer than 100 domains, leaves the current list in place. Workers read
the list once per process, so restart them (or wait for the next deploy) after a refresh.

## Devices, privately

Radar recognises a browser with a **first-party cookie** (`cbox_device`): set by this host for
this host, HttpOnly, `SameSite=Lax`, Secure wherever the session cookie is, holding 128 random
bits and nothing derived from the person or the machine. It is set when somebody submits a
sign-in or a sign-up, as part of securing the sign-in they asked for — the category of cookie
consent rules treat as strictly necessary. There is no third-party script, no canvas or font
fingerprinting and no other storage.

For a client that keeps no cookies — an app on the Frontend API, a private window — the
fallback is a coarse **fingerprint**: the user agent with every version cut to its major
number, the `Sec-CH-UA` client hints the browser sends anyway, and its first language. Two
colleagues on the same browser and OS look alike; that is the right failure for "have we seen
this before?".

Devices are remembered only after a sign-in **succeeds** — through any door: password,
passkey, magic link, Enterprise SSO, social login, a completed challenge. Typing somebody's
address cannot add a device or a location to their history, and a failed attempt from the
other side of the world does not make their next real sign-in look like impossible travel.

## What is stored, and for how long

| Where | What | How long |
| --- | --- | --- |
| `risk_decisions` | Per attempt: verdict, mode, deciding rule, rules that fired, reasons, risk score, **country, ASN**, mail domain, keyed pseudonyms of the IP, the address and the device cookie, and the facts (counts, flags, travel speed). **Never** the IP, the address or the user agent. | `CBOX_ID_RISK_TRAIL_RETENTION_DAYS` (90) |
| `radar_devices` | Per account and browser: pseudonyms of the account, the device cookie and the fingerprint; the country and a point rounded to one decimal (~11 km) of the last successful sign-in on it. | `CBOX_ID_RADAR_DEVICE_RETENTION_DAYS` (180) after the last sign-in on it |
| Cache | Velocity counters and IP lookups, keyed by pseudonyms. | Two windows (at most two hours); lookups a day |
| `radar_rules`, `radar_list_entries`, `radar_settings` | What administrators wrote. A list entry holds the IP, range, address or domain **as typed** — it is configuration, kept readable on purpose. | Until removed |

Pseudonyms are HMAC-SHA256 under `app.key`, and everything Radar remembers about an account is
keyed under the environment too, so the same address in two environments is two people.
Erasing a person (`users.erase`) deletes their remembered devices and unlinks their address and
device from every decision in the environment (the erasure receipt lists `app.radar_devices`
and `app.risk_decisions`). It does **not** remove an allow- or deny-list entry naming their
address — that is the environment's own fraud-prevention record; remove it with
`radar.lists.remove` if the request covers it.

## The API, MCP and the CLI

Every console action is an action on the environment's management API and its MCP server:

| Action | REST | Scope |
| --- | --- | --- |
| `radar.settings.get` / `.update` | `GET` / `PATCH /api/v1/radar/settings` | `radar:read` / `radar:write` |
| `radar.mode.set` (critical) | `PUT /api/v1/radar/mode` | `radar:manage` |
| `radar.rules.list` / `.get` / `.create` / `.update` / `.delete` | `/api/v1/radar/rules[/{id}]` | `radar:read` / `radar:write` |
| `radar.rules.reorder` | `PUT /api/v1/radar/rules/order` | `radar:write` |
| `radar.lists.list` / `.add` / `.remove` | `/api/v1/radar/lists[/{id}]` | `radar:read` / `radar:write` |
| `radar.decisions.list` / `.get` | `GET /api/v1/radar/decisions[/{id}]` | `radar:read` |

`radar.decisions.list` filters by `verdict`, `flow`, `rule` (deciding or fired), `country`,
`email` and `ip` (matched by pseudonym, never returned), `device`, `enforced`, `from` and `to`,
newest first; pass `next_cursor` as `after` for the next page.

## Honest limits

- **IP intelligence is approximate.** A GeoIP country is right most of the time and a city
  often wrong; mobile carriers, satellite links and corporate VPNs place people far from where
  they are. Impossible travel ignores hops under 300 km (`CBOX_ID_RADAR_TRAVEL_MIN_KM`) for that
  reason, and still produces false positives for people on VPNs. Start it in monitor.
- **The free GeoLite2 databases report no VPN, proxy or hosting flags.** Without the paid
  Anonymous IP database (or IPinfo's privacy data) the *Tor, VPN or open proxy* rule sees only
  Tor, from the local exit list, and *Hosting network* never fires.
- **Velocity is only as shared as the cache.** On more than one replica the counters must live
  in a shared store (`CBOX_ID_RADAR_CACHE_STORE`, e.g. `redis`); a per-process store counts
  each pod's traffic separately. Counts use a two-bucket sliding window and the distinct-address
  count saturates at 1,000 per window — approximations, chosen to cost two cache reads.
- **Credential stuffing from a botnet** spreads across many IPs and stays under any per-IP
  threshold. *Repeated failures on one account* and the account lockout catch the guessing on
  each account; nothing per-IP catches a slow, wide spray. A shared NAT (an office, a mobile
  carrier) can also trip the per-IP rules — allow-list it.
- **Device recognition is not identification.** Clearing cookies makes any device new; the
  fingerprint fallback is deliberately coarse. A deny-listed device is a speed bump, not a ban.
- **Passkey and magic-link attempts are judged before anybody is known**, so their facts carry
  no address: rules on `email`, `new_device` or travel do not apply to them, and a challenge adds
  no step (they already prove possession). Blocks, lists and per-IP rules do apply.
- **Enterprise SSO sign-ins are the identity provider's to judge.** A federated sign-in is
  vouched for by the customer's IdP; Radar records the device and location it succeeded from,
  but does not challenge or block it.
- **A sign-up challenge without Turnstile is an emailed code**, which proves an inbox — a
  disposable inbox included, which is why the disposable-domain rule blocks rather than
  challenges by default.
- **Decisions are not on the audit chain.** They are pre-authentication telemetry and live in
  `risk_decisions` with their own retention (the reasons are in
  [Adaptive risk](../security/adaptive-risk.md#why-not-the-audit-log)). Changes to Radar's
  configuration — the mode, rules, lists — **are** on the audit chain, with who made them.
- **The bundled disposable list is a starter.** Refresh it, or point
  `CBOX_ID_RADAR_DISPOSABLE_DOMAINS_PATH` at your own.
