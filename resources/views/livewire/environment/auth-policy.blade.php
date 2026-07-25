<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Sign-in rules.
 *
 * The environment sets the baseline every organization inherits. An organization may
 * override it, but only to TIGHTEN — the framework combines the two by taking the
 * stricter value of each field, so nothing an admin can type here lets a tenant drop
 * below the operator's floor. The per-organization column shows what each one actually
 * ends up with, which is the question an admin is really asking.
 */
new #[Layout('components.layouts.environment', ['title' => 'Sign-in rules'])] class extends Component
{
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

    public int $minLength = 12;

    public bool $requireBreachCheck = true;

    public string $maxAgeDays = '';

    public int $reuseHistory = 0;

    public string $mfa = 'optional';

    public string $sso = 'off';

    public string $lockoutThreshold = '';

    public function mount(): void
    {
        $policy = app(AuthPolicies::class)->forEnvironment();

        $this->minLength = $policy->minLength;
        $this->requireBreachCheck = $policy->requireBreachCheck;
        $this->maxAgeDays = $policy->maxAgeDays === null ? '' : (string) $policy->maxAgeDays;
        $this->reuseHistory = $policy->reuseHistory;
        $this->mfa = $policy->mfa->value;
        $this->sso = $policy->sso->value;
        $this->lockoutThreshold = $policy->lockoutThreshold === null ? '' : (string) $policy->lockoutThreshold;
    }

    public function save(AuthPolicies $policies): void
    {
        $data = $this->validate([
            'minLength' => ['required', 'integer', 'min:8', 'max:128'],
            'maxAgeDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reuseHistory' => ['required', 'integer', 'min:0', 'max:24'],
            'lockoutThreshold' => ['nullable', 'integer', 'min:3', 'max:100'],
        ], attributes: [
            'minLength' => 'minimum length',
            'maxAgeDays' => 'maximum age',
            'reuseHistory' => 'reuse history',
            'lockoutThreshold' => 'lockout threshold',
        ]);

        $policies->setForEnvironment(new AuthPolicy(
            minLength: (int) $data['minLength'],
            requireBreachCheck: $this->requireBreachCheck,
            maxAgeDays: $this->maxAgeDays === '' ? null : (int) $this->maxAgeDays,
            reuseHistory: (int) $data['reuseHistory'],
            mfa: MfaRequirement::from($this->mfa),
            sso: SsoEnforcement::from($this->sso),
            lockoutThreshold: $this->lockoutThreshold === '' ? null : (int) $this->lockoutThreshold,
        ));

        $this->dispatch('toast', message: 'Sign-in rules saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $policies = app(AuthPolicies::class);

        // What each organization ENDS UP with, not what it asked for — the effective
        // policy after the environment baseline is applied.
        $rows = [];
        foreach (Organization::query()->orderBy('name')->get() as $org) {
            $rows[] = [
                'id' => $org->id,
                'name' => $org->name,
                'overridden' => $policies->overrideFor($org->id) !== null,
                'effective' => $policies->resolve($org->id),
            ];
        }

        return ['organizations' => $rows];
    }
}; ?>

<div class="space-y-6">
    <x-page-header title="Sign-in rules" subtitle="The baseline every organization in this environment inherits. An organization can ask for stricter rules — never looser." />

    <form wire:submit="save" class="rounded-xl border p-5 space-y-5" style="border-color:var(--border)">
        {{-- Honesty over completeness: three of these are stored and inherited but nothing
             reads them yet. A control that says "Required" and isn't is worse than one
             that admits it, so they are marked rather than quietly presented as live. --}}
        <p class="text-xs rounded-lg px-3 py-2" style="color:var(--warning-strong);background:var(--warning-soft)">
            Password length, breach checking and reuse are enforced. Rotation, lockout and two-factor
            are saved and inherited but <strong>not yet enforced at sign-in</strong> — don't rely on them as controls.
        </p>

        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="min-length" class="text-sm font-medium">Minimum password length</label>
                <input id="min-length" wire:model="minLength" type="number" min="8" max="128" class="input mt-1.5"
                       @error('minLength') aria-invalid="true" aria-describedby="min-length-error" @enderror>
                @error('minLength') <p id="min-length-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="reuse-history" class="text-sm font-medium">Block reuse of the last</label>
                <input id="reuse-history" wire:model="reuseHistory" type="number" min="0" max="24" class="input mt-1.5"
                       @error('reuseHistory') aria-invalid="true" aria-describedby="reuse-history-error" @enderror>
                <p class="mt-1.5 text-xs" style="color:var(--faint)">Passwords. 0 turns reuse checking off. Only hashes are kept.</p>
                @error('reuseHistory') <p id="reuse-history-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="max-age" class="text-sm font-medium">Force a change after <span class="badge badge-warn ml-1">Not enforced yet</span></label>
                <input id="max-age" wire:model="maxAgeDays" type="number" min="1" max="3650" class="input mt-1.5" placeholder="Never"
                       @error('maxAgeDays') aria-invalid="true" aria-describedby="max-age-error" @enderror>
                <p class="mt-1.5 text-xs" style="color:var(--faint)">Days. Leave empty to never force a rotation.</p>
                @error('maxAgeDays') <p id="max-age-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="lockout" class="text-sm font-medium">Lock out after <span class="badge badge-warn ml-1">Not enforced yet</span></label>
                <input id="lockout" wire:model="lockoutThreshold" type="number" min="3" max="100" class="input mt-1.5" placeholder="Off"
                       @error('lockoutThreshold') aria-invalid="true" aria-describedby="lockout-error" @enderror>
                <p class="mt-1.5 text-xs" style="color:var(--faint)">Failed attempts. Leave empty to disable lockout.</p>
                @error('lockoutThreshold') <p id="lockout-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mfa" class="text-sm font-medium">Two-factor authentication <span class="badge badge-warn ml-1">Not enforced yet</span></label>
                <select id="mfa" wire:model="mfa" class="input mt-1.5">
                    <option value="off">Not offered</option>
                    <option value="optional">Optional — users may enrol</option>
                    <option value="required">Required — users must enrol to sign in</option>
                </select>
            </div>

            <div>
                <label for="sso" class="text-sm font-medium">Single sign-on</label>
                <select id="sso" wire:model="sso" class="input mt-1.5">
                    <option value="off">Passwords and SSO both available</option>
                    <option value="preferred">Prefer SSO, passwords still work</option>
                    <option value="required">Require SSO — password sign-in is refused</option>
                </select>
            </div>
        </div>

        <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--muted)">
            <input type="checkbox" wire:model="requireBreachCheck" class="rounded mt-0.5">
            <span>Refuse passwords found in known data breaches. Checked against Have I Been Pwned without ever sending the password — if the service is unreachable the sign-up is allowed rather than blocked.</span>
        </label>

        <div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">Save rules</button>
        </div>
    </form>

    {{-- What each organization actually ends up with. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Per organization</p>
        <p class="mt-1 text-xs" style="color:var(--faint)">The rules in force after this environment's baseline is applied. An organization's own override can only make these stricter.</p>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left" style="color:var(--muted)">
                        <th class="pb-2 font-medium">Organization</th>
                        <th class="pb-2 font-medium">Min length</th>
                        <th class="pb-2 font-medium">2FA</th>
                        <th class="pb-2 font-medium">SSO</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($organizations as $row)
                        <tr class="border-t" style="border-color:var(--border)" wire:key="org-{{ $row['id'] }}">
                            <td class="py-2">
                                {{ $row['name'] }}
                                @if ($row['overridden'])
                                    <span class="badge ml-1">Override</span>
                                @else
                                    <span class="text-xs ml-1" style="color:var(--faint)">inherited</span>
                                @endif
                            </td>
                            <td class="py-2 tabular-nums">{{ $row['effective']->minLength }}</td>
                            <td class="py-2">{{ ucfirst($row['effective']->mfa->value) }}</td>
                            <td class="py-2">{{ ucfirst($row['effective']->sso->value) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-sm" style="color:var(--faint)">No organizations in this environment yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
