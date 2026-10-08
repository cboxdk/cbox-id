---
title: React
weight: 2
description: Sign people in to a React single-page app (Vite) with @cboxdk/id-js and the @cboxdk/id-react widgets, with a small server that keeps tokens out of the browser.
---

# React

You will build a React single-page app (Vite) with a user button from `@cboxdk/id-react`,
where a small Node server in the same project runs the sign-in, so the browser never holds
a token.

You need Node 20 or later and the three things in
[Before you start](_index.md#before-you-start). Written against `@cboxdk/id-js` 0.17 and
`@cboxdk/id-react` 0.7.

## Why there is a server

A sign-in that runs entirely in the browser does not work against Cbox ID today. The
discovery document, the token endpoint and the JWKS send no CORS headers, so a page served
from another origin cannot read them: `CboxIdClient` called from React fails on its first
request with a CORS error in the console. If you used the console's **Get started** page,
skip its browser-only `src/auth.ts` snippet for this reason.

So the two steps that talk to Cbox ID, starting the sign-in and finishing it, run in a
server of about forty lines. Vite's dev server proxies `/auth`, `/callback` and `/api` to
it, so everything stays on one origin and the session is an ordinary httpOnly cookie.

## 1. Create the app

Register a **Single-page or mobile app**: a public client that signs in with PKCE and holds
no secret. Use the redirect URI `http://localhost:5173/callback` and the sign-out URI
`http://localhost:5173/`.

Because a server now finishes the sign-in, you can register a **Web app** instead and set
`CBOX_ID_CLIENT_SECRET` in step 3. Nothing else on this page changes.

**In the console.** Open the environment console (`https://<environment>.cboxid.com/admin`)
and choose one of:

- **Home → Get started.** Pick **React**, optionally name the app, and create it. The page
  registers a Single-page or mobile app with the redirect URI above, then waits for your
  first sign-in and tells you when it arrives. It does not set a sign-out URI: open the
  app under **Developers → Applications** and add `http://localhost:5173/` under
  **Sign-out URIs**.
- **Developers → Applications → New app.** Enter an app name, answer **Single-page or
  mobile app** to *What kind of app is it?*, and fill in **Redirect URIs** and
  **Sign-out URIs**.

A single-page app is issued no client secret. Copy the **client ID** from the app's page.

**With the `cbox` CLI.** Sign in, pick the environment, and run the `apps.create` action:

```bash
cbox login
cbox id use <environment>
cbox id apps create \
  --name="My React app" \
  --type=spa \
  --redirect-uris=http://localhost:5173/callback \
  --post-logout-redirect-uris=http://localhost:5173/
```

Creating an app is a critical action. The CLI asks before sending it, and because you
are signed in as yourself it then waits for your approval: approve the request showing
the same code on your device or under **Approvals** in the console. The answer includes
`client_id`.

**With the REST API.** Use a secret key (`cbid_env_…`) with the `apps:write` scope, on the
environment's own host (see [API keys](../guides/keys.md)):

```bash
curl -X POST https://<environment>.cboxid.com/api/v1/apps \
  -H "Authorization: Bearer cbid_env_…" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "name": "My React app",
    "type": "spa",
    "redirect_uris": ["http://localhost:5173/callback"],
    "post_logout_redirect_uris": ["http://localhost:5173/"]
  }'
```

The `201` response carries `client_id`.

## 2. Install the SDK

```bash
npm create vite@latest my-app -- --template react-ts
cd my-app
npm install @cboxdk/id-js @cboxdk/id-react express express-session
```

`@cboxdk/id-js` runs the sign-in on the server. `@cboxdk/id-react` draws the user button
and sign-in and sign-out buttons in the browser; it never touches a token. `express` and
`express-session` are the server and its session.

## 3. Set the environment

```bash
# .env — read by server.mjs only; nothing here reaches the browser bundle
CBOX_ID_ISSUER=https://<environment>.cboxid.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_REDIRECT_URI=http://localhost:5173/callback
CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:5173/
SESSION_SECRET=<at least 32 random characters, e.g. from: openssl rand -base64 32>
# Only if you registered a Web app:
# CBOX_ID_CLIENT_SECRET=csec_…
```

Do not prefix these with `VITE_`: that would put them in the browser bundle. `CboxIdClient`
reads no environment variables itself; the server below passes these in.

Proxy the server's routes through Vite:

```ts
// vite.config.ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/auth': 'http://localhost:3001',
      '/callback': 'http://localhost:3001',
      '/api': 'http://localhost:3001',
    },
  },
});
```

Start the server with the client and the session:

```js
// server.mjs
import express from 'express';
import session from 'express-session';
import { CboxIdClient } from '@cboxdk/id-js';

const cbox = new CboxIdClient({
  issuer: process.env.CBOX_ID_ISSUER,
  clientId: process.env.CBOX_ID_CLIENT_ID,
  clientSecret: process.env.CBOX_ID_CLIENT_SECRET, // undefined for a single-page app
  redirectUri: process.env.CBOX_ID_REDIRECT_URI,
});

const app = express();
app.use(
  session({
    secret: process.env.SESSION_SECRET,
    resave: false,
    saveUninitialized: false,
    cookie: { httpOnly: true, sameSite: 'lax' },
  }),
);

// Steps 4–7 add routes here.

app.listen(3001);
```

## 4. The sign-in route

```js
// server.mjs
app.get('/auth/sign-in', async (req, res) => {
  const { url, state, codeVerifier, nonce } = await cbox.createAuthorizationRequest();
  req.session.pending = { state, codeVerifier, nonce };
  res.redirect(url);
});
```

`createAuthorizationRequest()` returns the authorize URL with a PKCE `S256` challenge, a
`state` and a nonce. The server keeps the three values in the session for the callback.

## 5. The callback

```js
// server.mjs
app.get('/callback', async (req, res) => {
  const user = await cbox.authenticate({
    params: {
      code: req.query.code,
      state: req.query.state,
      error: req.query.error,
      error_description: req.query.error_description,
    },
    stored: req.session.pending ?? { state: '', codeVerifier: '', nonce: '' },
  });

  req.session.pending = undefined;
  req.session.user = {
    id: user.id,
    email: user.email,
    name: user.name,
    organizationId: user.organizationId,
  };
  req.session.idToken = user.idToken;
  res.redirect('/');
});

app.get('/api/me', (req, res) => {
  res.json(req.session.user ?? null);
});
```

`authenticate()` verifies the state, exchanges the code with the PKCE verifier and
verifies the `id_token`. It throws `InvalidStateError` or `AuthenticationError`, which
Express 5 (the version `npm install express` installs) hands to its error handler. Only the
fields the widgets draw go to the browser, through `/api/me`; the tokens stay in the
server's session.

Render the user button:

```tsx
// src/App.tsx
import { useEffect, useState } from 'react';
import { CboxIdProvider, UserButton } from '@cboxdk/id-react';

type User = { id: string; email: string | null; name: string | null; organizationId: string | null };

export default function App() {
  const [user, setUser] = useState<User | null | undefined>(undefined);

  useEffect(() => {
    fetch('/api/me').then((r) => r.json()).then(setUser);
  }, []);

  if (user === undefined) return null;

  return (
    <CboxIdProvider user={user} urls={{ signIn: '/auth/sign-in', signOut: '/auth/sign-out' }}>
      <header>
        <UserButton />
      </header>
      <main>{user ? <p>Signed in as {user.email}</p> : <p>You are signed out.</p>}</main>
    </CboxIdProvider>
  );
}
```

`<UserButton>` shows a sign-in button when `user` is null, and an avatar with an account
menu and **Sign out** when it is not.

Run the server and Vite in two terminals, then open `http://localhost:5173`:

```bash
node --env-file=.env --watch server.mjs
npm run dev
```

## 6. Protect a route

The server is what actually protects data. Refuse API calls without a session:

```js
// server.mjs — before the routes it guards
function requireUser(req, res, next) {
  if (!req.session.user) return res.status(401).json({ error: 'unauthenticated' });
  next();
}

app.get('/api/reports', requireUser, (req, res) => {
  res.json({ owner: req.session.user.id, reports: [] });
});
```

In the browser, send a signed-out visitor to sign in before rendering a private view:

```tsx
// src/RequireUser.tsx
import { useEffect, type ReactNode } from 'react';
import { useCboxUser } from '@cboxdk/id-react';

export function RequireUser({ children }: { children: ReactNode }) {
  const user = useCboxUser();

  useEffect(() => {
    if (!user) window.location.assign('/auth/sign-in');
  }, [user]);

  return user ? <>{children}</> : null;
}
```

Use it inside `<CboxIdProvider>`, for example `<RequireUser><Reports /></RequireUser>`.

## 7. Sign out

```js
// server.mjs
app.get('/auth/sign-out', async (req, res) => {
  const idToken = req.session.idToken;
  req.session.destroy(() => {});
  const url = await cbox.logoutUrl(process.env.CBOX_ID_POST_LOGOUT_REDIRECT_URI, idToken ?? undefined);
  res.redirect(url ?? '/');
});
```

`<UserButton>`'s **Sign out** links here. `logoutUrl()` returns the environment's
end-session URL (`/oauth/logout`), or `null` if it advertises none. Cbox ID sends the
person back only to a URI listed under **Sign-out URIs** on the app, matched character
for character. The `id_token` hint lets it end the person's sessions everywhere, not only
in this browser.

## Next steps

- [Workspaces & organizations](../core-concepts/workspaces-and-organizations.md) — where
  your app sits in Cbox ID.
- [Organizations in your app](../getting-started/organizations-in-your-app.md) — bind a
  sign-in to one of your customers; `<OrganizationSwitcher>` draws the switcher.
- [Enterprise SSO](../guides/single-sign-on.md) — let a customer sign in with their own
  identity provider.
- [Agents and MCP](../guides/agents-and-mcp.md) — manage the environment from an agent.
- [Reference](../reference/_index.md).
- The SDKs: [cboxdk/id-js](https://github.com/cboxdk/id-js) and
  [cboxdk/id-react](https://github.com/cboxdk/id-react).

## When it doesn't work

| What you see | What to do |
|---|---|
| A CORS error in the browser console on `/.well-known/openid-configuration` or `/oauth/token` | The sign-in is running in the browser. Run it in `server.mjs` as above. |
| Cbox ID shows "The redirect URI does not match any registered for this application." | `CBOX_ID_REDIRECT_URI` must equal one of the app's **Redirect URIs** exactly, `http://localhost:5173/callback` here. |
| An issuer error, or the callback refuses the `id_token` | `CBOX_ID_ISSUER` must be the `issuer` from discovery, verbatim, with no trailing slash. |
| `invalid_client` on the callback | The app is registered as a Web app but no `CBOX_ID_CLIENT_SECRET` is set, or the client ID is wrong. |
| A 404 from `/.well-known/openid-configuration` | You pointed at `cboxid.com`. Use the environment's own host. |

More in [Integrate your app](../getting-started/integrate-your-app.md#when-it-does-not-work).
