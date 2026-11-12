<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\SessionController;
use App\Platform\Console\CustomerConsole;
use App\Platform\Console\OrganizationLanding;
use App\Platform\CurrentUser;
use App\Platform\Organizations\LastUsedOrganization;
use App\Platform\Organizations\OrganizationDirectory;
use App\Platform\Organizations\OrganizationEntry;
use App\Platform\Organizations\OrganizationRequired;
use App\Platform\PlaneResolver;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteRecord;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * `/orgs` and the doors around it: the picker, an organization's front door, and the
 * switch that keeps the page you are on.
 */
final readonly class OrganizationPickerController extends ConsoleController
{
    /**
     * Every organization this person can open. Never a gate in front of the console: `/`
     * goes straight to the last-used one, and this page is where the organization menu's
     * "All organizations" lands.
     */
    public function index(OrganizationDirectory $directory, CurrentUser $me): Response
    {
        abort_if(app(PlaneResolver::class)->onCustomerEnvironment(), 404);

        $current = $me->organizationId();

        return $this->page('console/orgs/index', __('console.organizations_2730183d'), [
            'organizations' => array_map(static fn (OrganizationEntry $entry): array => [
                'slug' => $entry->slug,
                'name' => $entry->name,
                'role' => $entry->role,
                'roleLabel' => self::roleLabel($entry->role),
                'href' => route('orgs.show', ['org' => $entry->slug]),
                'current' => $entry->id === $current,
            ], $directory->forSubject($me->id())),
        ]);
    }

    private static function roleLabel(string $role): string
    {
        return match (MembershipRole::tryFrom($role)) {
            MembershipRole::Owner => __('console.owner_4b1b8aa3'),
            MembershipRole::Admin => __('console.admin_c1c224b0'),
            MembershipRole::Developer => __('console.developer_3fb7b394'),
            MembershipRole::Viewer => __('console.viewer_678bfa6a'),
            default => __('console.member_7c968fb7'),
        };
    }

    /** `/orgs/{org}`: the organization's front door, which is the first page it has. */
    public function show(OrganizationLanding $landing): RedirectResponse
    {
        return redirect()->to($landing->url());
    }

    /**
     * Open another organization and stay on the same page there, or on that page's list
     * when the page was a record that belongs to the organization being left.
     *
     * Posted from the organization menu with the organization's id and the path the
     * person is on. The id is checked against their own memberships, never trusted; an
     * organization they cannot open answers with the picker.
     */
    public function switch(Request $request, OrganizationDirectory $directory, CurrentUser $me): SymfonyResponse
    {
        // A customer's own console switches between ITS organizations, in the session, as
        // it always has: `/orgs/{org}` addresses Cbox organizations, which it has none of.
        if (app(PlaneResolver::class)->onCustomerEnvironment()) {
            $answer = app()->call([app(SessionController::class), 'switchOrganization']);

            return $answer instanceof SymfonyResponse ? $answer : redirect()->route('dashboard');
        }

        $wanted = $request->string('organization')->toString();
        $target = null;

        foreach ($directory->forSubject($me->id()) as $entry) {
            if ($entry->id === $wanted || $entry->slug === $wanted) {
                $target = $entry;
            }
        }

        if ($target === null) {
            return redirect()->route('orgs.index');
        }

        return redirect()->to($this->samePageOrList($request->string('path')->toString(), $target->slug));
    }

    /**
     * The page at `$path` in the organization `$slug`: the same route when it names
     * nothing but the organization, its nav page (the list) when it names a record, and
     * the organization's front door for anything else.
     */
    private function samePageOrList(string $path, string $slug): string
    {
        $fallback = route('orgs.show', ['org' => $slug]);
        $route = $this->match($path);

        if ($route === null || ! str_starts_with((string) $route->getName(), 'orgs.') || $route->getName() === 'orgs.show') {
            return $fallback;
        }

        $name = (string) $route->getName();
        $records = array_values(array_filter($route->parameterNames(), static fn (mixed $name): bool => $name !== 'org'));

        if ($records === []) {
            return route($name, ['org' => $slug]);
        }

        $list = CustomerConsole::pageOf($name)[1] ?? null;

        return is_string($list) && $list !== $name && Route::has($list)
            && array_filter(Route::getRoutes()->getByName($list)?->parameterNames() ?? [], static fn (mixed $name): bool => $name !== 'org') === []
            ? route($list, ['org' => $slug])
            : $fallback;
    }

    private function match(string $path): ?RouteRecord
    {
        if ($path === '' || ! str_starts_with($path, '/orgs/') || strlen($path) > 2000) {
            return null;
        }

        try {
            return Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException) {
            return null;
        }
    }

    /**
     * `/` and the v2 overview for a person on the app host: the last-used organization,
     * else their first, else the picker.
     */
    public static function home(Request $request): RedirectResponse
    {
        $me = app(CurrentUser::class);
        $entry = LastUsedOrganization::resolve($request, app(OrganizationDirectory::class), $me->id(), $me->organizationId());

        if ($entry === null) {
            // In the Cbox realm a person with no organization answers one screen first
            // (an invitation, or a personal organization) and comes back here.
            $required = app(OrganizationRequired::class);

            return $required->appliesTo($me)
                ? redirect()->to($required->url())
                : redirect()->route('orgs.index');
        }

        // Straight to its first page when the session is already in it; another
        // organization goes through its front door, which binds it first.
        if ($entry->id !== $me->organizationId()) {
            return redirect()->route('orgs.show', ['org' => $entry->slug]);
        }

        URL::defaults(['org' => $entry->slug]);

        return redirect()->to(app(OrganizationLanding::class)->url());
    }
}
