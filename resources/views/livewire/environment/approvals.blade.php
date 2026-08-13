<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Agent approvals — the human-in-the-loop surface for
 * CIBA (Client-Initiated Backchannel Authentication) requests. An agent asks to act
 * on a subject's behalf; the env-admin approves or denies the pending request.
 *
 * Requests are environment-owned (BelongsToEnvironment on BackchannelAuthRequest),
 * so every query and lookup here is transparently scoped to this environment — an id
 * minted in another plane never resolves and is a 404, closing cross-tenant tampering
 * (deny-by-default). Access is gated by the env-admin session (route middleware), so
 * the account member has full authority over every pending request in this
 * environment; there is no per-org entitlement lock at the control-plane level.
 */
new #[Layout('components.layouts.environment', ['title' => 'Agent approvals'])] class extends Component
{
    use WithPagination;

    /** A screenful. See the read in with() for why this page is bounded at all. */
    private const PER_PAGE = 25;

    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);
    }

    /*
     * There is deliberately NO approve() here.
     *
     * A CIBA approval is the USER's consent for an agent to act as them, and the token
     * that follows is minted for that user — so an operator approving on their behalf
     * would be granting consent they were never asked for. That is the same bypass the
     * service layer now refuses (approve() requires the acting subject to BE the
     * request's subject), and this console previously passed the env-admin's own member
     * id, which could never match: the button silently did nothing.
     *
     * Denying is the safe half of the pair — it withholds access rather than granting it
     * — so an operator keeps the ability to shut a pending request down.
     */
    public function deny(string $id): void
    {
        $request = $this->pendingRequest($id);

        // Act as the request's own subject: denial cannot grant anything, so this is a
        // fail-closed operator action rather than consent on someone else's behalf.
        app(BackchannelAuthentication::class)->deny($request->id, $request->user_id);

        // BACK TO PAGE ONE. Deny the last row on page two and the paginator still asks
        // for page two, which is now empty — and this page's empty state says "No pending
        // requests", so an operator working a backlog concludes they are done.
        $this->resetPage();

        $this->dispatch('toast', message: 'Request denied.', severity: 'error');
    }

    /**
     * Resolve a pending, unexpired request THIS environment owns, or refuse. The
     * query is environment-scoped, so an id from another plane resolves to null and
     * is a 404 — never a cross-tenant mutation (deny-by-default).
     */
    private function pendingRequest(string $id): BackchannelAuthRequest
    {
        $request = BackchannelAuthRequest::query()
            ->where('id', $id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        abort_if($request === null, 404);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $labels = [
            'openid' => 'Verify your identity',
            'profile' => 'Your name',
            'email' => 'Your email address',
            'offline_access' => 'Stay signed in',
        ];

        // A PAGE OF THEM. This is every pending request in the environment, not one
        // person's — an agent platform generates these continuously, and the unbounded
        // read hydrated the lot into one response. Twenty-five is a screenful; an
        // operator working through a backlog pages, and an environment with a runaway
        // client no longer takes the console down with it.
        $requests = BackchannelAuthRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE);

        // Two lookups for the page, not two per row. `byClientId()` inside the map was a
        // query each, so a full page cost 25 round trips to render 25 application names.
        $names = Client::query()
            ->whereIn('client_id', $requests->getCollection()->pluck('client_id')->unique())
            ->pluck('name', 'client_id');

        // WHOSE request it is. The page asks an operator to recognise a request and gave
        // them the application name alone — which is the same on every row when one
        // agent platform is behind them all. The subject is the fact that distinguishes
        // "an agent is asking to act as Dana" from "an agent is asking".
        $subjects = User::query()
            ->whereIn('id', $requests->getCollection()->pluck('user_id')->unique())
            ->pluck('email', 'id');

        // The paginator keeps its own rows — the models — and the two lookups travel
        // beside it. Mapping them into arrays would mean re-typing the paginator, and the
        // view needs the models anyway to build its scope rows.
        return [
            'requests' => $requests,
            'appNames' => $names,
            'subjects' => $subjects,
            'scopeLabels' => $labels,
        ];
    }
}; ?>

<div>
    <x-page-header title="Agent approvals" subtitle="Requests from agents asking to act on a user's behalf. The user approves these themselves — deny anything you do not recognise." />

    <div class="mt-6 space-y-4">
        @forelse ($requests as $request)
            <div class="rounded-xl border p-5" style="border-color:var(--border)" wire:key="req-{{ $request->id }}">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center rounded-full shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent-strong)">
                        <x-icon name="shield" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold truncate">{{ $appNames[$request->client_id] ?? $request->client_id }} is requesting access</p>
                        <p class="text-xs truncate" style="color:var(--faint)">wants to act on behalf of {{ $subjects[$request->user_id] ?? 'a user who no longer exists' }}</p>
                    </div>
                </div>

                @if ($request->binding_message)
                    <div class="mt-4 rounded-lg px-3.5 py-3" style="background:var(--accent-soft)">
                        <p class="label">Confirm this matches the device</p>
                        <p class="mt-1 font-medium">{{ $request->binding_message }}</p>
                    </div>
                @endif

                @if ($request->scopes !== [])
                    <div class="mt-4">
                        <p class="label">This will allow {{ $appNames[$request->client_id] ?? $request->client_id }} to</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($request->scopes as $scope)
                                <li class="flex items-center gap-2.5 text-sm">
                                    <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success-strong)" />
                                    <span>{{ $scopeLabels[$scope] ?? $scope }}</span>
                                    <span class="badge">{{ $scope }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-5 flex gap-2.5">
                    <button type="button" wire:click="deny('{{ $request->id }}')" wire:confirm="Deny this request?" class="btn btn-danger" style="color:var(--destructive)" wire:loading.attr="disabled">Deny</button>
                </div>
            </div>
        @empty
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                <h3>No pending requests</h3>
                <p>Agent approval requests will appear here as they arrive, for you to deny if you do not recognise them.</p>
            </div>
        @endforelse

        @if ($requests->hasPages())
            <div class="mt-4">{{ $requests->links() }}</div>
        @endif
    </div>
</div>
