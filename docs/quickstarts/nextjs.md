---
title: Next.js
weight: 1
description: Sign people in to a Next.js App Router app with @cboxdk/id-js — register the app, add four route handlers, protect a page, sign out.
---

# Next.js

You will build a Next.js (App Router) app where people sign in through your Cbox ID
environment, land on a protected `/dashboard`, and sign out again.

You need Node 20 or later, Next.js 14, 15 or 16, and the three things in
[Before you start](_index.md#before-you-start). Written against `@cboxdk/id-js` 0.17.

## 1. Create the app

Register a **Web app**: Next.js runs on a server, so it can keep a client secret. Use the
redirect URI `http://localhost:3000/auth/callback` and the sign-out URI
`http://localhost:3000/`.

**In the console.** Open the environment console (`https://<environment>.cboxid.com/admin`)
and choose one of:

- **Home → Get started.** Pick **Next.js**, optionally name the app, and create it. The
  page registers a Web app with the redirect URI above, shows the client secret once,
  gives you the environment block and starter code for Next.js, then waits for your first
  sign-in and tells you when it arrives. It does not set a sign-out URI: open the app
  under **Developers → Applications** and add `http://localhost:3000/` under
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
  --name="My Next.js app" \
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
    "name": "My Next.js app",
    "type": "web",
    "redirect_uris": ["http://localhost:3000/auth/callback"],
    "post_logout_redirect_uris": ["http://localhost:3000/"]
  }'
```

The `201` response carries `client_id` and `client_secret`. The secret is returned once; a
retry with the same `Idempotency-Key` returns the app with `client_secret: null`.

## 2. Install the SDK

```bash
npm install @cboxdk/id-js jose
```

`@cboxdk/id-js` runs the sign-in. It does not keep a session for your app, so this
quickstart uses [`jose`](https://github.com/panva/jose) (already a dependency of the SDK)
to sign a small session cookie. Use your own session layer if you have one.

## 3. Set the environment

```bash
# .env.local
CBOX_ID_ISSUER=https://<environment>.cboxid.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_CLIENT_SECRET=csec_…
CBOX_ID_REDIRECT_URI=http://localhost:3000/auth/callback

# Read by the code below, not by the SDK:
CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:3000/
SESSION_SECRET=<at least 32 random characters, e.g. from: openssl rand -base64 32>
```

`createCboxId()` reads `CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_ID`, `CBOX_ID_CLIENT_SECRET` and
`CBOX_ID_REDIRECT_URI`, and throws a `ConfigurationError` naming what is missing.

```ts
// lib/cbox.ts
import { createCboxId } from '@cboxdk/id-js/nextjs';

export const cboxId = createCboxId();
```

```ts
// lib/session.ts
import { SignJWT, jwtVerify } from 'jose';
import { cookies } from 'next/headers';

const key = new TextEncoder().encode(process.env.SESSION_SECRET);

export type Session = {
  sub: string;
  email: string | null;
  name: string | null;
  idToken: string | null;
};

export function sealSession(session: Session): Promise<string> {
  return new SignJWT({ ...session })
    .setProtectedHeader({ alg: 'HS256' })
    .setIssuedAt()
    .setExpirationTime('8h')
    .sign(key);
}

export async function getSession(): Promise<Session | null> {
  const token = (await cookies()).get('session')?.value;
  if (!token) return null;
  try {
    const { payload } = await jwtVerify(token, key);
    return payload as unknown as Session;
  } catch {
    return null;
  }
}
```

## 4. The sign-in route

```ts
// app/auth/sign-in/route.ts
import { cboxId } from '@/lib/cbox';

export const GET = () => cboxId.signIn();
```

`signIn()` builds the authorization request (PKCE `S256`, `state`, nonce), stores those
values in short-lived httpOnly cookies, and redirects to your environment's sign-in page.
Link to it from anywhere: `<a href="/auth/sign-in">Sign in</a>`.

## 5. The callback

```ts
// app/auth/callback/route.ts
import { NextResponse, type NextRequest } from 'next/server';
import { cboxId } from '@/lib/cbox';
import { sealSession } from '@/lib/session';

export async function GET(request: NextRequest) {
  // Verifies state, PKCE and the id_token; throws InvalidStateError or AuthenticationError.
  const user = await cboxId.callback(request);

  const response = NextResponse.redirect(new URL('/dashboard', request.url));
  response.cookies.set(
    'session',
    await sealSession({ sub: user.id, email: user.email, name: user.name, idToken: user.idToken }),
    { httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production', path: '/' },
  );
  return response;
}
```

`user.id` is the stable subject. Key your own user records on it, not on the email.

## 6. Protect a route

```tsx
// app/dashboard/page.tsx
import { redirect } from 'next/navigation';
import { getSession } from '@/lib/session';

export default async function Dashboard() {
  const session = await getSession();
  if (!session) redirect('/auth/sign-in');

  return (
    <main>
      <p>Signed in as {session.email}</p>
      <a href="/auth/sign-out">Sign out</a>
    </main>
  );
}
```

Run `npm run dev`, open `http://localhost:3000/dashboard`, and you are sent to sign in and
back.

## 7. Sign out

```ts
// app/auth/sign-out/route.ts
import { NextResponse, type NextRequest } from 'next/server';
import { cboxId } from '@/lib/cbox';
import { getSession } from '@/lib/session';

export async function GET(request: NextRequest) {
  const session = await getSession();
  const url = await cboxId.signOutUrl(
    process.env.CBOX_ID_POST_LOGOUT_REDIRECT_URI,
    session?.idToken ?? undefined,
  );

  const response = NextResponse.redirect(url ?? new URL('/', request.url));
  response.cookies.delete('session');
  return response;
}
```

`signOutUrl()` returns the environment's end-session URL (`/oauth/logout`), or `null` if
the environment advertises none. Cbox ID sends the person back only to a URI listed under
**Sign-out URIs** on the app, matched character for character. Passing the `id_token` as
the hint lets Cbox ID end the person's sessions everywhere, not only in this browser.

## Next steps

- [Workspaces & organizations](../core-concepts/workspaces-and-organizations.md) — where
  your app sits in Cbox ID.
- [Organizations in your app](../getting-started/organizations-in-your-app.md) — bind a
  sign-in to one of your customers, and switch between them (`cboxId.switchOrganization()`).
- [Enterprise SSO](../guides/single-sign-on.md) — let a customer sign in with their own
  identity provider.
- [Agents and MCP](../guides/agents-and-mcp.md) — manage the environment from an agent.
- [Reference](../reference/_index.md).
- The SDK: [cboxdk/id-js](https://github.com/cboxdk/id-js). For prebuilt sign-in and user
  widgets, add [`@cboxdk/id-react`](https://github.com/cboxdk/id-react).

## When it doesn't work

| What you see | What to do |
|---|---|
| Cbox ID shows "The redirect URI does not match any registered for this application." | `CBOX_ID_REDIRECT_URI` must equal one of the app's **Redirect URIs** exactly: scheme, host, port, path and trailing slash. |
| An issuer error, or the callback refuses the `id_token` | `CBOX_ID_ISSUER` must be the `issuer` from discovery, verbatim, with no trailing slash. |
| `invalid_client` on the callback | Wrong client ID or secret. A lost secret cannot be shown again: rotate it on the app's page. |
| A 404 from `/.well-known/openid-configuration` | You pointed at `cboxid.com`. Use the environment's own host. |

More in [Integrate your app](../getting-started/integrate-your-app.md#when-it-does-not-work).
