<?php

declare(strict_types=1);

use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Log streaming — the SIEM export registry. Mirrors this
 * environment's hash-chained audit trail out to a downstream SIEM (Splunk, Elastic,
 * Graylog, CEF) over an authenticated HTTP sink.
 *
 * Streams are environment-owned ({@see AuditStream} via BelongsToEnvironment), so the
 * registry only ever resolves a stream within this environment — an id minted in
 * another plane never matches, closing cross-tenant id tampering. Access is gated by
 * the env-admin session (route middleware), so the account member has full CRUD here;
 * there is no per-org entitlement lock at the control-plane level.
 */
new #[Layout('components.layouts.environment', ['title' => 'Log streaming'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string')]
    public string $destination = 'generic_json';

    #[Validate('required|url|max:2048')]
    public string $endpointUrl = '';

    #[Validate('required|string')]
    public string $auth = 'none';

    #[Validate('nullable|string|max:4096')]
    public string $secret = '';

    public bool $creating = false;

    /** The plaintext signing secret, held only for the single render after creation. */
    public ?string $revealedSecret = null;

    public function create(LogStreams $streams): void
    {
        $this->validate();

        $registered = $streams->create(
            $this->name,
            Destination::from($this->destination),
            $this->endpointUrl,
            $this->secret !== '' ? $this->secret : null,
            AuthScheme::from($this->auth),
        );

        // A generated HMAC key (or an echoed token) is revealed exactly once; only
        // ciphertext is persisted, so this is the caller's one chance to capture it.
        $this->revealedSecret = $registered->secret;
        $this->reset('name', 'endpointUrl', 'secret', 'creating');
        session()->flash('status', 'Log stream created.');
    }

    public function dismissSecret(): void
    {
        $this->reset('revealedSecret');
    }

    /**
     * Disable a stream THIS environment owns, or refuse. find() is environment-scoped,
     * so an id from another plane resolves to null and is a 404 — never a cross-tenant
     * mutation (deny-by-default resolve-then-act).
     */
    public function disable(string $id, LogStreams $streams): void
    {
        abort_if($streams->find($id) === null, 404);

        $streams->disable($id);
        session()->flash('status', 'Stream disabled.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'streams' => AuditStream::query()->orderByDesc('created_at')->get(),
            'destinations' => Destination::cases(),
            'schemes' => AuthScheme::cases(),
            'destinationLabels' => [
                'splunk_hec' => 'Splunk HEC',
                'elastic_ecs' => 'Elastic (ECS)',
                'graylog_gelf' => 'Graylog (GELF)',
                'cef_http' => 'CEF over HTTP',
                'generic_json' => 'Generic JSON',
            ],
            'schemeLabels' => [
                'none' => 'None',
                'bearer' => 'Bearer token',
                'splunk' => 'Splunk',
                'hmac' => 'HMAC (generated key)',
            ],
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Log streaming</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Mirror this environment's hash-chained audit trail out to your SIEM (Splunk, Elastic, Graylog, CEF). Delivery is at-least-once and environment-isolated.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New stream</button>
    </div>

    @if ($revealedSecret)
        <div class="mt-6 rounded-xl border p-5" style="border-color:var(--destructive)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold"><x-icon name="key" class="w-4 h-4" /> Stream signing secret</div>
                    <p class="mt-1 text-sm" style="color:var(--destructive)">Copy this now — it is shown only once and cannot be retrieved again. Your SIEM verifies delivery signatures with it.</p>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $revealedSecret }}</p>
        </div>
    @endif

    @if ($creating)
        <form wire:submit="create" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Prod Splunk" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="destination">Destination</label>
                    <select wire:model="destination" id="destination" class="select">
                        @foreach ($destinations as $case)
                            <option value="{{ $case->value }}">{{ $destinationLabels[$case->value] ?? $case->value }}</option>
                        @endforeach
                    </select>
                    @error('destination') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="label" for="endpointUrl">Endpoint URL</label>
                <input wire:model="endpointUrl" id="endpointUrl" type="url" class="input mono" placeholder="https://http-inputs.example.splunkcloud.com/services/collector">
                @error('endpointUrl') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="auth">Authentication</label>
                    <select wire:model="auth" id="auth" class="select">
                        @foreach ($schemes as $case)
                            <option value="{{ $case->value }}">{{ $schemeLabels[$case->value] ?? $case->value }}</option>
                        @endforeach
                    </select>
                    @error('auth') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="secret">Token / secret <span style="color:var(--faint)">(optional)</span></label>
                    <input wire:model="secret" id="secret" type="password" class="input" placeholder="HEC token — leave blank to generate an HMAC key">
                    @error('secret') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create stream</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($streams as $stream)
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate">{{ $stream->name }}</p>
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $destinationLabels[$stream->destination->value] ?? $stream->destination->value }}</span>
                            @if ($stream->circuit_opened_at !== null)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--destructive)">Circuit open</span>
                            @elseif ($stream->enabled)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Enabled</span>
                            @else
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Disabled</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs mono truncate" style="color:var(--faint)">{{ $stream->id }}</p>
                    </div>
                    @if ($stream->enabled)
                        <button wire:click="disable('{{ $stream->id }}')" wire:confirm="Disable this stream? Pending deliveries are kept but nothing new is sent." class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)">Disable</button>
                    @endif
                </div>

                <div class="mt-4">
                    <p class="label">Endpoint URL</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $stream->endpoint_url }}</p>
                </div>
            </div>
        @empty
            <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No streams yet. Add one to export this environment's audit trail to your SIEM.</p>
        @endforelse
    </div>
</div>
