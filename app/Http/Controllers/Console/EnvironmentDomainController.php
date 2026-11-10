<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\OrgPlane\Environments\RemoveEnvironmentDomain;
use App\Actions\OrgPlane\Environments\RequestEnvironmentDomain;
use App\Actions\OrgPlane\Environments\VerifyEnvironmentDomain;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Console\RequestEnvironmentDomainRequest;
use App\Platform\Console\OrganizationLanding;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › ENVIRONMENT DOMAINS — serving an environment's identity endpoints on the
 * customer's own domain, proved by a DNS TXT record.
 *
 * EVERY READ GOES THROUGH THE SAME RESOLUTION AS EVERY WRITE, which is the fix this page
 * exists to keep. The Volt version funnelled its writes through a reachability guard and
 * then read the challenge by passing the raw selected id to a service that resolves it
 * with a bare `Environment::find()` — and `Environment` is the tenancy root, with no scope
 * of its own. A member could name an environment belonging to a different account and read
 * back its unannounced domain and the `cbox-id-domain-verification=…` TXT proof; a bogus
 * id 500'd rather than 404'd, which was its own tell.
 *
 * A READ IS REDIRECTED AND A WRITE IS REFUSED. Somebody arriving here who may not manage
 * environments followed a link or typed a URL, and the console sends them where they can
 * be; a write that fails the same question has nowhere to be sent.
 *
 * EVERY WRITE IS AN ACTION (`app/Actions/OrgPlane/Environments/*Domain`), the same one a
 * workspace key runs at `/api/v1/workspace/environments/{id}/domain` — and it resolves
 * the environment against the ones this person may reach before any service sees the
 * id, so somebody else's environment is not found rather than refused.
 */
final readonly class EnvironmentDomainController extends ConsoleController
{
    public function index(Request $request, Memberships $members, EnvironmentDomains $domains): Response|RedirectResponse
    {
        if ($this->scope->capabilities()?->canManageEnvironments() !== true) {
            return redirect()->to(app(OrganizationLanding::class)->url());
        }

        $reachable = $this->reachable($members);
        $environments = Environment::query()->whereIn('id', $reachable)->orderBy('created_at')->get(['id', 'name', 'domain']);

        // In the URL rather than in component state: which environment is being given a
        // domain is worth linking to, and it is the same id every write is fenced on.
        $selected = trim($request->string('environment')->toString());

        if (! in_array($selected, $reachable, true)) {
            $selected = (string) ($environments->first()->id ?? '');
        }

        // Resolved in the query, fenced on what this person may reach — never picked out of a
        // loaded list, which is only as safe as the query that happened to build it.
        $environment = $selected === ''
            ? null
            : Environment::query()->whereIn('id', $reachable)->whereKey($selected)->first(['id', 'name', 'domain']);
        $challenge = $environment === null ? null : $domains->challenge($environment->id);

        return $this->page('console/environment-domains', __('console.environment_domains_cdec5a9f'), [
            'help' => HelpProps::for(HelpTopic::EnvironmentDomains),
            'environments' => $environments->map(fn (Environment $item): array => [
                'id' => $item->id,
                'name' => $item->name,
            ])->all(),
            'selected' => $selected,
            'verifiedDomain' => $environment?->domain,
            'challenge' => $challenge === null ? null : [
                'domain' => $challenge->domain,
                'recordName' => $challenge->recordName,
                'recordValue' => $challenge->recordValue,
                'verified' => $challenge->verified,
            ],
            'urls' => [
                'request' => $this->url('orgs.environment-domains.store'),
                'verify' => $this->url('orgs.environment-domains.verify'),
                'destroy' => $this->url('orgs.environment-domains.destroy'),
            ],
        ]);
    }

    /**
     * Ask for a domain through {@see RequestEnvironmentDomain} — the action a workspace key
     * runs too. The environment is resolved there, against the environments this person
     * may reach, before any service sees the id: one they cannot reach is not found.
     */
    public function store(RequestEnvironmentDomainRequest $request): RedirectResponse
    {
        abort_unless($this->scope->capabilities()?->canManageEnvironments() === true, 403);

        $result = $this->act(RequestEnvironmentDomain::class, [
            'environment_id' => $request->environmentId(),
            'domain' => $request->domain(),
        ], ['domain' => 'domain'], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.add_the_txt_record_below_then_verify_1c7edecb'));
    }

    /**
     * Verify through {@see VerifyEnvironmentDomain}. A record that is not visible yet is
     * NOT AN ERROR ON THE DOMAIN FIELD: the domain is fine and the record is probably
     * right — DNS simply has not propagated — so the refusal lands on `verify` and says
     * what to do rather than implying the value needs correcting.
     */
    public function verify(Request $request): RedirectResponse
    {
        abort_unless($this->scope->capabilities()?->canManageEnvironments() === true, 403);

        $result = $this->act(VerifyEnvironmentDomain::class, [
            'environment_id' => trim($request->string('environment')->toString()),
        ], fallback: 'verify');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var array{domain: string|null} $domain */
        $domain = $result->payload;

        return back()->with('status', __('console._part1_is_verified_and_now_serves_this_environment_acb1a79c', ['part1' => $domain['domain'] ?? 'The domain']));
    }

    /** Remove the domain through {@see RemoveEnvironmentDomain}, recorded on the workspace's trail. */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($this->scope->capabilities()?->canManageEnvironments() === true, 403);

        $result = $this->act(RemoveEnvironmentDomain::class, [
            'environment_id' => trim($request->string('environment')->toString()),
        ], ['domain' => 'domain'], 'domain');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('console.custom_domain_removed_51af6d53'));
    }

    /**
     * The environments this administrator may actually reach.
     *
     * IN THE PLATFORM ROOT. `memberships` is environment-owned and the console is served on
     * whichever host the deployment puts it on — so asked directly this answers "no
     * environments" for somebody who reaches several, and the page silently shows them
     * nothing.
     *
     * @return list<string>
     */
    private function reachable(Memberships $members): array
    {
        $organizationId = $this->scope->organizationId();
        $actorId = $this->scope->actorId();

        if ($organizationId === null || $actorId === '') {
            return [];
        }

        return app(PlatformRoot::class)->run(
            fn (): array => $members->accessibleEnvironmentIds($organizationId, $actorId),
        ) ?? [];
    }
}
