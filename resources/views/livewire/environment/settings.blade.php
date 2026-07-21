<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Settings. The environment's identity + the integration
 * details apps need to wire up sign-in: the OIDC issuer and discovery URL, served on
 * THIS environment's own host. Read-oriented — the environment's name/plan live on
 * the account side; this is where an integrator copies what they need.
 */
new #[Layout('components.layouts.environment', ['title' => 'Settings'])] class extends Component
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

    /**
     * @return array<string, mixed>
     */
    public function with(EnvironmentContext $environments): array
    {
        $key = $environments->current()?->environmentKey();
        $environment = $key !== null ? Environment::query()->find($key) : null;

        // The environment is reached on its own host (this request's host).
        $issuer = 'https://'.request()->getHost();

        return [
            'environment' => $environment,
            'issuer' => $issuer,
            'discovery' => $issuer.'/.well-known/openid-configuration',
        ];
    }
}; ?>

<div>
    <x-page-header title="Settings" subtitle="This environment's identity and the details your apps need to integrate." />

    <div class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <p class="text-xs uppercase tracking-wide" style="color:var(--faint)">Environment</p>
            <div class="mt-1 flex items-center gap-2">
                <span class="font-medium">{{ $environment?->name ?? '—' }}</span>
                @if ($environment?->isSandbox())
                    <span class="badge badge-warn">Sandbox</span>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-6">
        <p class="text-sm font-medium">Integration</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">Point your OIDC client at these. Discovery exposes every endpoint automatically.</p>
        <div class="mt-3 space-y-3">
            @foreach ([['Issuer', $issuer], ['OIDC discovery', $discovery]] as [$label, $value])
                <div class="rounded-xl border p-3" style="border-color:var(--border)">
                    <p class="text-xs" style="color:var(--faint)">{{ $label }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <code class="flex-1 min-w-0 truncate mono text-sm">{{ $value }}</code>
                        <button type="button" class="btn btn-ghost btn-sm shrink-0" data-copy="{{ $value }}" onclick="navigator.clipboard.writeText(this.getAttribute('data-copy'))">Copy</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
