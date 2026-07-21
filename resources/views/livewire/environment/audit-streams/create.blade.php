<?php

declare(strict_types=1);

use Cbox\LaravelSiem\Contracts\LogStreams;
use Cbox\LaravelSiem\Enums\AuthScheme;
use Cbox\LaravelSiem\Enums\Destination;
use Cbox\LaravelSiem\ValueObjects\RegisteredStream;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Log streaming › New. A dedicated, deep-linkable create
 * page. Registration mints a signing secret (or echoes a supplied token) that is
 * revealed EXACTLY ONCE on the returned {@see RegisteredStream};
 * we hand it to the detail page as a one-time flash and route straight there.
 *
 * The stream is environment-owned (the model is BelongsToEnvironment), so it can only
 * ever receive THIS environment's audit trail. The endpoint URL is SSRF-checked by the
 * registry before it is stored.
 */
new #[Layout('components.layouts.environment', ['title' => 'New log stream'])] class extends Component
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

    public function create(LogStreams $streams): mixed
    {
        $this->validate();

        $destination = Destination::tryFrom($this->destination);
        $authScheme = AuthScheme::tryFrom($this->auth);
        if ($destination === null || $authScheme === null) {
            $this->addError('destination', 'Choose a valid destination and auth scheme.');

            return null;
        }

        $registered = $streams->create(
            $this->name,
            $destination,
            $this->endpointUrl,
            $this->secret !== '' ? $this->secret : null,
            $authScheme,
        );

        // A generated HMAC key (or an echoed token) is revealed exactly once; only
        // ciphertext is persisted, so hand it to the detail page as a one-time flash —
        // it can never be retrieved again.
        if (is_string($registered->secret)) {
            session()->flash('newSecret', $registered->secret);
        }
        $this->dispatch('toast', message: 'Log stream created.');

        return $this->redirectRoute('environment.audit-streams.show', ['stream' => $registered->stream->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
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
    <a href="{{ route('environment.audit-streams') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Log streaming</a>
    <x-page-header class="mt-2" title="New log stream" subtitle="The signing secret is shown once, right after you create the stream." />

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Prod Splunk" autofocus>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="destination">Destination</label>
            <select wire:model="destination" id="destination" class="select">
                @foreach ($destinations as $case)
                    <option value="{{ $case->value }}">{{ $destinationLabels[$case->value] ?? $case->value }}</option>
                @endforeach
            </select>
            @error('destination') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="label" for="endpointUrl">Endpoint URL</label>
            <input wire:model="endpointUrl" id="endpointUrl" type="url" class="input mono" placeholder="https://http-inputs.example.splunkcloud.com/services/collector">
            @error('endpointUrl') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="auth">Authentication</label>
                <select wire:model="auth" id="auth" class="select">
                    @foreach ($schemes as $case)
                        <option value="{{ $case->value }}">{{ $schemeLabels[$case->value] ?? $case->value }}</option>
                    @endforeach
                </select>
                @error('auth') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="secret">Token / secret <span style="color:var(--faint)">(optional)</span></label>
                <input wire:model="secret" id="secret" type="password" class="input" placeholder="HEC token — leave blank to generate an HMAC key">
                @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create stream</button>
            <a href="{{ route('environment.audit-streams') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
