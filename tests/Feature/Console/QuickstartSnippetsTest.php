<?php

declare(strict_types=1);

use App\Platform\Connect\ConnectSnippets;
use App\Platform\Connect\QuickstartBlock;
use App\Platform\Connect\QuickstartFramework;
use App\Platform\Connect\QuickstartSources;
use Cbox\Id\OAuthServer\Models\Client;

/*
|--------------------------------------------------------------------------
| Get started's code IS the quickstart page's code, and it sets what the SDK reads.
|--------------------------------------------------------------------------
|
| The console's snippets and docs/quickstarts were written separately, and the console's
| were the wrong ones: `CBOX_ID_REDIRECT_URI` for a Laravel SDK that reads
| `CBOX_ID_REDIRECT` (a copied block answered 503), id-go calls that do not compile, a
| browser-only React sign-in the token endpoint's missing CORS headers make impossible,
| Nuxt and Python wired by hand instead of with their SDKs. Each block is now verbatim a
| block of the page, and each environment block sets the variables the SDK actually reads.
*/

/**
 * The variables each SDK reads from the environment ITSELF, read off its source:
 *
 *  - cboxdk/laravel-id-client, config/cbox-id-client.php: issuer, client_id, client_secret,
 *    and `redirect` from CBOX_ID_REDIRECT;
 *  - @cboxdk/id-js, src/nextjs.ts `createCboxId()`: the four below;
 *  - @cboxdk/id-nuxt, src/module.ts: the four, the sign-out return and the session password.
 *
 * id-go, id-python and id-js's `CboxIdClient` (React's server) read none: the quickstart's
 * code passes the values in, so its own reads are what must match ({@see quickstartCodeReads()}).
 *
 * @return list<string>
 */
function sdkReads(QuickstartFramework $framework): array
{
    return match ($framework) {
        QuickstartFramework::Laravel => ['CBOX_ID_ISSUER', 'CBOX_ID_CLIENT_ID', 'CBOX_ID_CLIENT_SECRET', 'CBOX_ID_REDIRECT'],
        QuickstartFramework::NextJs => ['CBOX_ID_ISSUER', 'CBOX_ID_CLIENT_ID', 'CBOX_ID_CLIENT_SECRET', 'CBOX_ID_REDIRECT_URI'],
        QuickstartFramework::Nuxt => ['CBOX_ID_ISSUER', 'CBOX_ID_CLIENT_ID', 'CBOX_ID_CLIENT_SECRET', 'CBOX_ID_REDIRECT_URI', 'CBOX_ID_POST_LOGOUT_REDIRECT_URI', 'CBOX_ID_SESSION_PASSWORD'],
        QuickstartFramework::Go, QuickstartFramework::Python, QuickstartFramework::React => [],
    };
}

/**
 * The environment variables a piece of code reads: `process.env.X`, `process.env['X']`,
 * `os.Getenv("X")`, `os.environ["X"]`.
 *
 * @return list<string>
 */
function quickstartCodeReads(string $code): array
{
    preg_match_all('/process\.env(?:\.|\[\')([A-Z][A-Z0-9_]*)|os\.Getenv\("([A-Z][A-Z0-9_]*)"\)|os\.environ\["([A-Z][A-Z0-9_]*)"\]/', $code, $matches);

    return array_values(array_unique(array_filter([...$matches[1], ...$matches[2], ...$matches[3]], static fn (string $name): bool => $name !== '')));
}

/**
 * The bodies of a quickstart page's fenced code blocks.
 *
 * @return list<string>
 */
function quickstartPageBlocks(QuickstartFramework $framework): array
{
    $markdown = (string) file_get_contents(base_path('docs/'.$framework->docsPath().'.md'));
    preg_match_all('/^```[a-z]*\n(.*?)\n```$/ms', $markdown, $matches);

    return $matches[1];
}

it('shows only blocks the framework\'s quickstart page has, verbatim', function (QuickstartFramework $framework): void {
    $source = QuickstartSources::of($framework);
    $page = quickstartPageBlocks($framework);
    $markdown = (string) file_get_contents(base_path('docs/'.$framework->docsPath().'.md'));

    foreach ([...$source->install, ...$source->code] as $block) {
        expect($page)->toContain($block->code);
    }

    // A run command is one of the page's blocks, or its inline code for a one-liner.
    foreach ($source->run as $block) {
        expect(in_array($block->code, $page, true) || str_contains($markdown, '`'.$block->code.'`'))
            ->toBeTrue("`{$block->code}` is not on the {$framework->label()} quickstart page");
    }

    // The environment block is the page's, less its file-name comment.
    $env = collect($page)->first(fn (string $body): bool => str_contains($body, "\n") && substr($body, strpos($body, "\n") + 1) === $source->env);

    expect($env)->not->toBeNull("the {$framework->label()} environment block is not the page's");
})->with(QuickstartFramework::cases());

it('sets every variable the SDK reads, and nothing the SDK reads under another name', function (QuickstartFramework $framework): void {
    $source = QuickstartSources::of($framework);
    $names = $source->envNames();

    foreach (sdkReads($framework) as $name) {
        expect($names)->toContain($name);
    }

    // Everything the code shown reads is in the block shown — set, or offered commented out
    // where it is optional (React's secret, for a Web app only). NODE_ENV is the runtime's.
    preg_match_all('/^(?:# )?([A-Z][A-Z0-9_]*)=/m', $source->env, $offered);

    foreach ($source->code as $block) {
        foreach (array_diff(quickstartCodeReads($block->code), ['NODE_ENV']) as $name) {
            expect($offered[1])->toContain($name);
        }
    }

    // And every CBOX_ID_ variable it sets is read by somebody: the SDK, or the page's code.
    $pageReads = collect(quickstartPageBlocks($framework))->flatMap(fn (string $code): array => quickstartCodeReads($code))->all();

    foreach (array_filter($names, static fn (string $name): bool => str_starts_with($name, 'CBOX_ID_')) as $name) {
        expect(in_array($name, sdkReads($framework), true) || in_array($name, $pageReads, true))
            ->toBeTrue("{$framework->label()} sets {$name}, which nothing reads");
    }
})->with(QuickstartFramework::cases());

it('names the Laravel callback variable the way the SDK reads it', function (): void {
    $names = QuickstartSources::of(QuickstartFramework::Laravel)->envNames();

    expect($names)->toContain('CBOX_ID_REDIRECT')->not->toContain('CBOX_ID_REDIRECT_URI');
});

it('runs the React sign-in on a server, with no browser-side token exchange and nothing for the bundle', function (): void {
    $source = QuickstartSources::of(QuickstartFramework::React);
    $code = implode("\n", array_map(static fn (QuickstartBlock $block): string => $block->code, $source->code));

    expect($code)->toContain("from '@cboxdk/id-js'")
        ->and(collect($source->code)->pluck('file')->all())->toContain('server.mjs')
        ->and($code)->not->toContain('sessionStorage')
        ->and($code)->not->toContain('import.meta.env')
        ->and($source->env)->not->toContain('VITE_');
});

it('uses each framework\'s own SDK', function (): void {
    expect(QuickstartSources::of(QuickstartFramework::Nuxt)->install[0]->code)->toBe('npm install @cboxdk/id-nuxt')
        ->and(QuickstartSources::of(QuickstartFramework::Python)->install[0]->code)->toContain('cbox-id-client @ git+https://github.com/cboxdk/id-python@')
        ->and(implode("\n", array_map(static fn (QuickstartBlock $block): string => $block->code, QuickstartSources::of(QuickstartFramework::Python)->code)))
        ->toContain('from cbox_id import')->not->toContain('authlib')
        // id-go's real signatures: two results, and a Callback/Stored pair.
        ->and(implode("\n", array_map(static fn (QuickstartBlock $block): string => $block->code, QuickstartSources::of(QuickstartFramework::Go)->code)))
        ->toContain('req, err := client.CreateAuthorizationRequest(cboxid.AuthParams{})')
        ->toContain('cboxid.Callback{')
        ->toContain('cboxid.Stored{');
});

it('fills the environment block in for the app, and never with its secret', function (QuickstartFramework $framework): void {
    $client = new Client;
    $client->forceFill(['client_id' => 'cid_quickstart', 'redirect_uris' => [$framework->redirectUri()]]);

    $snippet = app(ConnectSnippets::class)->quickstart($framework, $client, 'https://acme.cboxid.test');

    expect($snippet->env)->toContain('CBOX_ID_ISSUER=https://acme.cboxid.test')
        ->toContain('CBOX_ID_CLIENT_ID=cid_quickstart')
        ->not->toContain(QuickstartSources::ISSUER_PLACEHOLDER)
        ->not->toContain('='.QuickstartSources::CLIENT_ID_PLACEHOLDER)
        ->and($snippet->envNames())->toBe(QuickstartSources::of($framework)->envNames());

    if ($framework === QuickstartFramework::React) {
        expect($snippet->env)->not->toContain(ConnectSnippets::SECRET_PLACEHOLDER)
            ->and($snippet->envNames())->not->toContain('CBOX_ID_CLIENT_SECRET');
    } else {
        expect($snippet->env)->toContain('CBOX_ID_CLIENT_SECRET='.ConnectSnippets::SECRET_PLACEHOLDER);
    }
})->with(QuickstartFramework::cases());
