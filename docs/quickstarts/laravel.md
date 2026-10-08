---
title: Laravel
weight: 3
description: Sign people in to a Laravel app with cboxdk/laravel-id-client — register the app, add two routes, protect pages with the auth middleware, sign out.
---

# Laravel

You will build a Laravel app where people sign in through your Cbox ID environment, are
logged in as a local `User`, reach pages behind Laravel's `auth` middleware, and sign
out again.

You need PHP 8.4, Laravel 12 or 13, and the three things in
[Before you start](_index.md#before-you-start). Written against
`cboxdk/laravel-id-client` 0.13.

## 1. Create the app

Register a **Web app**: Laravel runs on a server, so it can keep a client secret. Use the
redirect URI `http://localhost:8000/auth/callback` and the sign-out URI
`http://localhost:8000/`.

**In the console.** Open the environment console (`https://<environment>.cboxid.com/admin`)
and choose one of:

- **Home → Get started.** Pick **Laravel**, optionally name the app, and create it. The
  page registers a Web app with the redirect URI above, shows the client secret once,
  gives you the environment block and starter routes, then waits for your first sign-in
  and tells you when it arrives. It does not set a sign-out URI: open the app under
  **Developers → Applications** and add `http://localhost:8000/` under **Sign-out URIs**.
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
  --name="My Laravel app" \
  --type=web \
  --redirect-uris=http://localhost:8000/auth/callback \
  --post-logout-redirect-uris=http://localhost:8000/
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
    "name": "My Laravel app",
    "type": "web",
    "redirect_uris": ["http://localhost:8000/auth/callback"],
    "post_logout_redirect_uris": ["http://localhost:8000/"]
  }'
```

The `201` response carries `client_id` and `client_secret`. The secret is returned once; a
retry with the same `Idempotency-Key` returns the app with `client_secret: null`.

## 2. Install the SDK

```bash
composer require cboxdk/laravel-id-client
php artisan vendor:publish --tag=cbox-id-client-config
```

Your users table needs a column for the Cbox ID subject, and a password is no longer
required for people who only sign in through Cbox ID:

```bash
php artisan make:migration add_cbox_id_to_users_table
```

```php
// database/migrations/xxxx_xx_xx_xxxxxx_add_cbox_id_to_users_table.php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('cbox_id')->nullable()->unique();
        $table->string('password')->nullable()->change();
    });
}
```

```bash
php artisan migrate
```

Add `cbox_id` to the `User` model's fillable attributes.

## 3. Set the environment

```dotenv
# .env
CBOX_ID_ISSUER=https://<environment>.cboxid.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_CLIENT_SECRET=csec_…
CBOX_ID_REDIRECT=http://localhost:8000/auth/callback
```

The callback variable is `CBOX_ID_REDIRECT`, not `CBOX_ID_REDIRECT_URI`; if you copied
the block from **Get started**, rename that line. The SDK reads
these through `config/cbox-id-client.php` and discovers every endpoint from the issuer. A
missing issuer, client ID or redirect is refused with `NotConfigured`, which names the
variable and renders as a 503.

## 4. The sign-in route

```php
// routes/web.php
use Cbox\Id\Client\Facades\CboxId;

Route::get('/auth/sign-in', fn () => CboxId::redirect())->name('login');
```

`CboxId::redirect()` builds the authorization request (PKCE `S256`, `state`, nonce),
keeps those values in the session, and redirects to your environment's sign-in page.
Naming the route `login` matters: it is where Laravel's `auth` middleware sends a guest.

## 5. The callback

```php
// routes/web.php
use App\Models\User;
use Illuminate\Http\Request;

Route::get('/auth/callback', function (Request $request) {
    $cbox = CboxId::authenticate($request); // verifies state, PKCE and the id_token

    $user = User::updateOrCreate(
        ['cbox_id' => $cbox->id],           // the stable subject
        ['email' => $cbox->email, 'name' => $cbox->name],
    );

    auth()->login($user);
    $request->session()->regenerate();
    $request->session()->put('cbox_id_token', $cbox->idToken);

    return redirect()->intended('/dashboard');
});
```

`authenticate()` returns a `CboxUser` and throws `InvalidState` on a forged or stale
callback and `AuthenticationFailed` otherwise, both under
`Cbox\Id\Client\Exceptions`. The `id_token` is kept only for the sign-out hint in step 7.

## 6. Protect a route

Use Laravel's own `auth` middleware:

```php
// routes/web.php
Route::middleware('auth')->get('/dashboard', function () {
    return 'Signed in as '.auth()->user()->email;
});
```

Run `php artisan serve`, open `http://localhost:8000/dashboard`, and you are sent to sign
in and back.

## 7. Sign out

```php
// routes/web.php
Route::post('/auth/sign-out', function (Request $request) {
    $url = CboxId::logoutUrl(
        returnTo: 'http://localhost:8000/',
        idTokenHint: $request->session()->get('cbox_id_token'),
    );

    auth()->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect($url ?? '/');
})->middleware('auth');
```

```blade
<form method="POST" action="/auth/sign-out">
    @csrf
    <button type="submit">Sign out</button>
</form>
```

`CboxId::logoutUrl()` returns the environment's end-session URL (`/oauth/logout`), or
`null` if it advertises none. Cbox ID sends the person back only to a URI listed under
**Sign-out URIs** on the app, matched character for character. The `id_token` hint lets
it end the person's sessions everywhere, not only in this browser.

## Next steps

- [Workspaces & organizations](../core-concepts/workspaces-and-organizations.md) — where
  your app sits in Cbox ID.
- [Organizations in your app](../getting-started/organizations-in-your-app.md) — bind a
  sign-in to one of your customers; `CboxId::switchOrganization($id)` and the
  `cbox-id.org` and `cbox-id.permission` middleware.
- [Enterprise SSO](../guides/single-sign-on.md) — let a customer sign in with their own
  identity provider.
- [Agents and MCP](../guides/agents-and-mcp.md) — manage the environment from an agent.
- [Reference](../reference/_index.md).
- The SDK: [cboxdk/laravel-id-client](https://github.com/cboxdk/laravel-id-client).

## When it doesn't work

| What you see | What to do |
|---|---|
| Cbox ID shows "The redirect URI does not match any registered for this application." | `CBOX_ID_REDIRECT` must equal one of the app's **Redirect URIs** exactly: scheme, host, port, path and trailing slash. |
| A 503 naming a missing variable | `NotConfigured`: set `CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_ID` and `CBOX_ID_REDIRECT`, then `php artisan config:clear`. |
| `AuthenticationFailed` mentioning the issuer or the `id_token` | `CBOX_ID_ISSUER` must be the `issuer` from discovery, verbatim, with no trailing slash. |
| `invalid_client` on the callback | Wrong client ID or secret. A lost secret cannot be shown again: rotate it on the app's page. |
| A 404 from `/.well-known/openid-configuration` | You pointed at `cboxid.com`. Use the environment's own host. |

More in [Integrate your app](../getting-started/integrate-your-app.md#when-it-does-not-work).
