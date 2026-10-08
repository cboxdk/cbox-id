---
title: Go
weight: 5
description: Sign people in to a Go net/http server with github.com/cboxdk/id-go — register the app, add the sign-in and callback handlers, protect a route, sign out.
---

# Go

You will build a Go web server on `net/http` where people sign in through your Cbox ID
environment, reach a protected `/dashboard`, and sign out again.

You need Go 1.25 or later and the three things in
[Before you start](_index.md#before-you-start). Written against
`github.com/cboxdk/id-go` v0.12.

## 1. Create the app

Register a **Web app**: a Go server can keep a client secret. Use the redirect URI
`http://localhost:8080/auth/callback` and the sign-out URI `http://localhost:8080/`.

**In the console.** Open the environment console (`https://<environment>.cboxid.com/admin`)
and choose one of:

- **Home → Get started.** Pick **Go**, optionally name the app, and create it. The page
  registers a Web app with the redirect URI above, shows the client secret once, gives
  you the environment block and the code from steps 2 to 6 below, then waits for your
  first sign-in and tells you when it arrives. It does not set a sign-out URI: open the app under
  **Developers → Applications** and add `http://localhost:8080/` under **Sign-out URIs**.
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
  --name="My Go app" \
  --type=web \
  --redirect-uris=http://localhost:8080/auth/callback \
  --post-logout-redirect-uris=http://localhost:8080/
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
    "name": "My Go app",
    "type": "web",
    "redirect_uris": ["http://localhost:8080/auth/callback"],
    "post_logout_redirect_uris": ["http://localhost:8080/"]
  }'
```

The `201` response carries `client_id` and `client_secret`. The secret is returned once; a
retry with the same `Idempotency-Key` returns the app with `client_secret: null`.

## 2. Install the SDK

```bash
go mod init example.com/myapp
go get github.com/cboxdk/id-go
```

The package name is `cboxid`. It verifies the `id_token` with
[`go-oidc`](https://github.com/coreos/go-oidc) and runs the code exchange with
`golang.org/x/oauth2`. It does not keep a session for your app; this quickstart keeps one
in memory, which you replace with your own store.

## 3. Set the environment

```bash
# .env
CBOX_ID_ISSUER=https://<environment>.cboxid.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_CLIENT_SECRET=csec_…
CBOX_ID_REDIRECT_URI=http://localhost:8080/auth/callback
CBOX_ID_POST_LOGOUT_REDIRECT_URI=http://localhost:8080/
```

The SDK reads no environment variables itself: `cboxid.Config` takes the values, and the
code below reads these names. Go does not load `.env` files, so export them before you run:

```bash
set -a; . ./.env; set +a
```

Start `main.go` with the client and a minimal session:

```go
// main.go
package main

import (
	"context"
	"crypto/rand"
	"errors"
	"log"
	"net/http"
	"os"
	"sync"

	cboxid "github.com/cboxdk/id-go"
)

// An in-memory session per browser, for this quickstart only.
type session struct {
	pending *cboxid.AuthorizationRequest
	user    *cboxid.CboxUser
}

var (
	mu       sync.Mutex
	sessions = map[string]*session{}
)

func current(w http.ResponseWriter, r *http.Request) *session {
	mu.Lock()
	defer mu.Unlock()
	if c, err := r.Cookie("sid"); err == nil {
		if s, ok := sessions[c.Value]; ok {
			return s
		}
	}
	id := rand.Text()
	s := &session{}
	sessions[id] = s
	http.SetCookie(w, &http.Cookie{Name: "sid", Value: id, Path: "/", HttpOnly: true, SameSite: http.SameSiteLaxMode})
	return s
}

func main() {
	client, err := cboxid.New(context.Background(), cboxid.Config{
		Issuer:       os.Getenv("CBOX_ID_ISSUER"),
		ClientID:     os.Getenv("CBOX_ID_CLIENT_ID"),
		ClientSecret: os.Getenv("CBOX_ID_CLIENT_SECRET"),
		RedirectURI:  os.Getenv("CBOX_ID_REDIRECT_URI"),
	})
	if err != nil {
		log.Fatal(err) // discovery runs here: a wrong issuer fails at start-up
	}

	// Steps 4–7 add handlers here.

	log.Fatal(http.ListenAndServe(":8080", nil))
}
```

`cboxid.New` fetches the environment's discovery document when it starts, so a wrong
issuer stops the program before it serves anything.

## 4. The sign-in route

```go
// in main()
http.HandleFunc("GET /auth/sign-in", func(w http.ResponseWriter, r *http.Request) {
	req, err := client.CreateAuthorizationRequest(cboxid.AuthParams{})
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	current(w, r).pending = &req
	http.Redirect(w, r, req.URL, http.StatusFound)
})
```

`CreateAuthorizationRequest` returns the authorize URL with a PKCE `S256` challenge, a
`state` and a nonce, and an `ErrConfiguration` error when the request could only fail at
Cbox ID. Keep `State`, `CodeVerifier` and `Nonce` for the callback.

## 5. The callback

```go
// in main()
http.HandleFunc("GET /auth/callback", func(w http.ResponseWriter, r *http.Request) {
	s := current(w, r)
	if s.pending == nil {
		http.Redirect(w, r, "/auth/sign-in", http.StatusFound)
		return
	}
	q := r.URL.Query()
	user, err := client.Authenticate(r.Context(),
		cboxid.Callback{
			Code:             q.Get("code"),
			State:            q.Get("state"),
			Error:            q.Get("error"),
			ErrorDescription: q.Get("error_description"),
		},
		cboxid.Stored{State: s.pending.State, CodeVerifier: s.pending.CodeVerifier, Nonce: s.pending.Nonce},
	)
	s.pending = nil
	if errors.Is(err, cboxid.ErrInvalidState) {
		http.Redirect(w, r, "/auth/sign-in", http.StatusFound) // stale or forged: start again
		return
	}
	if err != nil {
		http.Error(w, err.Error(), http.StatusUnauthorized)
		return
	}
	s.user = user
	http.Redirect(w, r, "/dashboard", http.StatusFound)
})
```

`Authenticate` checks the state, exchanges the code with the PKCE verifier, and verifies
the `id_token`. `user.ID` is the stable subject: key your own records on it, not on the
email. Errors wrap `cboxid.ErrInvalidState`, `cboxid.ErrAuthentication` or
`cboxid.ErrConfiguration`; match them with `errors.Is`.

## 6. Protect a route

```go
// at package level
func requireUser(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if current(w, r).user == nil {
			http.Redirect(w, r, "/auth/sign-in", http.StatusFound)
			return
		}
		next(w, r)
	}
}
```

```go
// in main()
http.HandleFunc("GET /dashboard", requireUser(func(w http.ResponseWriter, r *http.Request) {
	w.Write([]byte("Signed in as " + current(w, r).user.Email))
}))
```

Run `go run .`, open `http://localhost:8080/dashboard`, and you are sent to sign in and
back.

## 7. Sign out

```go
// in main()
http.HandleFunc("GET /auth/sign-out", func(w http.ResponseWriter, r *http.Request) {
	s := current(w, r)
	idToken := ""
	if s.user != nil {
		idToken = s.user.IDToken
	}
	s.user = nil

	url := client.LogoutURLWithHint(os.Getenv("CBOX_ID_POST_LOGOUT_REDIRECT_URI"), idToken)
	if url == "" {
		url = "/"
	}
	http.Redirect(w, r, url, http.StatusFound)
})
```

`LogoutURLWithHint` returns the environment's end-session URL (`/oauth/logout`), or `""`
if it advertises none. Cbox ID sends the person back only to a URI listed under
**Sign-out URIs** on the app, matched character for character. The `id_token` hint lets
it end the person's sessions everywhere; `LogoutURL(returnTo)` without it signs out this
browser only.

## Next steps

- [Workspaces & organizations](../core-concepts/workspaces-and-organizations.md) — where
  your app sits in Cbox ID.
- [Organizations in your app](../getting-started/organizations-in-your-app.md) — bind a
  sign-in to one of your customers (`AuthParams.Organization`, `client.SwitchOrganization`).
- [Enterprise SSO](../guides/single-sign-on.md) — let a customer sign in with their own
  identity provider.
- [Agents and MCP](../guides/agents-and-mcp.md) — manage the environment from an agent.
- [Reference](../reference/_index.md).
- The SDK: [cboxdk/id-go](https://github.com/cboxdk/id-go). For a CLI, see
  [Sign in from a CLI](../getting-started/sign-in-from-a-cli.md) and `cboxid.NewDeviceClient`.

## When it doesn't work

| What you see | What to do |
|---|---|
| Cbox ID shows "The redirect URI does not match any registered for this application." | `CBOX_ID_REDIRECT_URI` must equal one of the app's **Redirect URIs** exactly: scheme, host, port, path and trailing slash. |
| Start-up fails with `discovery failed` and `issuer did not match` | `CBOX_ID_ISSUER` must be the `issuer` from discovery, verbatim, with no trailing slash. |
| `invalid_client` on the callback | Wrong client ID or secret. A lost secret cannot be shown again: rotate it on the app's page. |
| Start-up fails with `discovery failed` and a 404 | You pointed at `cboxid.com`. Use the environment's own host. |

More in [Integrate your app](../getting-started/integrate-your-app.md#when-it-does-not-work).
