# Laravel

Du bygger en Laravel-app, hvor folk logger ind gennem dit Cbox ID-miljø, bliver logget ind
som en lokal `User`, kan åbne sider bag Laravels `auth`-middleware og kan logge ud igen.

Du skal bruge PHP 8.4, Laravel 12 eller 13 og de tre ting under Før du starter. Skrevet
til `cboxdk/laravel-id-client` 0.13.

## 1. Opret appen

Registrer en **Webapp**: Laravel kører på en server, så den kan gemme en klienthemmelighed.
Brug omdirigerings-URI'en `http://localhost:8000/auth/callback` og logout-URI'en
`http://localhost:8000/`.

**I konsollen.** Åbn miljøkonsollen (`https://<environment>.cboxauth.com/admin`), og vælg en
af disse:

- **Hjem → Kom i gang.** Vælg **Laravel**, giv eventuelt appen et navn, og opret den. Siden
  registrerer en webapp med omdirigerings-URI'en ovenfor, viser klienthemmeligheden én gang,
  giver dig miljøblokken og koden fra trin 2 til 6 nedenfor, og venter så på dit første
  login og siger til, når det kommer. Den sætter ingen logout-URI: åbn appen under
  **Udviklere → Applikationer**, og tilføj `http://localhost:8000/` under **Logout-URI'er**.
- **Udviklere → Applikationer → Ny app.** Skriv et navn til appen, svar **Webapp** på
  *Hvilken type app er det?*, og udfyld **Omdirigerings-URI'er** og **Logout-URI'er**.

Når du opretter en app, udstedes en hemmelighed, så konsollen kan bede dig bekræfte, at det
er dig, først. Kopiér **klienthemmeligheden**, når den vises: den gemmes hashet og kan ikke
vises igen. Mister du den, så udskift den på appens side.

**Med `cbox`-CLI'en.** Log ind, vælg miljøet, og kør handlingen `apps.create`:

```bash
cbox login
cbox id use <environment>
cbox id apps create \
  --name="My Laravel app" \
  --type=web \
  --redirect-uris=http://localhost:8000/auth/callback \
  --post-logout-redirect-uris=http://localhost:8000/
```

At oprette en app er en kritisk handling. CLI'en spørger, før den sender den, og fordi du er
logget ind som dig selv, venter den derefter på din godkendelse: godkend anmodningen med den
samme kode på din enhed eller under **Godkendelser** i konsollen. Svaret indeholder
`client_id` og `client_secret`, som vises én gang.

**Med REST-API'et.** Brug en hemmelig nøgle (`cbid_env_…`) med scopet `apps:write` på
miljøets egen vært (se API-nøgler):

```bash
curl -X POST https://<environment>.cboxauth.com/api/v1/apps \
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

Svaret `201` indeholder `client_id` og `client_secret`. Hemmeligheden returneres én gang; et
nyt forsøg med samme `Idempotency-Key` returnerer appen med `client_secret: null`.

## 2. Installer SDK'et

```bash
composer require cboxdk/laravel-id-client
php artisan vendor:publish --tag=cbox-id-client-config
```

Din users-tabel skal have en kolonne til Cbox ID-subjektet, og en adgangskode er ikke længere
påkrævet for folk, der kun logger ind gennem Cbox ID:

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

Tilføj `cbox_id` til `User`-modellens fillable-felter.

## 3. Sæt miljøvariablerne

```dotenv
# .env
CBOX_ID_ISSUER=https://<environment>.cboxauth.com
CBOX_ID_CLIENT_ID=cid_…
CBOX_ID_CLIENT_SECRET=csec_…
CBOX_ID_REDIRECT=http://localhost:8000/auth/callback
```

Variablen til callback hedder `CBOX_ID_REDIRECT`, ikke `CBOX_ID_REDIRECT_URI`. SDK'et læser
dem gennem `config/cbox-id-client.php` og finder alle endpoints ud fra udstederen. Mangler
udsteder, klient-id eller omdirigering, afvises det med `NotConfigured`, som nævner
variablen og vises som 503.

## 4. Login-routen

```php
// routes/web.php
use Cbox\Id\Client\Facades\CboxId;

Route::get('/auth/sign-in', fn () => CboxId::redirect())->name('login');
```

`CboxId::redirect()` bygger autorisationsforespørgslen (PKCE `S256`, `state`, nonce), gemmer
værdierne i sessionen og sender videre til dit miljøs loginside. Det er vigtigt, at routen
hedder `login`: det er dertil, Laravels `auth`-middleware sender en gæst.

## 5. Callback

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

`authenticate()` returnerer en `CboxUser` og kaster `InvalidState` ved et forfalsket eller
forældet callback og ellers `AuthenticationFailed`, begge under
`Cbox\Id\Client\Exceptions`. `id_token` gemmes kun til logout-hintet i trin 7.

## 6. Beskyt en route

Brug Laravels egen `auth`-middleware:

```php
// routes/web.php
Route::middleware('auth')->get('/dashboard', function () {
    return 'Signed in as '.auth()->user()->email;
});
```

Kør `php artisan serve`, åbn `http://localhost:8000/dashboard`, og du bliver sendt til login
og tilbage igen.

## 7. Log ud

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

`CboxId::logoutUrl()` returnerer miljøets end-session-URL (`/oauth/logout`), eller `null`,
hvis miljøet ikke oplyser en. Cbox ID sender kun personen tilbage til en URI, der står under
**Logout-URI'er** på appen, tegn for tegn. Med `id_token`-hintet kan Cbox ID afslutte
personens sessioner overalt, ikke kun i denne browser.

## Næste skridt

- [Organisationer og kunder](/ai-assistant/guides/id-workspaces): hvor din app hører hjemme i
  Cbox ID.
- Organisationer i din app: knyt et login til en af dine kunder;
  `CboxId::switchOrganization($id)` og middlewaren `cbox-id.org` og `cbox-id.permission`.
- Single sign-on: lad en kunde logge ind med sin egen identitetsudbyder.
- Agenter og MCP: administrer miljøet fra en agent.
- Reference.
- SDK'et: [cboxdk/laravel-id-client](https://github.com/cboxdk/laravel-id-client).

## Når det ikke virker

| Det ser du | Det gør du |
|---|---|
| Cbox ID viser "Omdirigeringsadressen matcher ingen af de adresser, der er registreret for denne app." | `CBOX_ID_REDIRECT` skal være præcis lig med en af appens **Omdirigerings-URI'er**: scheme, vært, port, sti og afsluttende skråstreg. |
| En 503, der nævner en manglende variabel | `NotConfigured`: sæt `CBOX_ID_ISSUER`, `CBOX_ID_CLIENT_ID` og `CBOX_ID_REDIRECT`, og kør `php artisan config:clear`. |
| `AuthenticationFailed`, der nævner udstederen eller `id_token` | `CBOX_ID_ISSUER` skal være `issuer` fra discovery, ordret og uden afsluttende skråstreg. |
| `invalid_client` på callback | Forkert klient-id eller hemmelighed. En mistet hemmelighed kan ikke vises igen: udskift den på appens side. |
| En 404 fra `/.well-known/openid-configuration` | Du har peget på `cboxid.com`. Brug miljøets egen vært. |

Mere under Integrer din app.
