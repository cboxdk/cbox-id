<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Props\Shared\AuthProps;
use App\Http\Props\Shared\FlashProps;
use App\Http\Props\Shared\ImpersonationProps;
use App\Platform\Appearance\BrandContext;
use App\Platform\CurrentEnvironment;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\Theme;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Http\Request;
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
        return [
            ...parent::share($request),

            'app' => [
                'name' => config('cbox-id.branding.name', 'Cbox ID'),
            ],

            // Painted by the root view before React exists; sent here so the shell can
            // reflect the current choice in the theme control without re-reading cookies.
            'theme' => Theme::preference(),

            'auth' => fn (): AuthProps => AuthProps::from(app(CurrentUser::class)),

            'brand' => fn () => app(BrandContext::class)->toProps(),

            // A sandbox realm is for development and testing, and the banner that says so
            // is a property of the ENVIRONMENT, not of any page in it.
            'sandbox' => fn (): bool => app(CurrentEnvironment::class)->isSandbox(),

            'impersonation' => fn (): ?ImpersonationProps => ImpersonationProps::from(
                app(Impersonation::class),
                app(Subjects::class),
            ),

            'flash' => fn (): FlashProps => FlashProps::from($request->session()),
        ];
    }
}
