---
title: The realm flip
weight: 9
description: The one-time data migration that makes the cbox environment the platform root and retires the old root. What it does per table, how to rehearse it on a copy, the cutover sequence, and how to roll back.
---

# The realm flip

A deployment that grew a platform root **and** a `cbox` environment holds each customer
twice: an organization and a person in the root (projects, keys, billing, the team), and
again in `cbox` (every Cbox app). `organization_links` joins the two.
`php artisan cbox-id:realm:flip` makes the `cbox` environment the platform root and
retires the old one. The organization and the person that survive are the `cbox` ones,
with the ids every app and token already carries.

It applies only to a deployment that has both environments. A single-environment install
has nothing to flip, and the command says so.

## The command

```bash
php artisan cbox-id:realm:flip                          # dry run (the default)
php artisan cbox-id:realm:flip --execute --confirm=<id of the root being retired>
php artisan cbox-id:realm:flip --verify
```

- **The dry run is the real thing, rolled back.** Every step runs in one transaction that
  is then undone. The plan, the id map, the per-table counts and the before/after
  inventory are what `--execute` will do to this database.
- **`--execute` runs in one transaction** and checks its post-conditions before it commits.
  A flip that would leave a row naming a retired id does not commit. That check searches
  every text column of every table for the retired ids; it does not rely on the list of
  tables the flip knows about.
- **Run it again safely.** A flipped deployment answers "nothing to do" and changes nothing.
- **`--verify`** checks the same post-conditions later, from the record the flip left in
  `realm_flips` and `realm_flip_ids`.

| Option | What it does |
|---|---|
| `--create-missing` | Moves an unlinked root organization, and a root person with no `cbox` account, into the realm with the id they have |
| `--role-conflict=highest\|root\|realm` | Which role a person keeps where both sides gave them one (default `highest`) |
| `--domain=<host>\|none` | The new root's `domain`. Default: the configured login host, else the old root's domain |
| `--carry-client=<client_id>` | Carries a root platform client although the realm has one of the same name |
| `--keep-root-audit` | Leaves the old root's audit rows in place, for audit tables that refuse `DELETE` |
| `--allow-pending-jobs` | Goes ahead although jobs wait on a queue that cannot be searched |
| `--tables` | Prints the decision for every table, not only the ones with rows |

## What blocks it

The dry run lists each of these and exits non-zero:

- There is not exactly one default environment, or no environment has the configured
  `cbox` slug (`CBOX_ID_CBOX_ENVIRONMENT`).
- A root organization that owns projects, keys, billing rows, connections or domains has
  no link. Link it with `php artisan orgs:link`, or pass `--create-missing`. There is no
  "skip": an organization that owns a project cannot be dropped, because its
  environments would stop being served.
- A member of a surviving organization, or a platform operator, has no `cbox` account.
  A person is matched by linked `sub` first (the identity the root signed in through),
  then by address. Have them sign in once, or pass `--create-missing`.
- No active platform operator exists.
- A pending job names an id of the old root, or jobs wait on a queue that cannot be read.
- A table holds rows of the old root and has no decision.
- `audit_logs` or `audit_checkpoints` has a trigger on `DELETE`.

An unlinked root organization that owns nothing is dropped with its memberships, and the
dry run says so first. `--create-missing` moves it instead.

## What happens to the data

`php artisan cbox-id:realm:flip --tables` prints every decision with its reason. In short:

| Decision | Tables | Why |
|---|---|---|
| Re-pointed | `projects`, `organization_api_keys`, `platform_operators`, `product_billing_usage_outbox`, `membership_environments`, and the console person named in tenant rows (`environment_api_keys.created_by_id`, `invitations.invited_by`, `admin_portal_links.created_by`, …) | They name a root organization or person by id; the id changes, the row stays |
| Merged | `memberships` | A root team role becomes the realm membership. The row moves where the person is not a member yet; otherwise the realm row takes the role (`--role-conflict`) and the root's environment grants |
| Moved into the realm | Clients and their secrets, roles and role assignments, invitations, connections and sign-in policy of an organization, verified domains, directories, webhooks, vault secrets, personal access tokens, feature flags, branding | They are the console's own configuration. Where the realm already has a row for the same thing, the realm's wins and the plan counts it |
| Follow the person | Passkeys, second factors, password history, Authenticator devices, linked identities | Moved with a person whose account moved. Dropped for a person who already has a `cbox` account: that account and its credentials survive |
| Archived | `audit_logs`, `audit_checkpoints`, `app_audit_*` of the old root | See below |
| Dropped | Sessions, tokens, pending codes and challenges, counters, the old root's signing keys, its event outbox and Signals ledger | Short-lived or derived, or meaningless once the old root no longer issues |

Three things are not carried, on purpose:

1. **A person's root-only credentials.** Somebody with an account on both sides keeps the
   `cbox` account, its `sub`, its password, its passkeys and its second factor. The root's
   passkeys and factors are dropped: a passkey carries the user id it was made for, and a
   second factor is sealed to its user id. The dry run names everybody who ends up with
   no passkey or factor, and every operator who must enrol one before `/admin` opens.
2. **Platform clients the realm already has by name.** The Authenticator, the CLI and the
   step-up client are found by name inside an environment. Where the realm has one, the
   root's client id cannot be kept. The dry run names each. Every other platform client
   keeps its client id and secrets.
3. **Sessions and tokens.** All of them, in every environment, are revoked. API keys and
   personal access tokens are not sessions and keep working.

### The audit trail

The audit log is a hash chain per environment and scope. An entry's hash covers its
environment id, its organization id and its sequence, so the old root's entries cannot
join the realm's chains without being rewritten, and a rewritten trail proves nothing.

The flip therefore verifies each of the old root's chains where it stands, copies every
entry and checkpoint verbatim into `realm_flip_archive` (hashes included), and removes
them from the live tables. The realm's own chains are not touched and are verified again
before the flip commits. The console's activity page starts from the flip, whose first
entry is `realm.flipped` on the new root's chain.

Export the archive with:

```sql
\copy (select payload from realm_flip_archive where source_table = 'audit_logs' order by chain, sequence) to 'old-root-audit.jsonl'
```

## Rehearsing it

`scripts/realm-flip-rehearsal.sh` starts a throwaway `postgres:18`, restores a dump (or
seeds a fixture shaped like the hosted service), migrates, and runs dry run, execute,
verify, `audit-chain:verify` and a second execute, comparing row counts around the dry
run and the second run.

```bash
scripts/realm-flip-rehearsal.sh --seed
scripts/realm-flip-rehearsal.sh --dump /secure/cbox-id.dump --flip "--domain=login.example.com"
```

A dump is production data: keep it on an encrypted disk. The script copies it only into
the container it removes afterwards. Read the dry run it prints. Every line under "Read
before executing" is a decision the flip makes for you.

## The cutover

1. **Rehearse** on a dump taken the same day, with the options you will use.
2. **Back up.** A full database backup (see Rollback), and note the release that is live.
3. **Stop writes.** Maintenance mode on the web pods, then stop the queue workers and the
   scheduler once the queues are empty.
4. **Deploy the release that contains the flip** and run `php artisan migrate --force`.
5. **Dry run** on production: `php artisan cbox-id:realm:flip` with your options. It must
   print the same plan as the rehearsal.
6. **Execute:** `php artisan cbox-id:realm:flip --execute --confirm=<root id> …`.
7. **Verify:** `php artisan cbox-id:realm:flip --verify`, then `php artisan audit-chain:verify`.
8. **Follow up**, as the flip printed under "Other systems that must follow": re-key the
   Billing account of every organization whose id changed, set the environment variables
   that name an environment id or a host, add the new callback URL at each social
   provider, and run `php artisan cache:clear`.
9. **Start** the workers and the scheduler, leave maintenance mode, and sign in.

## Rolling back

The flip is one transaction: if it fails, nothing changed. After it has committed there
is no reverse command. It deletes the old root's users, credentials and sessions, and
those cannot be rebuilt from what is left. Roll back by restoring:

- **The database, whole**, from the backup taken in step 2 (a `pg_dump` or the
  point-in-time backup, restored to the moment before step 6). The flip touches most
  tables, so a partial restore is not sound.
- **The environment variables** as they were, and the previous release.
- **The cache and the session store**, flushed, so nothing keyed by the new state survives.
- **Billing's account keys**, if they were already re-keyed in step 8.

Sign-ins and writes made after the flip are lost by a restore. Decide inside the
maintenance window, before step 9.
