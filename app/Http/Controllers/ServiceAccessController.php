<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Account\JoinServiceWaitlist;
use App\Actions\Account\LeaveFeatureWaitlist;
use App\Http\Controllers\Console\RunsActions;
use App\Platform\Actions\ActionRefused;
use App\Platform\CurrentUser;
use App\Platform\ServiceAccess\BillingWaitlists;
use App\Platform\ServiceAccess\ServiceAccessSettings;
use App\Platform\ServiceAccess\ServiceAccessUnavailable;
use App\Platform\ServiceAccess\WaitlistTeams;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

final readonly class ServiceAccessController extends PageController
{
    use RunsActions;

    public function index(Request $request, CurrentUser $me, BillingWaitlists $billing, ServiceAccessSettings $settings, WaitlistTeams $teams): Response
    {
        abort_unless($settings->enabled(), 404);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'service' => ['sometimes', 'string', 'max:190'], 'kind' => ['sometimes', 'in:service,feature'], 'organization' => ['nullable', 'string', 'max:64']]);
        $services = [];
        $unavailable = false;
        $page = max(1, $request->integer('page', 1));
        $lastPage = $page;
        $filter = $request->filled('service') ? $request->string('service')->toString() : null;
        $kind = $request->string('kind', 'service')->toString();
        $choices = $kind === 'feature' ? $teams->choices($me->id()) : [];
        $organization = $kind === 'feature' ? ($request->filled('organization') ? $request->string('organization')->toString() : ($choices[0]['id'] ?? null)) : null;
        abort_if($organization !== null && ! in_array($organization, array_column($choices, 'id'), true), 404);
        $query = $kind === 'feature' ? ['kind' => $kind, 'organization' => $organization] : [];
        try {
            $catalogue = $billing->page($page, $filter, $kind);
            $lastPage = $catalogue['lastPage'];
            $email = $me->email();
            $registrations = $me->emailVerified() && $email !== null
                ? $billing->registrations(array_map(static fn ($service): int => $service->id, $catalogue['services']), $me->id(), $email, $organization)
                : [];
            foreach ($catalogue['services'] as $service) {
                $services[] = [
                    'kind' => $service->kind, 'scope' => $service->scope, 'serviceName' => $service->serviceName,
                    'id' => $service->id, 'key' => $service->key, 'name' => $service->name,
                    'description' => $service->description, 'mode' => $service->mode, 'questions' => $service->questions,
                    'publicUrl' => $service->publicUrl, 'status' => $registrations[$service->id] ?? null,
                    'joinHref' => route('account.services.join', ['program' => $service->id] + $query + ['service' => $filter]),
                    'leaveHref' => route('account.services.leave', ['program' => $service->id]),
                    'reference' => (string) Str::uuid(),
                ];
            }
        } catch (ServiceAccessUnavailable) {
            $unavailable = true;
        }

        return $this->page('account/services', __($kind === 'feature' ? 'service_access.features' : 'service_access.title'), [
            'kind' => $kind, 'organization' => $organization, 'teams' => $choices,
            'servicesHref' => route('account.services', ['service' => $filter]),
            'featuresHref' => route('account.services', ['kind' => 'feature', 'service' => $filter]),
            'services' => $services, 'unavailable' => $unavailable,
            'email' => $me->email(), 'emailVerified' => $me->emailVerified(),
            'accountHref' => route('account'),
            'allHref' => $filter === null ? null : route('account.services', $query),
            'previousHref' => $page > 1 ? route('account.services', ['page' => $page - 1, 'service' => $filter] + $query) : null,
            'nextHref' => $page < $lastPage ? route('account.services', ['page' => $page + 1, 'service' => $filter] + $query) : null,
        ]);
    }

    public function join(Request $request, int $program, ServiceAccessSettings $settings): RedirectResponse
    {
        abort_unless($settings->enabled(), 404);
        try {
            $this->runAction(JoinServiceWaitlist::class, [
                'organization' => $request->input('organization'), 'interest' => $request->input('interest', 'test'),
                'program' => $program, 'country' => $request->input('country'),
                'answers' => $request->input('answers', []), 'reference' => $request->input('reference'),
            ]);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            return back()->withErrors(['waitlist' => $refused->getMessage()]);
        }

        return to_route('account.services', $request->string('kind')->toString() === 'feature' ? ['kind' => 'feature', 'organization' => $request->input('organization'), 'service' => $request->input('service')] : [])->with('status', __($request->string('kind')->toString() === 'feature' ? 'service_access.feature_joined' : 'service_access.joined'));
    }

    public function leave(Request $request, int $program, ServiceAccessSettings $settings): RedirectResponse
    {
        abort_unless($settings->enabled(), 404);
        try {
            $this->runAction(LeaveFeatureWaitlist::class, ['program' => $program, 'organization' => $request->input('organization'), 'reference' => $request->input('reference')]);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            return back()->withErrors(['waitlist' => $refused->getMessage()]);
        }

        return to_route('account.services', ['kind' => 'feature', 'organization' => $request->input('organization')])->with('status', __('service_access.withdrawn'));
    }
}
