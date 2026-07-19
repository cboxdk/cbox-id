<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

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
    public function approve(string $id): void
    {
        $request = $this->pendingRequest($id);

        app(BackchannelAuthentication::class)->approve(
            $request->id,
            app(EnvironmentAdminAuth::class)->current()?->id ?? '',
        );

        session()->flash('status', 'Request approved.');
    }

    public function deny(string $id): void
    {
        $request = $this->pendingRequest($id);

        app(BackchannelAuthentication::class)->deny($request->id);

        session()->flash('status', 'Request denied.');
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
        $clients = app(ClientRegistry::class);

        $labels = [
            'openid' => 'Verify your identity',
            'profile' => 'Your name',
            'email' => 'Your email address',
            'offline_access' => 'Stay signed in',
        ];

        $requests = BackchannelAuthRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BackchannelAuthRequest $request) use ($clients, $labels): array {
                return [
                    'id' => $request->id,
                    'appName' => $clients->byClientId($request->client_id)?->name ?? $request->client_id,
                    'bindingMessage' => $request->binding_message,
                    'scopeRows' => array_map(
                        fn (string $scope): array => ['scope' => $scope, 'label' => $labels[$scope] ?? $scope],
                        $request->scopes,
                    ),
                ];
            });

        return [
            'requests' => $requests,
        ];
    }
}; ?>

<div>
    <x-page-header title="Agent approvals" subtitle="Pending requests from agents asking to act on a user's behalf. Approve only requests you recognize." />

    <div class="mt-6 space-y-4">
        @forelse ($requests as $request)
            <div class="rounded-xl border p-5" style="border-color:var(--border)" wire:key="req-{{ $request['id'] }}">
                <div class="flex items-center gap-3">
                    <span class="grid place-items-center rounded-full shrink-0" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                        <x-icon name="shield" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold truncate">{{ $request['appName'] }} is requesting access</p>
                        <p class="text-xs" style="color:var(--faint)">wants to act on the user's behalf</p>
                    </div>
                </div>

                @if ($request['bindingMessage'])
                    <div class="mt-4 rounded-lg px-3.5 py-3" style="background:var(--accent-soft)">
                        <p class="label">Confirm this matches the device</p>
                        <p class="mt-1 font-medium">{{ $request['bindingMessage'] }}</p>
                    </div>
                @endif

                @if (count($request['scopeRows']) > 0)
                    <div class="mt-4">
                        <p class="label">This will allow {{ $request['appName'] }} to</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($request['scopeRows'] as $row)
                                <li class="flex items-center gap-2.5 text-sm">
                                    <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success)" />
                                    <span>{{ $row['label'] }}</span>
                                    <span class="badge">{{ $row['scope'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-5 flex gap-2.5">
                    <button type="button" wire:click="approve('{{ $request['id'] }}')" class="btn btn-primary" wire:loading.attr="disabled">Approve</button>
                    <button type="button" wire:click="deny('{{ $request['id'] }}')" wire:confirm="Deny this request?" class="btn btn-ghost" style="color:var(--destructive)" wire:loading.attr="disabled">Deny</button>
                </div>
            </div>
        @empty
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
                <h3>No pending requests</h3>
                <p>Agent approval requests will appear here for review as they arrive.</p>
            </div>
        @endforelse
    </div>
</div>
