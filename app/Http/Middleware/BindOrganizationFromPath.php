<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\CurrentUser;
use App\Platform\Impersonation;
use App\Platform\OrganizationAccess;
use App\Platform\Organizations\LastUsedOrganization;
use App\Platform\Organizations\OrganizationDirectory;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/orgs/{org}/…`: the organization is the one the ADDRESS names, and nothing else.
 *
 * The slug is read against the signed-in person's own memberships
 * ({@see OrganizationDirectory::find()}), so a slug that names somebody else's
 * organization is a 404, exactly like one that names nothing. The session never decides
 * which organization a page acts on: it only remembers the last one, for `/`.
 *
 * Moving between organizations by URL is still a switch, and it keeps the switch's
 * rules: a step-up confirmed for one organization does not carry to another, and the
 * session id rotates ({@see PlatformAuth::switchOrganization()}). A support session is
 * pinned to the organization it was opened for; any other slug is a 404 for it.
 *
 * After binding, `{org}` is a URL default for the rest of the request, so every
 * `route('orgs.…')` the page builds stays in this organization, and the parameter is
 * removed from the route so controllers keep the signatures they had.
 */
final class BindOrganizationFromPath
{
    public function __construct(
        private readonly CurrentUser $user,
        private readonly OrganizationDirectory $directory,
        private readonly PlaneResolver $planes,
        private readonly PlatformAuth $auth,
        private readonly Impersonation $impersonation,
        private readonly Organizations $organizations,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('org');

        abort_unless($route !== null && is_string($slug) && $slug !== '' && strlen($slug) <= 120, 404);
        // The Cbox organization console lives on the app host. A tenant host's own
        // organization console is a different surface with different organizations.
        abort_unless(! $this->planes->onCustomerEnvironment() && $this->user->check(), 404);

        $entry = $this->directory->find($this->user->id(), $slug);

        abort_if($entry === null, 404);

        $pinned = $this->impersonation->active()?->organizationId;
        abort_if(is_string($pinned) && $pinned !== '' && $pinned !== $entry->id, 404);

        $organization = $this->organizations->find($entry->id);
        abort_if($organization === null, 404);

        $refusal = OrganizationAccess::refusalPhrase($organization->status);
        abort_if($refusal !== null, 403, 'This organization has been '.$refusal.'. Contact your platform operator.');

        if ($this->user->organizationId() !== $entry->id) {
            $this->auth->switchOrganization($request, $entry->id);
        }

        $subject = $this->user->subject();
        $session = $this->user->session();

        if ($subject !== null && $session !== null) {
            $this->user->set($subject, $session, $organization, MembershipRole::tryFrom($entry->role));
        }

        $previous = URL::getDefaultParameters()['org'] ?? null;
        URL::defaults(['org' => $entry->slug]);
        $route->forgetParameter('org');

        try {
            $response = $next($request);
        } finally {
            URL::defaults(['org' => $previous]);
        }

        LastUsedOrganization::remember($response, $entry->slug);

        return $response;
    }
}
