<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Cbox\Id\Webhooks\Exceptions\UnsafeWebhookUrl;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Webhooks › New. A dedicated, deep-linkable create page.
 * The endpoint is registered platform-wide within THIS environment (organizationId
 * null — the model is BelongsToEnvironment, so it still never receives another plane's
 * events). Registration mints a signing secret that is returned exactly once; we hand
 * it to the detail page as a one-time flash and route straight there.
 */
new #[Layout('components.layouts.environment', ['title' => 'New webhook'])] class extends Component
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

    public string $url = '';

    /** @var list<string> */
    public array $eventTypes = [];

    public function create(WebhookRegistry $webhooks): mixed
    {
        $this->validate([
            'url' => ['required', 'url', 'max:500'],
            'eventTypes' => ['required', 'array', 'min:1'],
            'eventTypes.*' => ['string'],
        ]);

        try {
            $registered = $webhooks->register(null, $this->url, array_values($this->eventTypes));
        } catch (UnsafeWebhookUrl) {
            // The registry's SSRF guard refused the target — surface it on the field
            // rather than 500. The endpoint must resolve to a public address.
            $this->addError('url', 'That URL is not allowed — it must be a public HTTPS endpoint.');

            return null;
        }

        // The plaintext secret exists only in this response; hand it to the detail page
        // as a one-time flash — it is never retrievable again.
        session()->flash('newSecret', $registered->secret);
        $this->dispatch('toast', message: 'Webhook endpoint created.');

        return $this->redirectRoute('environment.webhooks.show', ['webhook' => $registered->endpoint->id], navigate: true);
    }
}; ?>

<div>
    <a href="{{ route('environment.webhooks') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Webhooks</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New webhook</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The signing secret is shown once, right after you create the endpoint.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="url">Endpoint URL</label>
            <input wire:model="url" id="url" type="url" class="input mono" placeholder="https://example.com/webhooks/cbox" autofocus>
            @error('url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <span class="label">Event types</span>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach (self::EVENT_TYPES as $event)
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox" wire:model="eventTypes" value="{{ $event }}" class="rounded">
                        <span class="mono text-xs">{{ $event }}</span>
                    </label>
                @endforeach
            </div>
            @error('eventTypes') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create webhook</button>
            <a href="{{ route('environment.webhooks') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
