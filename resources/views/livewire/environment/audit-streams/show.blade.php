<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Contracts\LogStreams;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Log streaming › detail. The full, deep-linkable lifecycle
 * for one SIEM stream: its configuration, disable/resume, the one-time signing secret
 * handed off from create, and delete.
 *
 * Every read/mutation re-resolves the stream within THIS environment (the model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default resolve-then-act). The signing secret is stored as
 * ciphertext and never decrypted for display; only a freshly minted secret (handed off
 * from the create page) is shown, exactly once, and never re-echoed afterwards.
 */
new #[Layout('components.layouts.environment', ['title' => 'Log stream'])] class extends Component
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

    public string $streamId = '';

    /** The freshly minted secret shown once (create hand-off); never stored plaintext.
     *  Protected so it is never dehydrated into the wire snapshot — shown once, then gone. */
    protected ?string $newSecret = null;

    public function mount(string $stream): void
    {
        $model = AuditStream::query()->whereKey($stream)->first();
        abort_if($model === null, 404);

        $this->streamId = $model->id;

        // One-time reveal handed off from the create page.
        $secret = session('newSecret');
        if (is_string($secret)) {
            $this->newSecret = $secret;
        }
    }

    private function resolveStream(): AuditStream
    {
        $model = AuditStream::query()->whereKey($this->streamId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    /**
     * Disable a stream so it stops receiving deliveries (pending rows are kept). The
     * env-scoped re-resolve means a foreign id 404s before the service is ever called.
     */
    public function disable(LogStreams $streams): void
    {
        $streams->disable($this->resolveStream()->id);
        $this->dispatch('toast', message: 'Stream disabled.');
    }

    /**
     * Re-enable a previously disabled stream. The registry exposes no dedicated enable
     * verb, so we flip the `enabled` attribute through its update() seam.
     */
    public function resume(LogStreams $streams): void
    {
        $streams->update($this->resolveStream()->id, ['enabled' => true]);
        $this->dispatch('toast', message: 'Stream resumed.');
    }

    public function dismissSecret(): void
    {
        $this->newSecret = null;
    }

    public function deleteStream(): mixed
    {
        $this->resolveStream()->delete();
        $this->dispatch('toast', message: 'Log stream deleted.');

        return $this->redirectRoute('environment.audit-streams', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'stream' => $this->resolveStream(),
            // Protected → never dehydrated; passed explicitly so the secret renders once.
            'newSecret' => $this->newSecret,
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

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.audit-streams') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Log streaming</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $stream->name }}</h1>
            @if ($stream->enabled)
                <span class="badge badge-success">Enabled</span>
            @else
                <span class="badge">Disabled</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $stream->id }}</p>
    </div>

    {{-- One-time signing secret (create hand-off) — never shown again. --}}
    @if ($newSecret)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">Copy this signing secret now — it won't be shown again.</p>
                    <p class="mt-1 text-sm" style="color:var(--warning-strong)">Your SIEM verifies delivery signatures with it.</p>
                    <p class="mt-3 mono text-sm break-all select-all">{{ $newSecret }}</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Dismiss</button>
            </div>
        </div>
    @endif

    {{-- Configuration --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Configuration</p>
        <dl class="mt-4 space-y-4">
            <div>
                <dt class="label">Destination</dt>
                <dd class="text-sm">{{ $destinationLabels[$stream->destination->value] ?? $stream->destination->value }}</dd>
            </div>
            <div>
                <dt class="label">Authentication</dt>
                <dd class="text-sm">{{ $schemeLabels[$stream->auth->value] ?? $stream->auth->value }}</dd>
            </div>
            <div>
                <dt class="label">Endpoint URL</dt>
                <dd class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $stream->endpoint_url }}</dd>
            </div>
        </dl>
    </div>

    {{-- Lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Lifecycle</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($stream->enabled)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="disable" wire:confirm="Disable this stream? Pending deliveries are kept but nothing new is sent.">Disable</button>
            @else
                <button type="button" class="btn btn-ghost btn-sm" wire:click="resume">Resume</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteStream" wire:confirm="Delete this log stream? This cannot be undone and its audit trail stops being exported.">Delete stream</button>
        </div>
    </div>
</div>
