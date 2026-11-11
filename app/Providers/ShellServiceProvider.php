<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Console\PaletteActions;
use App\Platform\Console\ShellPayload;
use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\Shell\ApprovalsNotificationSource;
use App\Platform\Shell\IdHelpConfig;
use App\Platform\Shell\IdSearchProvider;
use App\Platform\Shell\IdShell;
use App\Platform\Shell\Levels\IdEnvironmentLevel;
use App\Platform\Shell\Levels\IdOrganizationLevel;
use App\Platform\Shell\Levels\IdProjectLevel;
use App\Platform\Shell\ShellRequest;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Console\Kit\Shell\BannerKind;
use Cbox\Console\Kit\Shell\PaletteAction;
use Cbox\Console\Kit\Shell\ShellBanner;
use Cbox\Console\Kit\Shell\ShellLink;
use Cbox\Console\Kit\Shell\ShellPerson;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

/**
 * THE SHARED SHELL, described once (laravel-console-kit `Console::shell()`), rendered by
 * cbox-ui's `CboxShell`. See {@see IdShell} for what is ID's own.
 *
 * Three surfaces, chosen by the URL rather than by a mode:
 *  - an organization's pages (`/orgs/{org}/…`) and its environments' consoles
 *    (`…/projects/{project}/environments/{environment}/…`): Organization › Project ›
 *    Environment;
 *  - platform admin (`/admin/…`): no levels, and a notice that it acts on the whole
 *    deployment;
 *  - a customer environment's own host: its white-labelled console keeps its own chrome.
 */
final class ShellServiceProvider extends ServiceProvider
{
    /** The query fragment an action link carries when it must be POSTed (the impersonation exit). */
    public const POST_ACTION = '#post';

    public function boot(): void
    {
        Console::shell()
            ->app('id', is_string($name = config('app.name')) && $name !== '' ? $name : 'Cbox ID', '/')
            ->person(static function (): ?ShellPerson {
                $user = app(CurrentUser::class);

                if (! $user->check()) {
                    $subject = ShellRequest::subjectId();
                    // The person lives in the platform root, whichever environment the page stands in.
                    $found = $subject === null ? null : app(PlatformRoot::class)->run(static fn () => app(Subjects::class)->find($subject));

                    return $found === null ? null : new ShellPerson((string) $found->id, (string) ($found->name ?: $found->email), null, null);
                }

                return new ShellPerson($user->id(), $user->name() !== '' ? $user->name() : (string) $user->email(), null, null);
            })
            ->levels(new IdOrganizationLevel, new IdProjectLevel, new IdEnvironmentLevel)
            ->search(new IdSearchProvider)
            ->actions(static function (Request $request): array {
                $legacy = app(ShellPayload::class)->build();
                $actions = array_map(
                    static fn (array $action): PaletteAction => PaletteAction::url($action['href'], $action['label'], $action['name']),
                    app(PaletteActions::class)->for($legacy->areas ?? []),
                );

                // An operator on an organization's console: platform admin and the platform's
                // own environment, which the old account menu linked and CboxShell's has no
                // room for, are a ⌘K away.
                if ($legacy?->platformHref !== null) {
                    $actions[] = PaletteAction::url($legacy->platformHref, __('console.platform_admin_69652f55'), 'platform.admin');
                }

                if ($legacy?->platformEnvironmentHref !== null) {
                    $actions[] = PaletteAction::url($legacy->platformEnvironmentHref, __('console.platform_environment_2676dfe4'), 'platform.environment');
                }

                return $actions;
            })
            ->help(new IdHelpConfig)
            ->helpTopic(static function (Request $request): ?string {
                $topic = $request->attributes->get(HelpProps::REQUEST_ATTRIBUTE);

                return is_string($topic) && $topic !== '' ? 'id.'.$topic : null;
            })
            ->notifications(new ApprovalsNotificationSource)
            ->banner(static function (Request $request): ?ShellBanner {
                $impersonation = app(Impersonation::class);
                $active = $impersonation->active();

                if ($active !== null) {
                    $email = app(Subjects::class)->find($active->subject)?->email;
                    $sentence = trans('console._part1_you_are_impersonating_part2_part3_for_support_ev_aaad4d39', [
                        'part1' => '', 'part2' => is_string($email) && $email !== '' ? $email : $active->subject, 'part3' => '', 'part4' => '', 'part5' => '', 'part6' => '',
                    ]);
                    $text = trim((string) preg_replace('/\s+/', ' ', is_string($sentence) ? $sentence : ''));
                    $exit = route('impersonation.exit', [], false).self::POST_ACTION;

                    return new ShellBanner(BannerKind::Impersonation, $text, new ShellLink(__('console.exit_impersonation_357bef8f'), $exit));
                }

                // A page that reaches past one organization says so over the page: platform
                // admin (the whole deployment) and the platform's own environment (everyone's
                // sign-in). The same sentence the console's "acts on" line has always said.
                $actsOn = app(ShellPayload::class)->build()?->actsOn;

                if ($actsOn !== null && $actsOn->critical) {
                    $label = $actsOn->kind === 'platform' ? __('console.platform_admin_69652f55').': '.$actsOn->label : $actsOn->label;

                    return new ShellBanner(BannerKind::Notice, $actsOn->detail !== null ? $label.'. '.$actsOn->detail : $label);
                }

                return null;
            })
            ->sandboxText(static fn (): string => __('console.sandbox_environment_for_testing_only_this_is_part1_your_4efdd306', ['part1' => __('console.not_254bb97b')]))
            // A record page falls back to its list on a switch: the nearest parent route that
            // is a page of either console's nav (`environment.users.show` → `environment.users`).
            ->listFallback(static function (Route $route): ?string {
                $name = $route->getName();

                if (! is_string($name)) {
                    return null;
                }

                $pages = app(ConsoleNavigation::class)->environment()->routes();

                foreach (Console::nav()->areas() as $area) {
                    foreach ($area->pages() as $page) {
                        $pages[] = $page->route;
                    }
                }

                $parts = explode('.', $name);

                while (count($parts) > 1) {
                    array_pop($parts);

                    if (in_array(implode('.', $parts), $pages, true)) {
                        return implode('.', $parts);
                    }
                }

                return null;
            })
            ->appCatalogUrl('/switch-app/catalog?include=available')
            ->account(
                profileUrl: 'account',
                switchAccountUrl: 'accounts',
                signOutUrl: 'logout',
            );
    }

    public function register(): void
    {
        $this->app->scoped(IdShell::class);
    }
}
