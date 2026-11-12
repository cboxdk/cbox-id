<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Assistant\SupportContext;
use App\Http\Props\Console\ApiEquivalentProps;
use App\Http\Props\Shared\AuthProps;
use App\Http\Props\Shared\EnvironmentProps;
use App\Http\Props\Shared\FlashProps;
use App\Http\Props\Shared\I18nProps;
use App\Http\Props\Shared\ImpersonationProps;
use App\Http\Props\Shell\ShellProps;
use App\Platform\Appearance\BrandContext;
use App\Platform\Connect\ActionSnippets;
use App\Platform\Console\ShellPayload;
use App\Platform\CurrentEnvironment;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\Locale\HostedTranslations;
use App\Platform\Shell\IdShell;
use App\Platform\Surfaces\AccountChrome;
use App\Platform\Theme;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Middleware;

/**
 * WHAT EVERY PAGE IS GIVEN WITHOUT ASKING.
 *
 * The rule for what belongs here is narrow, and worth stating because the temptation is
 * to widen it: a shared prop is something the CHROME draws, or something whose absence
 * would be a bug on a page that forgot to request it. The impersonation banner is the
 * canonical case — it is the only way out of an impersonation, and it must not be
 * possible for a page to be rendered without it.
 *
 * Everything else — the rows on a list, the endpoint being edited — belongs to the
 * controller that serves the page, where it can be scoped, authorized and typed.
 *
 * COST. This runs on every response, including the sign-in surfaces and the error pages,
 * so each prop is a closure and each closure asks the cheapest question first. Nothing
 * here touches the database for a guest.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * THE NAMES THE CHROME OWNS.
     *
     * Inertia merges shared props and page props into one bag, and the page wins. So a
     * controller that happens to call one of its own props `environment` does not add a
     * prop — it REPLACES the environment the sandbox banner and the realm badge read, and
     * the chrome throws while the page itself is perfectly correct. That is what took the
     * settings page down: it sent the environment RECORD it is about under the same name
     * as the environment the shell draws.
     *
     * Enumerated here rather than derived from {@see self::share()} because deriving it
     * would mean building every shared prop — including the ones that read the database —
     * to answer a question about names.
     *
     * @var list<string>
     */
    public const SHARED_KEYS = [
        'supportSessionUrl',
        'app',
        'theme',
        'auth',
        'brand',
        'environment',
        'impersonation',
        'flash',
        'shell',
        'tenantShell',
        'accountShell',
        'accountBack',
        'appCatalogUrl',
        'i18n',
        'apiEquivalents',
        // Inertia's own, from `parent::share()`.
        'errors',
    ];

    /**
     * The asset version. A deployment that changes the built bundle makes every open tab
     * do a hard navigation on its next visit rather than mounting new props into an old
     * bundle — which is the failure mode that produces "it works after a refresh".
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Native flash is excluded from browser history, unlike shared props.
        // Keep the shared shape for pages that consume it, but use this one-shot
        // channel for notifications so Back cannot replay a successful action.
        $flash = FlashProps::from($request->session());
        if ($flash->status !== null || $flash->error !== null) {
            Inertia::flash($flash->toArray());
        }

        return [
            ...parent::share($request),

            /*
             * Whole-product branding for a self-hosted install — the deployment's own
             * name, headline and trust line, overridable without touching a template.
             *
             * `trustLine` is EMPTY by default on purpose and must stay that way: a
             * self-hosted deployment must only claim what it can back, and shipping an
             * unearned certification badge in the default configuration would put words
             * in the mouth of everybody who installs this.
             */
            /*
             * The organization `/orgs/{org}/…` links are built for: the one in the path, or
             * the one this session is in. The client sets it as the `org` URL default, so a
             * route helper called without one stays in this organization, as `route()` does.
             */
            'org' => static function (): ?array {
                $slug = URL::getDefaultParameters()['org'] ?? null;

                return is_string($slug) && $slug !== '' ? ['slug' => $slug] : null;
            },

            'app' => fn (): array => [
                'name' => config('cbox-id.branding.name', 'Cbox ID'),
                // Translate the shipped headline; a deployment's own wording stays authored.
                'tagline' => config('cbox-id.branding.tagline') === trans('hosted.layout.hero_title', [], 'en')
                    ? __('hosted.layout.hero_title')
                    : config('cbox-id.branding.tagline'),
                'trustLine' => config('cbox-id.branding.trust_line'),
                'year' => date('Y'),
            ],

            // Painted by the root view before React exists; sent here so the shell can
            // reflect the current choice in the theme control without re-reading cookies.
            'theme' => Theme::preference(),

            'auth' => fn (): AuthProps => AuthProps::from(app(CurrentUser::class)),

            'brand' => fn () => app(BrandContext::class)->toProps(),

            // Which realm this is. A property of the ENVIRONMENT, not of any page in it —
            // and the sandbox banner and the realm badge both hang off it.
            'environment' => fn (): EnvironmentProps => EnvironmentProps::from(
                app(CurrentEnvironment::class),
            ),

            'impersonation' => fn (): ?ImpersonationProps => ImpersonationProps::from(
                app(Impersonation::class),
                app(Subjects::class),
            ),

            'flash' => fn (): FlashProps => FlashProps::from($request->session()),

            /*
             * THE CHROME. Null on a page that has none — sign-in, the admin portal, an
             * error — so the React shell asks one question rather than five.
             *
             * A closure, so a guest request never reaches the console-kit registry or the
             * membership lookups behind it. See {@see ShellPayload} for what goes in.
             */
            'supportSessionUrl' => fn (): ?string => app(SupportContext::class)->sessionUrl($request),
            // cbox-ui's `CboxShell`, built by laravel-console-kit ({@see IdShell}). On the
            // login host, which has no console, a person's own pages are drawn in the same
            // shell with only those pages in it ({@see AccountChrome::shell()}).
            'shell' => fn (): ?array => app(AccountChrome::class)->applies($request) ? app(AccountChrome::class)->shell($request) : app(IdShell::class)->build(),
            // A customer environment's white-labelled console keeps its own chrome.
            'tenantShell' => fn (): ?ShellProps => app(IdShell::class)->tenant(),
            // In login-host mode, the way back from a person's own pages on the login host
            // to where they came from ("Back to …", {@see AccountChrome}).
            'accountShell' => fn (): ?array => app(AccountChrome::class)->toProps($request),
            // On a customer environment's own host, the way back to the app that sent the
            // person to their own pages; the console chrome there draws it in its topbar.
            'accountBack' => fn (): ?array => app(AccountChrome::class)->tenantBack($request),
            // Where the app switcher reads the signed-in person's catalog (AppCatalogFeedController).
            'appCatalogUrl' => fn (): string => route('apps.catalog', [], false),

            /*
             * THE HOSTED PAGES' LANGUAGE — the locale, the picker's options, and the one
             * page group's strings. Null on the console, which is English and ships no
             * catalogue. A closure because the controller decides which group it is
             * rendering, and that has happened by the time Inertia resolves this.
             * {@see HostedTranslations}.
             */
            'i18n' => fn (): ?I18nProps => app(HostedTranslations::class)->toProps(),

            /*
             * "</> API" — the actions this console page hosts, keyed by name, with what the
             * browser needs to write each as curl, an MCP call, a CLI command and an SDK call
             * ({@see ActionSnippets}). Shared rather than per page so no page can forget it:
             * a form added to the console is answered by its API twin without anyone wiring
             * it, and `ApiEquivalentCoverageTest` holds every action to that. Empty off the
             * console, and never built for a page without chrome.
             */
            'apiEquivalents' => fn (): array => app(AccountChrome::class)->applies($request) || app(ShellPayload::class)->build() === null
                ? []
                : array_map(
                    static fn (ApiEquivalentProps $props): array => $props->toArray(),
                    app(ActionSnippets::class)->forRequest($request),
                ),
        ];
    }
}
