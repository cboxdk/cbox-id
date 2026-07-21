<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookDelivery;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Cbox\Id\Webhooks\Support\SafeWebhookUrl;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Webhooks › detail. The full, deep-linkable lifecycle for
 * one endpoint: edit URL + subscribed events, pause/resume, rotate the signing secret,
 * inspect recent deliveries, and delete.
 *
 * Every read/mutation re-resolves the endpoint within THIS environment (the model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default). The signing secret is stored sealed and never decrypted
 * for display; only a freshly minted secret (on create hand-off or rotation) is shown,
 * exactly once, and never re-echoed afterwards.
 */
new #[Layout('components.layouts.environment', ['title' => 'Webhook'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

    /** @var list<string> The event catalogue an endpoint may subscribe to. */
    public const EVENT_TYPES = [
        'user.created',
        'user.updated',
        'user.login',
        'user.deactivated',
        'user.reactivated',
        'user.password_reset',
        'user.email_verified',
        'user.mfa_enrolled',
        'user.passkey_registered',
        'identity.linked',
        'organization.created',
        'organization.member_added',
        'organization.member_removed',
        'organization.member_role_changed',
        'organization.suspended',
        'organization.reactivated',
        'organization.invitation_created',
        'organization.invitation_accepted',
        'role.assigned',
        'role.unassigned',
        'directory.user.provisioned',
        'directory.user.deactivated',
        'directory.user.deprovisioned',
        'directory.group.membership_changed',
    ];

    public string $endpointId = '';

    public string $editUrl = '';

    /** @var list<string> */
    public array $editEvents = [];

    /** The freshly minted secret shown once (create hand-off or rotation); never stored plaintext. */
    public ?string $newSecret = null;

    public function mount(string $webhook): void
    {
        $endpoint = WebhookEndpoint::query()->whereKey($webhook)->first();
        abort_if($endpoint === null, 404);

        $this->endpointId = $endpoint->id;
        $this->editUrl = $endpoint->url;
        $this->editEvents = array_values($endpoint->event_types);

        // One-time reveal handed off from the create page.
        $secret = session('newSecret');
        if (is_string($secret)) {
            $this->newSecret = $secret;
        }
    }

    private function endpoint(): WebhookEndpoint
    {
        $endpoint = WebhookEndpoint::query()->whereKey($this->endpointId)->first();
        abort_if($endpoint === null, 404);

        return $endpoint;
    }

    public function saveSubscription(): void
    {
        $endpoint = $this->endpoint();

        $data = $this->validate([
            'editUrl' => ['required', 'url', 'max:500'],
            'editEvents' => ['required', 'array', 'min:1'],
            'editEvents.*' => ['string'],
        ]);

        // Re-run the SSRF guard on any URL change — a public endpoint can never be
        // silently repointed at an internal address.
        if (! SafeWebhookUrl::isSafe($data['editUrl'])) {
            $this->addError('editUrl', 'That URL is not allowed — it must be a public HTTPS endpoint.');

            return;
        }

        $endpoint->url = $data['editUrl'];
        $endpoint->event_types = array_values($this->editEvents);
        $endpoint->save();

        $this->dispatch('toast', message: 'Subscription updated.');
    }

    public function pause(WebhookRegistry $webhooks): void
    {
        $webhooks->pause($this->endpoint()->id);
        $this->dispatch('toast', message: 'Endpoint paused — it will stop receiving events.');
    }

    public function resume(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->status = EndpointStatus::Active;
        $endpoint->save();

        $this->dispatch('toast', message: 'Endpoint resumed.');
    }

    public function rotateSecret(SecretBox $secretBox): void
    {
        $endpoint = $this->endpoint();

        $secret = bin2hex(random_bytes(32));
        $endpoint->secret_encrypted = $secretBox->seal($secret, $endpoint->secretContext());
        $endpoint->save();

        // Shown once here; the sealed form is all that persists.
        $this->newSecret = $secret;
        $this->dispatch('toast', message: 'Signing secret rotated — update your endpoint now.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newSecret');
    }

    public function deleteEndpoint(): mixed
    {
        $this->endpoint()->delete();
        $this->dispatch('toast', message: 'Webhook endpoint deleted.');

        return $this->redirectRoute('environment.webhooks', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $endpoint = $this->endpoint();

        return [
            'endpoint' => $endpoint,
            'deliveries' => WebhookDelivery::query()
                ->where('endpoint_id', $endpoint->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.webhooks') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Webhooks</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight mono truncate" style="font-size:1.25rem">{{ $endpoint->url }}</h1>
            @if ($endpoint->status === EndpointStatus::Active)
                <span class="badge badge-success">Active</span>
            @else
                <span class="badge badge-warn">Paused</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $endpoint->id }}</p>
    </div>

    {{-- One-time signing secret (create hand-off or rotation) — never shown again. --}}
    @if ($newSecret)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mt-3 mono text-sm break-all select-all">{{ $newSecret }}</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Dismiss</button>
            </div>
        </div>
    @endif

    {{-- Subscription --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Subscription</p>
        <form wire:submit="saveSubscription" class="mt-4 space-y-4">
            <div>
                <label class="label" for="editUrl">Endpoint URL</label>
                <input wire:model="editUrl" id="editUrl" type="url" class="input mono">
                @error('editUrl') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <span class="label">Event types</span>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (self::EVENT_TYPES as $event)
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="checkbox" wire:model="editEvents" value="{{ $event }}" class="rounded">
                            <span class="mono text-xs">{{ $event }}</span>
                        </label>
                    @endforeach
                </div>
                @error('editEvents') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveSubscription">Save changes</button>
        </form>
    </div>

    {{-- Signing secret --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Signing secret</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">The secret signs every delivery's HMAC. It is stored sealed and can't be retrieved — rotating issues a new one, shown once.</p>
        <button type="button" class="btn btn-ghost btn-sm mt-4" wire:click="rotateSecret" wire:confirm="Rotate the signing secret? The current secret stops verifying immediately — update your endpoint right after."><x-icon name="refresh" class="w-4 h-4" /> Rotate secret</button>
    </div>

    {{-- Recent deliveries --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Recent deliveries</p>
        <div class="mt-4 space-y-2">
            @forelse ($deliveries as $delivery)
                <div class="flex items-center gap-3 rounded-lg border px-3 py-2" style="border-color:var(--border)" wire:key="delivery-{{ $delivery->id }}">
                    <div class="min-w-0 flex-1">
                        <span class="block text-sm font-medium truncate mono">{{ $delivery->event_type }}</span>
                        <p class="text-xs" style="color:var(--faint)">
                            Attempt {{ $delivery->attempt }}@if ($delivery->response_code) · HTTP {{ $delivery->response_code }}@endif
                            @if ($delivery->delivered_at) · delivered {{ $delivery->delivered_at->diffForHumans() }}@elseif ($delivery->next_retry_at) · retry {{ $delivery->next_retry_at->diffForHumans() }}@endif
                        </p>
                    </div>
                    @if ($delivery->status === \Cbox\Id\Webhooks\Enums\DeliveryStatus::Delivered)
                        <span class="badge badge-success shrink-0">Delivered</span>
                    @else
                        <span class="badge badge-warn shrink-0">{{ ucfirst($delivery->status->value) }}</span>
                    @endif
                </div>
            @empty
                <p class="text-sm" style="color:var(--muted)">No deliveries yet.</p>
            @endforelse
        </div>
    </div>

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($endpoint->status === EndpointStatus::Active)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="pause" wire:confirm="Pause this endpoint? It will stop receiving events until resumed.">Pause</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resume">Resume</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteEndpoint" wire:confirm="Delete this webhook endpoint? This cannot be undone.">Delete endpoint</button>
        </div>
    </div>
</div>
