---
title: Administering the platform's own environment
weight: 6
description: The platform root environment — how every workspace owner and operator signs in — has the same console, API and MCP tools as any environment, with platform admin as one area of that console. Who administers it, where, and the guards that keep its operators able to sign in.
---

# Administering the platform's own environment

On a multi-tenant deployment the **platform root environment** (`cboxid.com` on the
hosted service, your root host when you self-host) is how every workspace owner and every
operator signs in to Cbox ID. It is an environment like any other. It has sign-in methods,
an authentication policy, social login, branding and a typeface, SMS, Radar, apps, APIs,
keys, webhooks, log streams, roles, feature flags, fine-grained authorization, pipes,
hooks and an audit log.

Its **platform operators** administer it, with the same console, the same actions and the
same API a tenant's administrators use. That console is also where they administer the
deployment itself: **platform admin** is its **Platform** area.

## Where

| | A tenant's environment | The platform's own environment |
|---|---|---|
| Console | `https://<environment>.cboxid.com/admin/…` | `https://cboxid.com/admin/…`, the same paths |
| Who | the workspace's owners, admins and developers | active platform operators |
| REST | `/api/v1/…` on the root with `Cbox-Environment: <slug>` | `/api/v1/…` on the root with `Cbox-Environment: <root slug or id>` |
| MCP | the root's `/mcp`, `environment` argument | the root's `/mcp`, `environment` argument |
| CLI | `cbox id use <environment>` | `cbox id use <root slug>` |
| Keys | that environment's `cbid_env_…` keys, on its host | the root environment's `cbid_env_…` keys, on the root host |

Ways in, for an operator:

- the account menu: **Platform environment**, or **Platform admin** for the Platform area;
- the context switcher in the topbar: **Platform › Platform**, on every console;
- ⌘K: type *Platform* and choose **Open Platform**;
- **Platform › Environments**: **Open console** on the *Platform root* row.

Every page says what it acts on, above the page. On the platform environment that line is
a warning: *the platform's own environment … a change here applies to all of them.*

## Platform admin

The deployment's own pages are the first area of the same console, **Platform**, at
`cboxid.com/admin/platform`:

| Page | Address | What it is for |
|---|---|---|
| Workspaces | `/admin/platform/workspaces` | Every workspace on the install. Open one to walk its projects and environments; **Suspend** and **Reactivate** are on its row and its page. |
| Environments | `/admin/platform/environments` | Every environment and who owns it. Create one, point platform admin at it, and **Provision admin** to seed its first organization and owner. |
| Organization tree | `/admin/platform/organizations` | The organizations of the environment platform admin is pointed at, as the reseller → customer → sub-unit tree. Re-parent, suspend, and open one to start a **support session** (impersonation) as one of its members. |
| Operators | `/admin/platform/operators` | Who runs the install. Add or suspend an operator. |
| Usage | `/admin/platform/usage` | Sign-ins, users and tokens across every environment. |
| Queues | `/admin/platform/queues` | Whether the queue workers are running, with the job monitor at `/admin/platform/queues/monitor`. |
| Search | `/admin/platform/search` | One search across every environment. ⌘K searches the platform environment itself. |

The line above each of these pages is the warning: *Platform admin: the whole deployment …
a suspension, a new environment or an operator change here applies across all of them.*

It is the same shell as the rest of `/admin`: the rail, the context switcher, ⌘K, the
breadcrumbs and the account menu do not change when you move between Platform and the
environment's own areas.

Every write is the same action the operator API runs (`/api/v1/platform/…` on the root, and
the root's MCP tools); the console address does not change the API's.

**Who sees it.** Active platform operators, on the root's own host. Anybody else gets
`404` for every `/admin/platform` address, signed in or not, and a tenant's host has no
such address at all. On a single-tenant install there is no environment console; the same
pages, at the same addresses, are a **Platform** area of the console's rail for its
operators.

### Support sessions

On **Platform › Organization tree**, open an organization and choose **Impersonate** on a
member (never an owner or admin), with a reason. You land on that member's console with a
banner naming them and **Exit impersonation**. Platform admin and the platform environment's
console answer `404` while it lasts. **Exit impersonation**, or the 30-minute limit, brings you back to
**Platform › Organization tree**. A user of the platform environment itself can be
impersonated the same way from **Users & orgs › Users**, and **Exit impersonation** returns you to the
platform environment's console.

### Old addresses

Platform admin used to be a separate console at `/platform`. Every old page answers `301`
to its new address, with the query string kept:

| Old | New |
|---|---|
| `/platform` | `/admin/platform/environments` |
| `/platform/workspaces`, `/platform/workspaces/{id}` | `/admin/platform/workspaces`, `/admin/platform/workspaces/{id}` |
| `/platform/customers`, `/platform/customers/{id}` | `/admin/platform/workspaces`, `/admin/platform/workspaces/{id}` |
| `/platform/organizations`, `/platform/organizations/{id}` | `/admin/platform/organizations`, `/admin/platform/organizations/{id}` |
| `/platform/operators` | `/admin/platform/operators` |
| `/platform/usage` | `/admin/platform/usage` |
| `/platform/queues` | `/admin/platform/queues` |
| `/platform/queues/monitor`, `/platform/queues/monitor/job/{uuid}` | `/admin/platform/queues/monitor`, `/admin/platform/queues/monitor/job/{uuid}` |
| `/platform/search`, `/platform/search/jump/{id}` | `/admin/platform/search`, `/admin/platform/search/jump/{id}` |
| `/operator` | `/admin/platform` |
| `/platform/security` | `/account` |

Form posts to the old addresses are not redirected; a page rendered before the move posts
to the new address the next time it loads.

A workspace owner who is not an operator gets `404` for `cboxid.com/admin`. They cannot
name the root environment over REST or MCP either (`not_found`).

## Turn on Google for cboxid.com

1. Open the platform environment's console and go to **Authentication › Social login**.
2. Choose **Google**. **Who is it for?** is already **The whole environment**.
3. Copy the redirect URI the form shows into your Google OAuth client. It is on the root
   host: `https://cboxid.com/sso/oidc/<id>/callback`. That is the only URI to register.
4. Paste the client ID and secret, then press **Turn on Google for everyone**.

The Google button is now on `https://cboxid.com/login`. Over the API, the same thing is
`signin.social.set` with `environment_wide: true`, sent to the root with
`Cbox-Environment` naming the root environment.

### It also signs people in to every environment console

Environment administrators are platform-root accounts. Somebody who opens
`acme.cboxid.com/admin/users` signed out goes through these steps:

1. They are sent to the root's sign-in page, `cboxid.com/login`. It offers the platform
   environment's buttons and sign-in methods.
2. They sign in. With Google, the provider calls back to the root's redirect URI. Radar,
   two-factor, step-up and the "last used" badge apply as for any sign-in.
3. They are handed to `acme.cboxid.com` by the signed, single-use **POST** handoff. No
   token appears in a URL.
4. They land on `/admin/users`, the page they asked for. That page is checked twice: it
   must be an environment-console page, and its signature must match the handoff. A
   foreign or tampered target is dropped, and the person lands on the console's home
   instead.

Signing out of `acme.cboxid.com/admin` signs the person out of `cboxid.com` too, and out
of every other console that sign-in opened. A handoff whose sign-in has ended is refused,
even if it was never used. The tenant's own users signed in on `acme.cboxid.com` are a
separate context: the console's sign-out leaves them signed in, and theirs leaves the
console open.

Somebody who signs in fine but administers nothing in that environment sees **You don't
administer this environment**. The page says their sign-in worked and offers
**Switch user** and their projects.

A **tenant's own** social providers are for the people who sign in to that tenant's apps,
on the tenant's hosted pages. They are never offered for the tenant's `/admin`. Each Social
login page says which of the two it is.

## The guards

The platform environment is where the operators sign in, so a change that would leave an
operator with no way in is refused. The refusal names who would be stranded and what to do
first. This is enforced in the actions themselves, so the console, REST, MCP and the CLI
all refuse the same changes:

- requiring SSO for the whole environment, or for an organization an operator belongs to,
  while that operator has no **active** SSO connection to use;
- disabling or deleting the SSO connection that operators who must use SSO sign in with;
- turning off passkeys and magic links for an operator who has neither a password nor any
  other way in;
- removing or turning off the social provider that is an operator's only way in.

**Approvals.** A token or key acting on the platform environment needs its person's
approval for every **write**. Elsewhere, only **critical** actions need one. The
actions and their declared danger do not change. Only the credential's approval threshold
is raised on the root. The console uses its own step-up.

There is no surface that deletes the root's signing keys. `cbox-id:keys:rotate` always
creates the new key before it retires the old ones.

## When the owner is not an operator

The first-run screen and `cbox-id:install` make the installer the first operator. A
deployment can still have an owner who is not one, for example somebody who signed up
before the deployment was claimed. Grant it from a shell on the deployment:

```bash
php artisan cbox-id:operator:grant owner@example.com
```

- The person must already sign in to the root. The command never creates a person.
- It links the operator record to that existing account. Their password, passkeys and
  second factor are unchanged.
- It is idempotent: running it again changes nothing and says so.
- It refuses to undo a suspension unless you pass `--reactivate`.
- It is recorded in the root's audit trail as `operator.granted`.

On Kubernetes, run it in one of the web pods. The hosted service runs in the `cbox-id`
namespace; replace `<web-deployment>` with the name of your web Deployment:

```bash
kubectl -n cbox-id exec -it deploy/<web-deployment> -- php artisan cbox-id:operator:grant owner@example.com
```

The person can open `https://<root>/admin`, and platform admin at
`https://<root>/admin/platform`, on their next page load.

## The workspace console on the root host

`cboxid.com` without `/admin` is a workspace's console: projects, team, keys, billing,
settings, the team's own sign-in to Cbox ID (**Team sign-in**) and its activity log.

Pages that set something an environment owns are not pages there any more. These include
Social login, Sign-in methods, Branding, Domains, Apps, Webhooks and Roles. Each used to
configure the workspace's own record in the root environment while looking like the
product's settings.

- An **operator** who opens one is sent to the same page of the platform environment's
  console, for example `/social-sign-in` → `/admin/social-sign-in`. Team sign-in is
  included.
- **Anybody else** is told, at that URL, that it is set in each environment's console,
  with a link to the same page in each environment they may open.
- A write to one of those pages answers `404`.

Single-tenant installs are unchanged. There the root environment is the product, and its
organization console is its administration — with platform admin as its **Platform** area,
at the same `/admin/platform` addresses, for its operators.

## Related

- [Planes and hosts](../core-concepts/planes-and-hosts.md)
- [Finding your way](../getting-started/finding-your-way.md)
- [Social login](../guides/social-sign-in.md)
- [Authentication policy and sign-in methods](../guides/authentication-policy.md)
