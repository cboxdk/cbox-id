---
title: Administering the platform's own environment
weight: 6
description: The platform root environment — how every workspace owner and operator signs in — has the same console, API and MCP tools as any environment. Who administers it, where, and the guards that keep its operators able to sign in.
---

# Administering the platform's own environment

On a multi-tenant deployment the **platform root environment** (`cboxid.com` on the
hosted service, your root host when you self-host) is how every workspace owner and every
operator signs in to Cbox ID. It is an environment like any other. It has sign-in methods,
an authentication policy, social login, branding and a typeface, SMS, Radar, apps, APIs,
keys, webhooks, log streams, roles, feature flags, fine-grained authorization, pipes,
hooks and an audit log.

Its **platform operators** administer it, with the same console, the same actions and the
same API a tenant's administrators use.

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

- the account menu: **Platform environment**;
- the context switcher in the topbar: **Platform › Platform**, on every console;
- ⌘K: type *Platform* and choose **Open Platform**;
- **Platform admin › Environments**: **Open console** on the *Platform root* row.

Every page says what it acts on, above the page. On the platform environment that line is
a warning: *the platform's own environment … a change here applies to all of them.*

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

The person can open `https://<root>/admin` on their next page load.

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
organization console is its administration.

## Related

- [Planes and hosts](../core-concepts/planes-and-hosts.md)
- [Finding your way](../getting-started/finding-your-way.md)
- [Social login](../guides/social-sign-in.md)
- [Authentication policy and sign-in methods](../guides/authentication-policy.md)
