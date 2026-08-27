<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\PageController;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ShellPayload;
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
        $route = $this->scope->routeName($name);

        return $parameters === null ? route($route) : route($route, $parameters);
    }

    /**
     * The organization this page acts within, or null for the whole-environment view.
     *
     * Null has to mean "an environment administrator has not chosen one yet" and never
     * "this member has no organization" — the nullable reader answers null for both, and
     * on the organization plane that second case widens every scoped query on the page
     * from one organization to the whole environment.
     */
    protected function actingOrganizationId(): ?string
    {
        return $this->scope->plane() === ConsolePlane::Environment
            ? $this->scope->organizationId()
            : $this->scope->requireOrganizationId();
    }
}
