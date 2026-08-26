<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Requests\Console\RequestEnvironmentDomainRequest;
use App\Platform\OrganizationActivity;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Exceptions\InvalidCustomDomain;
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
 */
final readonly class EnvironmentDomainController extends ConsoleController
{
    public function index(Request $request, Memberships $members, EnvironmentDomains $domains): Response|RedirectResponse
    {
        if ($this->scope->capabilities()?->canManageEnvironments() !== true) {
            return to_route('projects');
        }

        $reachable = $this->reachable($members);
        $environments = Environment::query()->whereIn('id', $reachable)->orderBy('created_at')->get(['id', 'name', 'domain']);

        // In the URL rather than in component state: which environment is being given a
        // domain is worth linking to, and it is the same id every write is fenced on.
        $selected = trim($request->string('environment')->toString());

        if (! in_array($selected, $reachable, true)) {
            $selected = (string) ($environments->first()->id ?? '');
        }

        $environment = $environments->firstWhere('id', $selected);
        $challenge = $environment === null ? null : $domains->challenge($environment->id);

        return $this->page('console/environment-domains', 'Environment domains', [
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
                'request' => $this->url('environment-domains.store'),
                'verify' => $this->url('environment-domains.verify'),
                'destroy' => $this->url('environment-domains.destroy'),
            ],
        ]);
    }

    public function store(RequestEnvironmentDomainRequest $request, Memberships $members, EnvironmentDomains $domains): RedirectResponse
    {
        $environmentId = $this->writable($request->environmentId(), $members);

        try {
            $domains->request($environmentId, $request->domain());
        } catch (InvalidCustomDomain $e) {
            // The service's own sentence, on the field that caused it: it names what is
            // wrong with the domain, which is something the person can act on.
            return back()->withInput()->withErrors(['domain' => $e->getMessage()]);
        }

        return back()->with('status', 'Add the TXT record below, then verify.');
    }

    public function verify(Request $request, Memberships $members, EnvironmentDomains $domains, OrganizationActivity $activity): RedirectResponse
    {
        $environmentId = $this->writable(trim($request->string('environment')->toString()), $members);

        $result = $domains->verify($environmentId);

        if (! $result->verified) {
            /*
             * NOT AN ERROR ON THE DOMAIN FIELD. The domain is fine and the record is
             * probably right — DNS simply has not propagated — so this says what to do
             * (wait, try again) rather than implying the value needs correcting.
             */
            return back()->withErrors([
                'verify' => 'The DNS TXT record isn\'t visible yet. DNS can take a few minutes to propagate — try again shortly.',
            ]);
        }

        $organizationId = $this->scope->organizationId();

        if ($organizationId !== null) {
            $activity->record(
                $organizationId,
                'organization.custom_domain_verified',
                $this->scope->actorId(),
                targetType: 'environment',
                targetId: $environmentId,
                context: ['domain' => $result->domain],
                request: $request,
            );
        }

        return back()->with('status', $result->domain.' is verified and now serves this environment.');
    }

    public function destroy(Request $request, Memberships $members, EnvironmentDomains $domains, OrganizationActivity $activity): RedirectResponse
    {
        $environmentId = $this->writable(trim($request->string('environment')->toString()), $members);

        $domains->clear($environmentId);

        $organizationId = $this->scope->organizationId();

        if ($organizationId !== null) {
            $activity->record(
                $organizationId,
                'organization.custom_domain_removed',
                $this->scope->actorId(),
                targetType: 'environment',
                targetId: $environmentId,
                request: $request,
            );
        }

        return back()->with('status', 'Custom domain removed.');
    }

    /**
     * The environment this write may act on, or a refusal.
     *
     * The capability AND the reachability, together and before anything else runs: an id
     * the caller cannot reach must never become an argument to a service that resolves it
     * unscoped.
     */
    private function writable(string $environmentId, Memberships $members): string
    {
        abort_unless($this->scope->capabilities()?->canManageEnvironments() === true, 403);
        abort_unless(in_array($environmentId, $this->reachable($members), true), 403);

        return $environmentId;
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
