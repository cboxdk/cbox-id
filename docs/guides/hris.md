---
title: HR system sync
weight: 41
description: Connect Workday, BambooHR, Rippling, HiBob or Personio so people get an account on their start date and lose it after their last day, with departments as groups you map to roles.
---

# HR system sync

**Console page:** Sign-in › Directory Sync › New directory (Authentication › Directory Sync in an environment console)

An HR system knows who works at the company before anyone else does: HR records a start
date before IT hears about the hire, and a termination date before anyone thinks to close
an account. Connect it, and Cbox ID keeps the organization's people in step with it:

- **A joiner gets an account on their start date** — not when the offer is signed.
- **A leaver loses it at the end of their last day**, every session signed out at once,
  even if HR has not flipped their status yet. People on leave keep their account.
- **Departments become groups**, which you map onto [roles](roles.md) like any other
  directory group, so access follows the department somebody is filed under.
- **Their manager, job title and employee number come along**, and any other HR field you
  name — a cost centre, a location — is copied onto the person as it is.

It is [Directory Sync](sync-users-in.md) the other way round: Cbox ID **pulls** from the HR
system's API on a schedule, so nothing on the HR side has to speak SCIM.

| HR system | What the customer creates | Changes picked up |
|---|---|---|
| Workday | A custom report enabled as a web service (RaaS), shared with an integration system user — or an API client with a refresh token | A full sync every time |
| BambooHR | An API key for a user who can see every employee | Changes only, between daily full syncs |
| Rippling | An API token with `workers.read`, `users.read`, `departments.read` | Changes only, between daily full syncs |
| HiBob | An API service user that can read root, work and lifecycle fields of everybody | A full sync every time |
| Personio | API credentials with read access to persons and org units | A full sync every time |

The click-by-click setup for each, as the customer's IT or HR administrator sees it, is in
the [identity provider guides](../for-it-admins/idp/_index.md#hr-systems).

## Connect one

1. Choose **New directory**, pick the HR system, and follow the steps the page shows —
   they are the HR system's own screens and words.
2. Paste what it produced. Secrets are typed into password fields, **checked against the
   HR system before anything is stored**, sealed, and never shown again. A wrong key or a
   missing permission is refused on the spot with the HR system's reason — "the
   credentials lack permission to read employees" — rather than discovered by a sync that
   provisions nobody.
3. Optionally name **fields to pass through**, one per line, in the HR system's own field
   names (BambooHR `location`, Rippling `work_location.city`, a Personio attribute label, a
   HiBob field id such as `work.custom.field_123`).
4. **Verify and connect.** The first sync starts in the background; the directory page
   shows it running, then how it went.

Or hand it to the people who run the HR system: an [Admin Portal](admin-portal.md) link
that covers **Directory Sync** lets them connect it themselves, in their language, without
an account here.

## Watching it

The directory's page shows the last sync — **Synced**, **Synced with problems**, **Failed**
or **Syncing** — when it ran, when the next one is due, and what it did: how many people
are up to date, deprovisioned and skipped, and how many groups.

**Synced with problems** means the HR system answered and almost everybody was synced, but
some records were not. Each is listed by the HR system's own employee ID with the reason:

- *Has no work email* — there is nothing to make an account from. Add one in the HR system.
- *Their work email belongs to an existing account that is not linked to this directory* —
  somebody already signed up with that address. Remove or link that account; Cbox ID never
  merges an HR record into an account on its own, because that is how accounts get taken
  over.
- *Refused to deprovision N of M active people* — see below.

Somebody listed there is never deprovisioned for it: a data problem in the HR system must
not lock out a person who still works there.

**Sync now** pulls straight away; **Full sync** asks a system that normally sends only
changes for everybody. **Sync settings** sets the pace (every 15 minutes to once a day;
hourly by default) and the fields to pass through. **Replace credentials** takes a rotated
key without disconnecting anything, checked before it replaces the old one.

## What it will not do

- **Create accounts for people who left before you connected.** HR systems keep everybody
  they ever employed; only people already in the directory are touched when they leave.
- **Create an account before the start date.** Pre-hires appear on the day
  (`CBOX_ID_HRIS_PRE_HIRE_DAYS` brings that forward for the whole platform).
- **Deactivate half the company because a permission changed.** A full sync that would
  deprovision more than half of the active people deprovisions nobody and says so — an
  API key that lost access to most departments looks exactly like a mass layoff. Fix the
  permission and run a full sync. (`CBOX_ID_HRIS_DEPROVISION_GUARD` sets the share; `1`
  turns it off.)

## From code

| Action | REST | Scope | Danger |
|---|---|---|---|
| `directories.hris.connect` | `POST /api/v1/directories/hris` | `directory_sync:write` | critical |
| `directories.sync` | `POST /api/v1/directories/{id}/sync` | `directory_sync:write` | write |
| `directories.sync_settings.update` | `PATCH /api/v1/directories/{id}/sync-settings` | `directory_sync:write` | write |
| `directories.credentials.replace` | `PUT /api/v1/directories/{id}/credentials` | `directory_sync:write` | critical |
| `directories.get` | `GET /api/v1/directories/{id}` | `directory_sync:read` | read |

Each is also an MCP tool and a CLI command. `credentials` takes the keys the HR system's
setup names — `report_url`, `username`, `password` (or `client_id`, `client_secret`,
`refresh_token`) for Workday; `subdomain`, `api_key` for BambooHR; `api_token` for
Rippling; `service_user_id`, `service_user_token` for HiBob; `client_id`, `client_secret`
for Personio — and none of them is ever returned. `directories.get` answers
`last_sync_status`, `last_sync_stats` (failures by employee ID, never by name or email),
`sync_interval_minutes` and `next_sync_at`.

## How a person arrives

Each employee is stored the way a SCIM directory stores a user, so everything that reads
the directory reads HR people the same way: name, work email, title, and the SCIM
enterprise attributes `employeeNumber`, `department` and `manager` — whose value is the
manager's employee ID in the HR system. The employment status, start and termination
dates, department ID and the fields you passed through sit beside them in the
`urn:cbox-id:params:scim:schemas:extension:hris:1.0:User` extension.

## Related

- [Directory Sync](sync-users-in.md) — the same page, for identity providers that push over SCIM.
- [Admin Portal](admin-portal.md) — let the customer's HR or IT admin connect it.
- [Roles](roles.md) — what mapping a department grants.
- [Sync people from an HR system](https://github.com/cboxdk/laravel-id/blob/main/docs/cookbook/sync-people-from-an-hr-system.md) — the framework's side.
