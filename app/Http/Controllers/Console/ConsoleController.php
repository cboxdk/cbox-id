<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\PageController;
use App\Http\Middleware\BindConsoleOrganization;
use App\Http\Props\Shared\OrganizationFilterProps;
use App\Http\Props\Shared\OrganizationPickerProps;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\OrganizationFilter;
use App\Platform\Console\ShellPayload;
use App\Platform\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteRegistry;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * WHAT EVERY CONSOLE PAGE HAS IN COMMON: a title, and a plane.
 *
 * THE TITLE IS THE SERVER'S. It is rendered into `<title>` on the first byte rather than
 * set by the page's own `<Head>` after the bundle parses — otherwise the first paint of
 * every console page says nothing but the product's name, and a person restoring twenty
 * tabs gets twenty identical ones. It is stated once, here, and the client's title
 * callback reads the same prop, so the two cannot disagree.
 *
 * THE PLANE IS THE SERVER'S TOO. The two planes call the same capability by different
 * route names — `webhooks` and `environment.webhooks` — and a page is one file. It could
 * do the arithmetic itself, but then a rename would mean a button posting to the other
 * plane's URL, and nothing would say so. {@see self::url()} answers instead.
 */
abstract readonly class ConsoleController extends PageController
{
    use RunsActions;

    public function __construct(
        ResponseFactory $inertia,
        protected ConsoleScope $scope,
    ) {
        parent::__construct($inertia);
    }

    /**
     * Render a console page, titled and placed.
     *
     * The SECTION is the console's addition to the base: the word that distinguishes the
     * whole install from one customer on it. Half the platform pages share a name with a
     * page about the operator's own organization — "Usage" is this install's traffic in
     * one and one customer's bill in the other — and the platform section used to have a
     * shell of its own that said so in the tab.
     *
     * @param  array<string, mixed>  $props
     */
    protected function page(string $component, string $title, array $props = []): Response
    {
        return parent::page($component, $title, $props)
            ->withViewData(['section' => app(ShellPayload::class)->build()?->section]);
    }

    /**
     * A URL on THIS plane, by the route's organization-plane name.
     *
     * {@see ConsoleScope::routeName()} maps it; the environment plane prefixes the same
     * capability with `environment.`.
     *
     * @param  array<string, mixed>|string|null  $parameters
     */
    protected function url(string $name, array|string|null $parameters = null): string
    {
        $route = $this->routeName($name);

        return $parameters === null ? route($route) : route($route, $parameters);
    }

    /**
     * The route name for a capability on THIS page — {@see ConsoleScope::routeName()}, and
     * one step further on an organization's own page: where the environment console has the
     * same capability under `/admin/organizations/{organization}/…`
     * (`environment.organizations.vault.show`), a page inside one organization links there,
     * so following a link does not drop the organization it was about.
     */
    protected function routeName(string $name): string
    {
        $route = $this->scope->routeName($name);

        if ($this->scope->plane() === ConsolePlane::Environment && $this->scope->organizationId() !== null) {
            $nested = 'environment.organizations.'.$name;

            if (RouteRegistry::has($nested)) {
                return $nested;
            }
        }

        return $route;
    }

    /**
     * The organization this page ACTS ON, or null for the whole environment.
     *
     * On the organization plane, the member's own — never null: a session with no
     * organization is refused rather than handed the unfiltered query. On the environment
     * plane, the organization the URL names (`/admin/organizations/{organization}/…`, bound by
     * {@see BindConsoleOrganization}) — and nothing on any other URL.
     *
     * This and {@see self::organizationFilter()} are the two halves of what used to be one
     * organization remembered in the session: a hidden filter that decided both what a list
     * showed and where a write landed. A write is never steered by a query parameter, and a
     * list never by the route a write would need.
     */
    protected function routeOrganizationId(): ?string
    {
        return $this->scope->plane() === ConsolePlane::Environment
            ? $this->scope->organizationId()
            : $this->scope->requireOrganizationId();
    }

    /**
     * Which organization a LIST is narrowed to.
     *
     * The organization plane's own, always. On the environment plane the organization the
     * route names when there is one (a list drawn inside an organization's page), else
     * `?organization=` — checked against this environment, so an id from anywhere else
     * empties the list rather than unfiltering it ({@see OrganizationFilter}).
     */
    protected function organizationFilter(): OrganizationFilter
    {
        if ($this->scope->plane() === ConsolePlane::Organization || $this->scope->organizationId() !== null) {
            $id = $this->scope->requireOrganizationId();

            return OrganizationFilter::of($id, (string) $this->scope->organizationName());
        }

        return OrganizationFilter::fromRequest(request());
    }

    /**
     * The filter chip a list draws — null where there is nothing to choose: the organization
     * plane, and a list inside one organization's own page.
     */
    protected function organizationFilterProps(OrganizationFilter $filter): ?OrganizationFilterProps
    {
        if ($this->scope->plane() !== ConsolePlane::Environment || $this->scope->organizationId() !== null) {
            return null;
        }

        return new OrganizationFilterProps($filter, route('environment.lookup.organizations'));
    }

    /**
     * An Organization chip that GOES to the same page about one organization rather than
     * narrowing this one — for a page whose per-organization half is a different thing, not
     * a subset (an organization's own token vault, its brand profile, a data-subject export
     * from its trail). Null where there is nothing to go to: the organization plane, and a
     * page already about one organization.
     */
    protected function organizationJump(string $routeName): ?OrganizationFilterProps
    {
        if ($this->scope->plane() !== ConsolePlane::Environment || $this->scope->organizationId() !== null) {
            return null;
        }

        return new OrganizationFilterProps(
            OrganizationFilter::none(),
            route('environment.lookup.organizations'),
            route($routeName, ['organization' => OrganizationFilterProps::PLACEHOLDER]),
        );
    }

    /**
     * "For which organization?" on a create form — null on the organization plane, which
     * creates in its own and asks nothing, and inside an organization's own page, which
     * already says.
     *
     * `?organization=` on the form's link prefills AND LOCKS it: that is how an
     * organization's page sends somebody to a form for it. An id that names nothing here
     * prefills nothing.
     */
    protected function organizationPicker(bool $allowsEnvironment = false): ?OrganizationPickerProps
    {
        if ($this->scope->plane() !== ConsolePlane::Environment || $this->scope->organizationId() !== null) {
            return null;
        }

        $filter = OrganizationFilter::fromRequest(request());

        return new OrganizationPickerProps(
            lookupHref: route('environment.lookup.organizations'),
            selected: $filter->id === null ? null : ['id' => $filter->id, 'name' => (string) $filter->name],
            locked: $filter->id !== null,
            allowsEnvironment: $allowsEnvironment,
        );
    }

    /**
     * The organization a form is ABOUT, as far as the page can tell before it is submitted:
     * the organization plane's own, the one the route names, or the one the form's link named
     * (`?organization=`, from an organization's own page) when it is one of this environment.
     * Null when the environment console has not been told — the form asks.
     */
    protected function prefilledOrganizationId(): ?string
    {
        if ($this->scope->plane() !== ConsolePlane::Environment) {
            return $this->scope->requireOrganizationId();
        }

        return $this->scope->organizationId() ?? OrganizationFilter::fromRequest(request())->id;
    }

    /**
     * The organization a create form names, for a write about to be made — and bound for the
     * rest of this request, so every check after it (entitlements, ownership, the action's
     * own target) asks about THAT organization.
     *
     * The organization plane's own, whatever was posted. On the environment plane the
     * organization the route names, else the form's `organization` field, checked against
     * this environment: another environment's id, or a made-up one, is a field error and
     * never a write into whichever organization a default picked. Empty is "the whole
     * environment" only where `$allowsEnvironment` says that is a thing this form creates.
     *
     * @throws ValidationException
     */
    protected function chosenOrganizationId(Request $request, bool $allowsEnvironment = false, string $field = 'organization'): ?string
    {
        if ($this->scope->plane() === ConsolePlane::Organization || $this->scope->organizationId() !== null) {
            return $this->scope->requireOrganizationId();
        }

        $asked = $request->input('organization');

        if ($asked === null || $asked === '') {
            if ($allowsEnvironment) {
                return null;
            }

            throw ValidationException::withMessages([$field => 'Say which organization this is for.']);
        }

        if (! is_string($asked) || strlen($asked) > 64 || ! $this->scope->bindOrganization($asked)) {
            throw ValidationException::withMessages([$field => 'That organization is not in this environment.']);
        }

        return $asked;
    }

    /**
     * Whether an organization is entitled to a feature — asked of a NAMED organization, for a
     * page about one it has not bound. Null is "not chosen yet", which a form must not report
     * as "your plan does not include this".
     */
    protected function organizationEntitled(?string $organizationId, string $feature): bool
    {
        return $organizationId === null || app(Entitlements::class)->entitled($organizationId, $feature);
    }

    /**
     * A link to a create form, carrying the organization this page is about so the form
     * opens prefilled and locked to it ({@see self::organizationPicker()}).
     *
     * @param  array<string, mixed>|string|null  $parameters
     */
    protected function createUrl(string $name, array|string|null $parameters = null): string
    {
        $url = $this->url($name, $parameters);
        $organizationId = $this->scope->plane() === ConsolePlane::Environment ? $this->scope->organizationId() : null;

        return $organizationId === null
            ? $url
            : $url.(str_contains($url, '?') ? '&' : '?').http_build_query([OrganizationFilter::PARAMETER => $organizationId]);
    }
}
