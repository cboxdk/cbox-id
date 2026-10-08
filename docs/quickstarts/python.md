---
title: Python
weight: 6
description: Sign people in to a Flask app with the cbox-id-client package (installed from git, not PyPI) — register the app, add the sign-in and callback routes, protect a view, sign out.
---

# Python

You will build a Flask app where people sign in through your Cbox ID environment, reach a
protected `/dashboard`, and sign out again. The SDK is framework-agnostic; the same calls
work in FastAPI or Django.

You need Python 3.10 or later and the three things in
[Before you start](_index.md#before-you-start). Written against `cbox-id-client` v0.9.0.

## 1. Create the app

Register a **Web app**: a Flask server can keep a client secret. Use the redirect URI
`http://localhost:5000/auth/callback` and the sign-out URI `http://localhost:5000/`.

**In the console.** Open the environment console (`https://<environment>.cboxid.com/admin`)
and choose one of:

- **Home → Get started.** Pick **Python**, optionally name the app, and create it. The
  page registers a Web app with the redirect URI above, shows the client secret once,
  gives you the environment block and the code from steps 2 to 6 below, then waits for
  your first sign-in and tells you when it arrives. It does not set a sign-out URI: open the app under
  **Developers → Applications** and add `http://localhost:5000/` under **Sign-out URIs**.
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
  --name="My Flask app" \
  --type=web \
  --redirect-uris=http://localhost:5000/auth/callback \
  --post-logout-redirect-uris=http://localhost:5000/
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
    "name": "My Flask app",
    "type": "web",
    "redirect_uris": ["http://localhost:5000/auth/callback"],
    "post_logout_redirect_uris": ["http://localhost:5000/"]
  }'
```

The `201` response carries `client_id` and `client_secret`. The secret is returned once; a
retry with the same `Idempotency-Key` returns the app with `client_secret: null`.

## 2. Install the SDK

`cbox-id-client` is **not on PyPI**: `pip install cbox-id-client` finds no such package.
It has never been published, and its README says no release pipeline is planned. Install it from
a git tag of [cboxdk/id-python](https://github.com/cboxdk/id-python):

```bash
python -m venv .venv && . .venv/bin/activate
pip install flask python-dotenv "cbox-id-client @ git+https://github.com/cboxdk/id-python@v0.9.0"
```

Pin the tag, as above, so an install is repeatable. The import name is `cbox_id`.

## 3. Set the environment

```dotenv
# .env
CBOX_ID_ISSUER=https://<environment>.cboxid.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_CLIENT_SECRET=csec_…
CBOX_ID_REDIRECT_URI=http://localhost:5000/auth/callback
CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:5000/
FLASK_SECRET_KEY=<at least 32 random characters, e.g. from: openssl rand -base64 32>
```

The SDK reads no environment variables itself: `CboxIdConfig` takes the values, and the
code below reads these names. The `flask` command loads `.env` when `python-dotenv` is
installed.

```python
# app.py
import os
from functools import wraps

from flask import Flask, redirect, request, session, url_for

from cbox_id import CboxIdClient, CboxIdConfig, InvalidStateError

app = Flask(__name__)
app.secret_key = os.environ["FLASK_SECRET_KEY"]

cbox = CboxIdClient(
    CboxIdConfig(
        issuer=os.environ["CBOX_ID_ISSUER"],
        client_id=os.environ["CBOX_ID_CLIENT_ID"],
        client_secret=os.environ["CBOX_ID_CLIENT_SECRET"],
        redirect_uri=os.environ["CBOX_ID_REDIRECT_URI"],
    )
)
```

## 4. The sign-in route

```python
# app.py
@app.get("/auth/sign-in")
def sign_in():
    req = cbox.create_authorization_request()
    session["cbox"] = {"state": req.state, "verifier": req.code_verifier, "nonce": req.nonce}
    return redirect(req.url)
```

`create_authorization_request()` returns the authorize URL with a PKCE `S256` challenge, a
`state` and a nonce. Keep the three values in the session for the callback.

## 5. The callback

```python
# app.py
@app.get("/auth/callback")
def callback():
    pending = session.pop("cbox", None) or {}
    try:
        user = cbox.authenticate(
            code=request.args.get("code"),
            state=request.args.get("state"),
            error=request.args.get("error"),
            error_description=request.args.get("error_description"),
            expected_state=pending.get("state"),
            code_verifier=pending.get("verifier"),
            nonce=pending.get("nonce"),
        )
    except InvalidStateError:
        return redirect(url_for("sign_in"))  # stale or forged: start again

    session["user"] = {"id": user.id, "email": user.email, "name": user.name}
    session["id_token"] = user.id_token
    return redirect(url_for("dashboard"))
```

`authenticate()` checks the state, exchanges the code with the PKCE verifier, and verifies
the `id_token` against the environment's JWKS. It raises `InvalidStateError` on a state
mismatch and `AuthenticationError` on anything else, with the server's code on
`exc.error`. `user.id` is the stable subject: key your own records on it, not on the
email.

## 6. Protect a route

```python
# app.py
def login_required(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        if "user" not in session:
            return redirect(url_for("sign_in"))
        return view(*args, **kwargs)

    return wrapped


@app.get("/dashboard")
@login_required
def dashboard():
    return f"Signed in as {session['user']['email']}"
```

Run `flask --app app run`, open `http://localhost:5000/dashboard`, and you are sent to sign
in and back.

## 7. Sign out

```python
# app.py
@app.get("/auth/sign-out")
def sign_out():
    id_token = session.get("id_token")
    session.clear()
    url = cbox.logout_url(os.environ["CBOX_ID_POST_LOGOUT_REDIRECT_URI"], id_token_hint=id_token)
    return redirect(url or "/")
```

`logout_url()` returns the environment's end-session URL (`/oauth/logout`), or `None` if it
advertises none. Cbox ID sends the person back only to a URI listed under **Sign-out
URIs** on the app, matched character for character. The `id_token` hint lets it end the
person's sessions everywhere, not only in this browser.

## Next steps

- [Workspaces & organizations](../core-concepts/workspaces-and-organizations.md) — where
  your app sits in Cbox ID.
- [Organizations in your app](../getting-started/organizations-in-your-app.md) — bind a
  sign-in to one of your customers (`create_authorization_request(organization=…)`,
  `client.switch_organization()`).
- [Enterprise SSO](../guides/single-sign-on.md) — let a customer sign in with their own
  identity provider.
- [Agents and MCP](../guides/agents-and-mcp.md) — manage the environment from an agent.
- [Reference](../reference/_index.md).
- The SDK: [cboxdk/id-python](https://github.com/cboxdk/id-python).

## When it doesn't work

| What you see | What to do |
|---|---|
| Cbox ID shows "The redirect URI does not match any registered for this application." | `CBOX_ID_REDIRECT_URI` must equal one of the app's **Redirect URIs** exactly: scheme, host, port, path and trailing slash. |
| `AuthenticationError` mentioning the issuer or the `id_token` | `CBOX_ID_ISSUER` must be the `issuer` from discovery, verbatim, with no trailing slash. |
| `AuthenticationError` with `invalid_client` | Wrong client ID or secret. A lost secret cannot be shown again: rotate it on the app's page. |
| A 404 from `/.well-known/openid-configuration` | You pointed at `cboxid.com`. Use the environment's own host. |
| `pip install cbox-id-client` fails with "No matching distribution found" | It is not on PyPI. Install from the git tag in step 2. |
| Port 5000 is already in use (macOS) | AirPlay Receiver holds it. Turn it off, or run on another port and register that redirect URI too. |

More in [Integrate your app](../getting-started/integrate-your-app.md#when-it-does-not-work).
