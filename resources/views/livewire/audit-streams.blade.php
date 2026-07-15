<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'SIEM streams'])] class extends Component
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

    public ?string $revealedSecret = null;

    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

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

        // A generated HMAC key (or an echoed token) is revealed exactly once.
        $this->revealedSecret = $registered->secret;
        $this->reset('name', 'endpointUrl', 'secret', 'creating');
        session()->flash('status', 'SIEM stream created.');
    }

    public function dismissSecret(): void
    {
        $this->reset('revealedSecret');
    }

    public function disable(string $id, LogStreams $streams): void
    {
        $streams->disable($id);
        session()->flash('status', 'Stream disabled.');
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'streams' => AuditStream::query()->orderByDesc('created_at')->get(),
            'destinations' => [
                'splunk_hec' => 'Splunk HEC',
                'elastic_ecs' => 'Elastic (ECS)',
                'graylog_gelf' => 'Graylog (GELF)',
                'cef_http' => 'CEF over HTTP',
                'generic_json' => 'Generic JSON',
            ],
            'schemes' => ['none' => 'None', 'bearer' => 'Bearer token', 'splunk' => 'Splunk', 'hmac' => 'HMAC (generated key)'],
        ];
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Audit</p>
            <h1 class="cbx-page-title">SIEM streams</h1>
            <p class="cbx-page-desc">Mirror this environment's hash-chained audit trail out to your SIEM (Splunk, Elastic, Graylog, CEF). Delivery is at-least-once and environment-isolated.</p>
        </div>
        @if ($me->isAdmin())
            <button wire:click="$set('creating', true)" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New stream</button>
        @endif
    </div>

    @if ($revealedSecret)
        <div class="card p-5 mb-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold"><x-icon name="key" class="w-4 h-4" /> Stream signing secret</div>
                    <p class="mt-1 text-sm" style="color:var(--warning)">Copy this now — it is shown only once and cannot be retrieved again. Your SIEM verifies delivery signatures with it.</p>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $revealedSecret }}</p>
        </div>
    @endif

    @if ($creating)
        <form wire:submit="create" class="card p-4 mb-5 space-y-3">
            <div class="grid gap-3" style="grid-template-columns:1fr 1fr">
                <div>
                    <label class="label" for="name">Name</label>
                    <input wire:model="name" id="name" class="input" placeholder="Prod Splunk" autofocus>
                    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="destination">Destination</label>
                    <select wire:model="destination" id="destination" class="input">
                        @foreach ($destinations as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="label" for="endpointUrl">Endpoint URL</label>
                <input wire:model="endpointUrl" id="endpointUrl" class="input mono" placeholder="https://http-inputs.example.splunkcloud.com/services/collector">
                @error('endpointUrl') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-3" style="grid-template-columns:1fr 1fr">
                <div>
                    <label class="label" for="auth">Authentication</label>
                    <select wire:model="auth" id="auth" class="input">
                        @foreach ($schemes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="secret">Token / secret <span style="color:var(--faint)">(optional)</span></label>
                    <input wire:model="secret" id="secret" type="password" class="input" placeholder="HEC token — leave blank to generate an HMAC key">
                    @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create stream</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Name</th><th>Destination</th><th>Endpoint</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($streams as $stream)
                    <tr>
                        <td class="font-medium">{{ $stream->name }}</td>
                        <td><span class="badge mono">{{ $destinations[$stream->destination->value] ?? $stream->destination->value }}</span></td>
                        <td class="mono break-all" style="color:var(--muted);max-width:22rem">{{ $stream->endpoint_url }}</td>
                        <td>
                            @if ($stream->circuit_opened_at !== null)
                                <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>Circuit open</span>
                            @elseif ($stream->enabled)
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Enabled</span>
                            @else
                                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Disabled</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($stream->enabled)
                                <button wire:click="disable('{{ $stream->id }}')" wire:confirm="Disable this stream? Pending deliveries are kept but nothing new is sent." class="btn btn-ghost btn-sm">Disable</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="cbx-empty"><div class="cbx-empty-icon"><x-icon name="audit" class="w-5 h-5" /></div><h3>No streams yet</h3><p>Add a stream to export the audit trail to your SIEM.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
