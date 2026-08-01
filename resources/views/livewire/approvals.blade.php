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
        $this->dispatch('toast', message: 'Request approved.');
    }

    public function deny(string $id): void
    {
        app(BackchannelAuthentication::class)->deny($id, app(CurrentUser::class)->id());
        $this->dispatch('toast', message: 'Request denied.', severity: 'error');
    }

    /** @return array<string, mixed> */
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
                    'appName' => $clients->byClientId($request->client_id)->name ?? $request->client_id,
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
    <x-page-header title="Agent approvals" :help="\App\Platform\Help\HelpTopic::AgentApprovals"
                   subtitle="When an app or agent needs your go-ahead to act as you, it asks here. Approve only requests you started yourself." />

    @forelse ($requests as $request)
        <div wire:key="request-{{ $request['id'] }}" class="card p-5 mb-4">
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
                            <x-icon name="check" class="w-4 h-4 shrink-0" style="color:var(--success-strong)" />
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
        <x-empty-state icon="shield" title="Nothing waiting for you" style="padding:3rem 1rem"
                       :help="\App\Platform\Help\HelpTopic::AgentApprovals"
                       body="Requests appear here on their own when an app or agent needs your approval to act as you. Nothing to do until one does — and if a request shows up you did not start, deny it." />
    @endforelse
</div>
