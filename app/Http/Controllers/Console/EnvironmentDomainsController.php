<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Platform\Console\OrganizationFilter;
use App\Platform\Console\Vocabulary;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * AUTHENTICATION › DOMAINS — every organization's claimed email domains, in one list.
 *
 * THE THIRD OF THE ENTERPRISE TRIO. Enterprise SSO and Directory Sync each had an
 * environment-wide list with an Organization column, and a tab on each organization's page;
 * Domains had only the tab. So "which of my customers has proved acme.com?", "what is still
 * waiting on a DNS record?" — the questions a support engineer is asked by name, not by
 * organization — meant opening every organization in turn. WorkOS lists domains on their
 * own; this is that list.
 *
 * READ HERE, CHANGED THERE. A domain belongs to ONE organization, and verifying it or
 * turning capture on is that organization's decision, made on its Domains tab — where the
 * TXT record to publish, the verify button and the capture switch already are (and where
 * the `organizations.domains.*` actions are wired). Each row links there, so this page adds
 * a way to FIND a domain without adding a second writer for it.
 *
 * Bounded by the environment scope on the model, like every environment-wide list; the
 * `?organization=` chip narrows it to one ({@see OrganizationFilter}).
 */
final readonly class EnvironmentDomainsController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function __invoke(Request $request): Response
    {
        $this->scope->assertMayAdministerEnvironment();

        $filter = $this->organizationFilter();
        $query = $filter->apply(VerifiedDomain::query())->orderBy('domain');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('domain', 'like', '%'.addcslashes(mb_strtolower($term), '%_\\').'%');
        }

        $domains = $query->paginate(self::PER_PAGE)->withQueryString();
        $owners = $this->scope->organizationNames($domains->pluck('organization_id'));

        return $this->page('console/domains-overview', Vocabulary::DOMAINS, [
            'help' => HelpProps::for(HelpTopic::Domains),
            'domains' => $domains->getCollection()->map(fn (VerifiedDomain $domain): array => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'verified' => $domain->isVerified(),
                'capture' => (bool) $domain->capture,
                'organization' => $owners[$domain->organization_id] ?? $domain->organization_id,
                // Its organization's Domains tab: the TXT record, verify, capture, remove.
                'href' => route('environment.organizations.domains', ['organization' => $domain->organization_id]),
            ])->values()->all(),
            'pagination' => PaginationProps::from($domains),
            'search' => $term,
            'organizationFilter' => $this->organizationFilterProps($filter),
            'organizationsHref' => route('environment.organizations'),
            'ssoHref' => route('environment.connections'),
        ]);
    }
}
