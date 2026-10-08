<?php

declare(strict_types=1);

namespace App\Platform\Connect;

use App\Platform\AppKind;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * The "wire it up" examples for one registered app, one per SDK that can actually do the
 * job this app was registered for.
 *
 * The page used to show ONE snippet, in JavaScript, whatever the reader was building.
 * Someone integrating a Laravel service or a Go CLI got JavaScript and had to translate
 * it — from a screen that also holds a secret shown exactly once, so the translation
 * happens under time pressure.
 *
 * WHICH SDKS APPEAR FOLLOWS FROM THE KIND, because offering all of them everywhere is the
 * same failure one step along: a device-flow tab under a service-to-service app is a tab
 * that cannot work, and a reader has no way to know that before pasting it. `curl` is
 * always last and always present — it is the one that needs no SDK at all, and it is what
 * somebody debugging reaches for.
 */
class ConnectSnippets
{
    /**
     * @return list<Snippet>
     */
    public function for(AppKind $kind, Client $client, string $issuer): array
    {
        $scopes = array_values($client->scopes ?? []);
        $scopeLine = implode(' ', $scopes);
        $jsScopes = "'".implode("', '", $scopes)."'";
        $clientId = $client->client_id;
        $redirect = ($client->redirect_uris ?? [])[0] ?? 'https://app.example.com/auth/callback';

        return match ($kind) {
            AppKind::CliOrDevice => [
                $this->jsDevice($issuer, $clientId, $jsScopes),
                $this->goDevice($issuer, $clientId, $scopes),
                $this->curlDevice($issuer, $clientId, $scopeLine),
            ],
            AppKind::Service => [
                $this->curlClientCredentials($issuer, $clientId, $scopeLine),
                $this->phpService($scopeLine),
                $this->goService($issuer, $clientId),
            ],
            AppKind::Agent => [
                $this->curlCiba($issuer, $clientId, $scopeLine),
            ],
            default => [
                $this->jsCode($issuer, $clientId, $redirect, $jsScopes),
                $this->phpCode(),
                $this->goCode($issuer, $clientId, $redirect),
                $this->curlAuthorize($issuer, $clientId, $redirect, $scopeLine),
            ],
        };
    }

    /** What stands in for the client secret in a quickstart's environment block. */
    public const string SECRET_PLACEHOLDER = '<your client secret>';

    /**
     * The quickstart's second step for one framework: install, environment block, code, run —
     * the code exactly as `docs/quickstarts/{framework}.md` gives it ({@see QuickstartSources}),
     * with the environment block filled in for $client.
     *
     * THE SECRET IS NEVER HERE. A confidential app's secret exists in plaintext once, on the
     * flash channel of the response that created it; this block names it
     * {@see self::SECRET_PLACEHOLDER} and the page puts the flashed value in its place in the
     * browser — so the secret is not written into the page's props, which the browser keeps
     * in its history. A public app's block keeps the page's commented-out secret line as it
     * is: there is no secret to put there.
     */
    public function quickstart(QuickstartFramework $framework, Client $client, string $issuer): QuickstartSnippet
    {
        $source = QuickstartSources::of($framework);
        $redirect = ($client->redirect_uris ?? [])[0] ?? $framework->redirectUri();

        $fill = [
            QuickstartSources::ISSUER_PLACEHOLDER => $issuer,
            QuickstartSources::CLIENT_ID_PLACEHOLDER => $client->client_id,
            // The page's localhost callback is the one Get started registers; an app whose
            // first redirect differs gets its own.
            $framework->redirectUri() => $redirect,
        ];

        if ($framework->kind()->clientType() === ClientType::Confidential) {
            $fill[QuickstartSources::CLIENT_SECRET_PLACEHOLDER] = self::SECRET_PLACEHOLDER;
        }

        return $source->withEnv(strtr($source->env, $fill));
    }

    private function jsCode(string $issuer, string $clientId, string $redirect, string $scopes): Snippet
    {
        return new Snippet('js', 'JavaScript', <<<TS
            import { CboxIdClient } from '@cboxdk/id-js'

            const cbox = new CboxIdClient({
              issuer: '{$issuer}',
              clientId: '{$clientId}',
              redirectUri: '{$redirect}',
              scopes: [{$scopes}],
            })

            // On your sign-in route: persist state/codeVerifier/nonce, redirect to req.url
            const req = await cbox.createAuthorizationRequest()

            // On your callback route:
            const user = await cbox.authenticate({ params, stored })
            TS, 'npm i @cboxdk/id-js', 'https://www.npmjs.com/package/@cboxdk/id-js');
    }

    private function phpCode(): Snippet
    {
        return new Snippet('php', 'Laravel', <<<'PHP'
            use Cbox\Id\Client\Facades\CboxId;

            Route::get('/auth/redirect', fn () => CboxId::redirect());

            Route::get('/auth/callback', function (Request $request) {
                $cbox = CboxId::authenticate($request);   // verifies state, PKCE, id_token

                $user = User::updateOrCreate(
                    ['cbox_id' => $cbox->id],
                    ['email' => $cbox->email, 'name' => $cbox->name],
                );

                auth()->login($user);

                return redirect('/dashboard');
            });
            PHP, 'composer require cboxdk/laravel-id-client', 'https://packagist.org/packages/cboxdk/laravel-id-client');
    }

    private function goCode(string $issuer, string $clientId, string $redirect): Snippet
    {
        return new Snippet('go', 'Go', <<<GO
            client, _ := cboxid.New(ctx, cboxid.Config{
                Issuer:       "{$issuer}",
                ClientID:     "{$clientId}",
                ClientSecret: os.Getenv("CBOX_ID_CLIENT_SECRET"), // empty for a public app
                RedirectURI:  "{$redirect}",
            })

            // On your sign-in route: keep req.State, req.CodeVerifier and req.Nonce, then
            // send the person to req.URL.
            req, err := client.CreateAuthorizationRequest(cboxid.AuthParams{})

            // On your callback route, with q := r.URL.Query():
            user, err := client.Authenticate(ctx,
                cboxid.Callback{Code: q.Get("code"), State: q.Get("state"), Error: q.Get("error"), ErrorDescription: q.Get("error_description")},
                cboxid.Stored{State: req.State, CodeVerifier: req.CodeVerifier, Nonce: req.Nonce},
            )
            GO, 'go get github.com/cboxdk/id-go', 'https://github.com/cboxdk/id-go');
    }

    private function curlAuthorize(string $issuer, string $clientId, string $redirect, string $scopes): Snippet
    {
        return new Snippet('curl', 'curl', <<<SH
            # Everything an SDK does, as the two requests underneath it.
            # 1. Send the person here (PKCE S256 is required):
            {$issuer}/oauth/authorize
              ?response_type=code
              &client_id={$clientId}
              &redirect_uri={$redirect}
              &scope={$scopes}
              &code_challenge=<S256(verifier)>
              &code_challenge_method=S256
              &state=<random>

            # 2. Swap the code they come back with:
            curl -X POST {$issuer}/oauth/token \\
              -d grant_type=authorization_code \\
              -d code=<code> \\
              -d redirect_uri={$redirect} \\
              -d code_verifier=<verifier> \\
              -d client_id={$clientId} \\
              -d client_secret=\$CBOX_ID_CLIENT_SECRET
            SH);
    }

    private function jsDevice(string $issuer, string $clientId, string $scopes): Snippet
    {
        return new Snippet('js', 'JavaScript', <<<TS
            import { CboxIdClient } from '@cboxdk/id-js'

            const cbox = new CboxIdClient({
              issuer: '{$issuer}',
              clientId: '{$clientId}',
              scopes: [{$scopes}],
            })

            const auth = await cbox.requestDeviceAuthorization()
            console.log(`Open \${auth.verificationUri} and enter \${auth.userCode}`)

            // Blocks until they approve; honours the interval and backs off on slow_down.
            const user = await cbox.pollDeviceToken(auth)
            TS, 'npm i @cboxdk/id-js', 'https://www.npmjs.com/package/@cboxdk/id-js');
    }

    /**
     * @param  list<string>  $scopes
     */
    private function goDevice(string $issuer, string $clientId, array $scopes): Snippet
    {
        $goScopes = '"'.implode('", "', $scopes).'"';

        return new Snippet('go', 'Go', <<<GO
            client, _ := cboxid.NewDeviceClient(ctx, cboxid.DeviceConfig{
                Issuer:   "{$issuer}",
                ClientID: "{$clientId}",
                Scopes:   []string{{$goScopes}},
            })

            auth, _ := client.RequestDeviceAuthorization(ctx, cboxid.DeviceParams{})
            fmt.Printf("Open %s and enter %s\\n", auth.VerificationURI, auth.UserCode)

            user, err := client.PollDeviceToken(ctx, auth) // blocks until approved
            GO, 'go get github.com/cboxdk/id-go', 'https://github.com/cboxdk/id-go');
    }

    private function curlDevice(string $issuer, string $clientId, string $scopes): Snippet
    {
        return new Snippet('curl', 'curl', <<<SH
            # 1. Ask for a code, then print user_code + verification_uri to the terminal.
            curl -X POST {$issuer}/oauth/device_authorization \\
              -d client_id={$clientId} \\
              -d scope="{$scopes}"

            # 2. Poll, no faster than the `interval` seconds it answered with.
            #    authorization_pending = keep going · slow_down = add 5s, permanently
            #    access_denied / expired_token = stop
            curl -X POST {$issuer}/oauth/token \\
              -d grant_type=urn:ietf:params:oauth:grant-type:device_code \\
              -d device_code=<device_code> \\
              -d client_id={$clientId}
            SH);
    }

    private function curlClientCredentials(string $issuer, string $clientId, string $scopes): Snippet
    {
        return new Snippet('curl', 'curl', <<<SH
            # A token for the service itself — no person involved.
            curl -X POST {$issuer}/oauth/token \\
              -d grant_type=client_credentials \\
              -d client_id={$clientId} \\
              -d client_secret=\$CBOX_ID_CLIENT_SECRET \\
              -d scope="{$scopes}"
            SH);
    }

    private function phpService(string $scopes): Snippet
    {
        return new Snippet('php', 'Laravel', <<<PHP
            use Cbox\\Id\\Client\\Facades\\CboxId;

            // A machine token for this app itself, cached until it expires.
            \$token = CboxId::machineToken(['{$scopes}']);

            Http::withToken(\$token)->get('https://api.example.com/reports');
            PHP, 'composer require cboxdk/laravel-id-client', 'https://packagist.org/packages/cboxdk/laravel-id-client');
    }

    private function goService(string $issuer, string $clientId): Snippet
    {
        return new Snippet('go', 'Go', <<<GO
            client, _ := cboxid.New(ctx, cboxid.Config{
                Issuer:       "{$issuer}",
                ClientID:     "{$clientId}",
                ClientSecret: os.Getenv("CBOX_ID_CLIENT_SECRET"),
                RedirectURI:  "https://unused.example/cb",
            })

            token, err := client.MachineToken(ctx, cboxid.MachineTokenParams{})
            GO, 'go get github.com/cboxdk/id-go', 'https://github.com/cboxdk/id-go');
    }

    private function curlCiba(string $issuer, string $clientId, string $scopes): Snippet
    {
        return new Snippet('curl', 'curl', <<<SH
            # Ask a person to approve, on a device they already have.
            # binding_message is the sentence THEY read — write the specific action.
            curl -X POST {$issuer}/oauth/backchannel_authentication \\
              -u {$clientId}:\$CBOX_ID_CLIENT_SECRET \\
              -d login_hint=person@example.com \\
              -d binding_message="Deploy release 4.2 to production" \\
              -d scope="{$scopes}"

            # Then poll /oauth/token with the auth_req_id it returns:
            #   grant_type=urn:openid:params:grant-type:ciba
            SH);
    }
}
