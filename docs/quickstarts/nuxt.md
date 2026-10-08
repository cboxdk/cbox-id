---
title: Nuxt
weight: 4
description: Sign people in to a Nuxt app with the @cboxdk/id-nuxt module — register the app, add one module entry, protect a page with route middleware, sign out.
---

# Nuxt

You will build a Nuxt app where people sign in through your Cbox ID environment, reach a
page behind route middleware, and sign out again. The `@cboxdk/id-nuxt` module registers
the sign-in, callback and sign-out routes and keeps the session for you.

You need Nuxt 3 or 4 and the three things in
[Before you start](_index.md#before-you-start). Written against `@cboxdk/id-nuxt` 0.5.

## 1. Create the app

Register a **Web app**: the module runs the sign-in on Nuxt's server, so it can keep a
client secret. Use the redirect URI `http://localhost:3000/auth/callback` and the sign-out
URI `http://localhost:3000/`.

**In the console.** Open the environment console (`https://<environment>.cboxid.com/admin`)
and choose one of:

- **Home → Get started.** Pick **Nuxt**, optionally name the app, and create it. The page
  registers a Web app with the redirect URI above, shows the client secret once, gives
  you the environment block and the code from steps 2 to 6 below, then waits for your
  first sign-in and tells you when it arrives. It does not set a sign-out URI: open the
  app under **Developers → Applications** and add `http://localhost:3000/` under
  **Sign-out URIs**.
- **Developers → Applications → New app.** Enter an app name, answer **Web app** to
  *What kind of app is it?*, and fill in **Redirect URIs** and **Sign-out URIs**.

Creating an app issues a secret, so the console may ask you to confirm it is you first.
Copy the **client secret** when it is shown: it is stored hashed and cannot be shown
again. If you lose it, rotate it on the app's page.

**With the `cbox` CLI.** Sign in, pick the environment, and run the `apps.create` action:

```bash
cbox login
cbox id use <environment>
cbox id apps create \
  --name="My Nuxt app" \
  --type=web \
  --redirect-uris=http://localhost:3000/auth/callback \
  --post-logout-redirect-uris=http://localhost:3000/
```

Creating an app is a critical action. The CLI asks before sending it, and because you
are signed in as yourself it then waits for your approval: approve the request showing
the same code on your device or under **Approvals** in the console. The answer includes
`client_id` and `client_secret`, printed once.

**With the REST API.** Use a secret key (`cbid_env_…`) with the `apps:write` scope, on the
environment's own host (see [API keys](../guides/keys.md)):

```bash
curl -X POST https://<environment>.cboxid.com/api/v1/apps \
  -H "Authorization: Bearer cbid_env_…" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "name": "My Nuxt app",
    "type": "web",
    "redirect_uris": ["http://localhost:3000/auth/callback"],
    "post_logout_redirect_uris": ["http://localhost:3000/"]
  }'
```

The `201` response carries `client_id` and `client_secret`. The secret is returned once; a
retry with the same `Idempotency-Key` returns the app with `client_secret: null`.

## 2. Install the SDK

```bash
npm install @cboxdk/id-nuxt
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@cboxdk/id-nuxt'],
});
```

The module brings `@cboxdk/id-js` for the sign-in and the `@cboxdk/id-vue` widgets
(`<CboxUserButton>` and friends), auto-imported.

## 3. Set the environment

```dotenv
# .env
CBOX_ID_ISSUER=https://<environment>.cboxid.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_CLIENT_SECRET=csec_…
CBOX_ID_REDIRECT_URI=http://localhost:3000/auth/callback
CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:3000/
CBOX_ID_SESSION_PASSWORD=<at least 32 random characters, e.g. from: openssl rand -base64 32>
```

The module reads all six. `CBOX_ID_SESSION_PASSWORD` seals the session cookie. The values
can also go in `nuxt.config.ts` under `cboxId` (`issuer`, `clientId`, `clientSecret`,
`redirectUri`, `postLogoutRedirectUri`).

The module reads `CBOX_ID_*` when Nuxt starts or builds. For a production build, set them
at build time, or override them at runtime with Nuxt's own runtime-config variables:
`NUXT_CBOX_ID_ISSUER`, `NUXT_CBOX_ID_CLIENT_ID`, `NUXT_CBOX_ID_CLIENT_SECRET`,
`NUXT_CBOX_ID_REDIRECT_URI`, `NUXT_CBOX_ID_POST_LOGOUT_REDIRECT_URI` and
`NUXT_CBOX_ID_SESSION_PASSWORD`.

## 4. The sign-in route

The module registers `GET /auth/sign-in`. Link to it, or drop in the widget:

```vue
<!-- app.vue -->
<template>
  <header>
    <!-- a sign-in button when signed out; avatar and account menu when signed in -->
    <CboxUserButton />
  </header>
  <NuxtPage />
</template>
```

`/auth/sign-in` accepts `?redirect=/where/next`, a path on your own site to land on
afterwards.

## 5. The callback

Nothing to write. `GET /auth/callback` is registered by the module: it verifies the state,
PKCE and the `id_token`, stores the user in the sealed session, and redirects to the
sign-in's `?redirect=` path, or `/`. Read the user anywhere:

```vue
<script setup lang="ts">
const user = useCboxUser();
</script>

<template>
  <p v-if="user">Signed in as {{ user.email }}</p>
</template>
```

## 6. Protect a route

```ts
// middleware/auth.ts
export default defineNuxtRouteMiddleware(() => {
  const user = useCboxUser();
  if (!user.value) {
    return navigateTo('/auth/sign-in?redirect=' + encodeURIComponent(useRoute().fullPath), {
      external: true,
    });
  }
});
```

```vue
<!-- pages/dashboard.vue -->
<script setup lang="ts">
definePageMeta({ middleware: 'auth' });
const user = useCboxUser();
</script>

<template>
  <p>Signed in as {{ user?.email }}</p>
</template>
```

`external: true` makes the redirect a full page load, because `/auth/sign-in` is a server
route rather than a page. Run `npm run dev`, open `http://localhost:3000/dashboard`, and
you are sent to sign in and back.

## 7. Sign out

The module registers `GET /auth/sign-out`. It clears the session and redirects to the
environment's end-session URL (`/oauth/logout`) with `CBOX_ID_POST_LOGOUT_REDIRECT_URI` as
the return address. `<CboxUserButton>`'s **Sign out** links there, or link to it yourself:

```vue
<a href="/auth/sign-out">Sign out</a>
```

Set `CBOX_ID_POST_LOGOUT_REDIRECT_URI` to exactly a URI under **Sign-out URIs** on the app,
trailing slash included. Left unset, the module falls back to the request origin
(`http://localhost:3000`, no slash), which rarely matches, and the person stays on Cbox
ID's own signed-out page.

## Next steps

- [Workspaces & organizations](../core-concepts/workspaces-and-organizations.md) — where
  your app sits in Cbox ID.
- [Organizations in your app](../getting-started/organizations-in-your-app.md) — bind a
  sign-in to one of your customers; the module's `/auth/switch-organization` route and
  `<CboxOrganizationSwitcher>`.
- [Enterprise SSO](../guides/single-sign-on.md) — let a customer sign in with their own
  identity provider.
- [Agents and MCP](../guides/agents-and-mcp.md) — manage the environment from an agent.
- [Reference](../reference/_index.md).
- The SDK: [cboxdk/id-nuxt](https://github.com/cboxdk/id-nuxt).

## When it doesn't work

| What you see | What to do |
|---|---|
| Cbox ID shows "The redirect URI does not match any registered for this application." | `CBOX_ID_REDIRECT_URI` must equal one of the app's **Redirect URIs** exactly: scheme, host, port, path and trailing slash. |
| An issuer error, or the callback refuses the `id_token` | `CBOX_ID_ISSUER` must be the `issuer` from discovery, verbatim, with no trailing slash. |
| `invalid_client` on the callback | Wrong client ID or secret. A lost secret cannot be shown again: rotate it on the app's page. |
| A 404 from `/.well-known/openid-configuration` | You pointed at `cboxid.com`. Use the environment's own host. |
| Sign-out leaves people on Cbox ID's signed-out page | `CBOX_ID_POST_LOGOUT_REDIRECT_URI` is unset or not on the app's **Sign-out URIs**. |

More in [Integrate your app](../getting-started/integrate-your-app.md#when-it-does-not-work).
