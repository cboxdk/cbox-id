<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Agent approvals'])] class extends Component
{
    public function approve(string $id): void
    {
        $me = app(CurrentUser::class);

        // Approval is consent: bind it to the acting subject, so a request belonging to
        // someone else is refused by the service rather than silently approved.
        app(BackchannelAuthentication::class)->approve($id, $me->id(), $me->organizationId());
        session()->flash('status', 'Request approved.');
    }

    public function deny(string $id): void
    {
        app(BackchannelAuthentication::class)->deny($id, app(CurrentUser::class)->id());
        session()->flash('status', 'Request denied.');
    }

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
            ->where('user_id', app(CurrentUser::class)->id())
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

<div class="max-w-lg">
    <div class="cbx-page-header mb-6">
        <div>
            <p class="cbx-page-eyebrow">Agents</p>
            <h1 class="cbx-page-title">Agent approvals</h1>
            <p class="cbx-page-desc">An agent is asking to act on your behalf. Approve only requests you recognize.</p>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="card p-3.5 mb-4 text-sm" style="color:var(--muted)">
            {{ session('status') }}
        </div>
    @endif

    @forelse ($requests as $request)
        <div class="card p-5 mb-4">
            <div class="flex items-center gap-3">
                <span class="grid place-items-center rounded-full" style="width:2.25rem;height:2.25rem;background:var(--accent-soft);color:var(--accent)">
                    <x-icon name="shield" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-medium truncate">{{ $request['appName'] }} is requesting access</p>
                    <p class="text-xs" style="color:var(--faint)">wants to act on your behalf</p>
                </div>
            </div>

            @if ($request['bindingMessage'])
                <div class="mt-5 rounded-lg px-3.5 py-3" style="background:var(--accent-soft)">
                    <p class="cbx-page-eyebrow">Confirm this matches your device</p>
                    <p class="mt-1 font-medium">{{ $request['bindingMessage'] }}</p>
                </div>
            @endif

            @if (count($request['scopeRows']) > 0)
                <p class="cbx-page-eyebrow mt-6">This will allow {{ $request['appName'] }} to</p>
                <ul class="mt-2.5 space-y-2">
                    @foreach ($request['scopeRows'] as $row)
                        <li class="flex items-center gap-2.5 text-sm">
                            <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success)" />
                            <span>{{ $row['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-7 flex gap-2.5">
                <button type="button" wire:click="deny('{{ $request['id'] }}')" class="btn btn-ghost btn-lg flex-1" wire:loading.attr="disabled">Deny</button>
                <button type="button" wire:click="approve('{{ $request['id'] }}')" class="btn btn-primary btn-lg flex-1" wire:loading.attr="disabled">Approve</button>
            </div>
        </div>
    @empty
        <div class="cbx-empty" style="padding:3rem 1rem">
            <div class="cbx-empty-icon"><x-icon name="shield" class="w-5 h-5" /></div>
            <h3>No pending requests</h3>
            <p>No pending requests — you're all caught up.</p>
        </div>
    @endforelse
</div>
