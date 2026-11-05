<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Account\JoinServiceWaitlist;
use App\Http\Controllers\Console\RunsActions;
use App\Platform\Actions\ActionRefused;
use App\Platform\CurrentUser;
use App\Platform\ServiceAccess\BillingWaitlists;
use App\Platform\ServiceAccess\ServiceAccessSettings;
use App\Platform\ServiceAccess\ServiceAccessUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

final readonly class ServiceAccessController extends PageController
{
    use RunsActions;

    public function index(Request $request, CurrentUser $me, BillingWaitlists $billing, ServiceAccessSettings $settings): Response
    {
        abort_unless($settings->enabled(), 404);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'service' => ['sometimes', 'string', 'max:190']]);
        $services = [];
        $unavailable = false;
        $page = max(1, $request->integer('page', 1));
        $lastPage = $page;
        $filter = $request->filled('service') ? $request->string('service')->toString() : null;
        try {
            $catalogue = $billing->page($page, $filter);
            $lastPage = $catalogue['lastPage'];
            $email = $me->email();
            $registrations = $me->emailVerified() && $email !== null
                ? $billing->registrations(array_map(static fn ($service): int => $service->id, $catalogue['services']), $me->id(), $email)
                : [];
            foreach ($catalogue['services'] as $service) {
                $services[] = [
                    'id' => $service->id, 'key' => $service->key, 'name' => $service->name,
                    'description' => $service->description, 'mode' => $service->mode, 'questions' => $service->questions,
                    'publicUrl' => $service->publicUrl, 'status' => $registrations[$service->id] ?? null,
                    'joinHref' => route('account.services.join', ['program' => $service->id]),
                    'reference' => (string) Str::uuid(),
                ];
            }
        } catch (ServiceAccessUnavailable) {
            $unavailable = true;
        }

        return $this->page('account/services', __('service_access.title'), [
            'services' => $services, 'unavailable' => $unavailable,
            'email' => $me->email(), 'emailVerified' => $me->emailVerified(),
            'accountHref' => route('account'),
            'allHref' => $filter === null ? null : route('account.services'),
            'previousHref' => $page > 1 ? route('account.services', ['page' => $page - 1, 'service' => $filter]) : null,
            'nextHref' => $page < $lastPage ? route('account.services', ['page' => $page + 1, 'service' => $filter]) : null,
        ]);
    }

    public function join(Request $request, int $program, ServiceAccessSettings $settings): RedirectResponse
    {
        abort_unless($settings->enabled(), 404);
        try {
            $this->runAction(JoinServiceWaitlist::class, [
                'program' => $program, 'country' => $request->input('country'),
                'answers' => $request->input('answers', []), 'reference' => $request->input('reference'),
            ]);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            return back()->withErrors(['waitlist' => $refused->getMessage()]);
        }

        return to_route('account.services')->with('status', __('service_access.joined'));
    }
}
