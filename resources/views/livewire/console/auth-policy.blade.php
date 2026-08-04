<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentEnvironment;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Sign-in rules — one component, both planes.
 *
 * The two halves of this capability were never a pair, because only one of them existed.
 * The environment plane had a page that wrote the baseline; the per-ORGANIZATION policy —
 * {@see AuthPolicies::setForOrganization()} and its clear — had no writer anywhere in the
 * product, while both read paths enforced it on every sign-in
 * ({@see \App\Platform\PlatformAuth::passwordLoginAllowedFor()} walks a subject's
 * memberships, and {@see \App\Platform\MemberCredentialGate::admits()} asks it for account
 * members). A rule the platform enforces and nobody can set is worse than no rule: it is a
 * capability the docs describe, the API implies, and the console silently withholds.
 *
 * So the plane decides WHICH LEVEL is being edited, and nothing else:
 *
 *  - environment plane → the baseline every organization inherits, plus the table of what
 *    each one actually ends up with;
 *  - organization plane → that organization's override, which may only ever TIGHTEN the
 *    baseline ({@see AuthPolicy::tightenedWith()}).
 *
 * INHERITANCE IS THE POINT OF THE ORGANIZATION HALF, so it is shown rather than flattened.
 * An administrator reading "Require SSO: off" has to know whether that is their decision
 * or their operator's, because the answer changes what they can do about it — and because
 * the framework merges the two by taking the stricter value, a page that showed only the
 * effective result would present the operator's floor as the tenant's own setting and then
 * refuse to let them lower it, with nothing on screen explaining why.
 *
 * The tightening validation below is the other half of that honesty. `tightenedWith()`
 * silently discards an override looser than the baseline, so storing one would leave the
 * console showing a value that is not in force anywhere. Refused with a message naming the
 * floor instead.
 */
new #[Layout('components.layouts.console', ['title' => 'Sign-in rules'])] class extends Component
{
    use WithPagination;

    /**
     * Re-checked on EVERY request rather than at mount: boot() runs on each hydration, so
     * an administrator demoted mid-session cannot keep an open page that still writes.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public int $minLength = 12;

    public bool $requireBreachCheck = true;

    public string $maxAgeDays = '';

    public int $reuseHistory = 0;

    public string $mfa = 'optional';

    public string $sso = 'off';

    public string $lockoutThreshold = '';

    /**
     * Set when a save would end sessions, so the page asks before it does.
     *
     * Held rather than confirmed in the browser because the CONSEQUENCE is what has to be
     * read, and a native confirm() cannot say "this ends every password session in Acme,
     * quite possibly including yours". See {@see confirmSave()}.
     */
    public bool $confirmingTightening = false;

    public function mount(): void
    {
        $this->hydrateFrom($this->editedPolicy());
    }

    /**
     * The policy the form edits: the baseline on the environment plane, the organization's
     * EFFECTIVE policy on the other.
     *
     * Effective rather than the stored override, deliberately. An organization with no
     * override has no values of its own, and prefilling the form with an empty
     * {@see AuthPolicy}'s defaults would show a tenant a minimum length of 12 while their
     * environment demanded 16 — and then save the 12 as an override that does nothing.
     */
    private function editedPolicy(): AuthPolicy
    {
        $policies = app(AuthPolicies::class);

        return $this->onEnvironmentPlane()
            ? $policies->forEnvironment()
            : $policies->resolve($this->organizationId());
    }

    private function hydrateFrom(AuthPolicy $policy): void
    {
        $this->minLength = $policy->minLength;
        $this->requireBreachCheck = $policy->requireBreachCheck;
        $this->maxAgeDays = $policy->maxAgeDays === null ? '' : (string) $policy->maxAgeDays;
        $this->reuseHistory = $policy->reuseHistory;
        $this->mfa = $policy->mfa->value;
        $this->sso = $policy->sso->value;
        $this->lockoutThreshold = $policy->lockoutThreshold === null ? '' : (string) $policy->lockoutThreshold;
    }

    private function onEnvironmentPlane(): bool
    {
        return app(ConsoleScope::class)->plane() === ConsolePlane::Environment;
    }

    /**
     * The organization being edited. Never called on the environment plane, where this
     * page is about the environment itself.
     */
    private function organizationId(): string
    {
        return app(ConsoleScope::class)->requireOrganizationId();
    }

    /**
     * Save, or stop and ask first when the save would sign people out.
     *
     * The check is on the EFFECTIVE policy either side of the change rather than on the
     * form field, because an organization already covered by an environment-wide mandate
     * loses nothing by restating it — and asking "are you sure you want to end every
     * session" about a change that ends none is how confirmations become reflexes.
     */
    public function save(): void
    {
        $policy = $this->validated();

        if ($policy === null) {
            return;
        }

        if ($this->endsSessions($policy)) {
            $this->confirmingTightening = true;

            return;
        }

        $this->write($policy);
    }

    /** The second click, after the consequences have been on screen. */
    public function confirmSave(): void
    {
        // Re-validated and re-derived from the current state rather than from anything
        // stashed when the dialog opened: the properties are public, so a round trip could
        // have changed them between the question and the answer.
        $policy = $this->validated();

        if ($policy === null) {
            $this->confirmingTightening = false;

            return;
        }

        $this->write($policy);
    }

    public function cancelTightening(): void
    {
        $this->confirmingTightening = false;
    }

    /**
     * Drop the override and go back to inheriting. Organization plane only — the
     * environment baseline is what everything else inherits FROM, so there is nothing
     * above it to fall back to.
     */
    public function returnToInherited(): void
    {
        $scope = app(ConsoleScope::class);
        $scope->assertMayAdminister();

        if ($this->onEnvironmentPlane()) {
            // Not reachable from the rendered page; refused anyway, because the property
            // that makes that true is the markup, and markup is not an authorization.
            $this->addError('sso', 'The environment baseline is what organizations inherit; it cannot itself inherit.');

            return;
        }

        app(AuthPolicies::class)->clearForOrganization($this->organizationId());

        // Re-read rather than reset to the enum defaults: the form now shows the baseline,
        // which is what this organization is actually governed by from here on.
        $this->hydrateFrom($this->editedPolicy());
        $this->confirmingTightening = false;

        $this->dispatch('toast', message: 'Back to the environment defaults.');
    }

    /**
     * The submitted policy, or null when the form is not acceptable.
     *
     * @return AuthPolicy|null
     */
    private function validated(): ?AuthPolicy
    {
        $this->validate([
            'minLength' => ['required', 'integer', 'min:8', 'max:128'],
            'maxAgeDays' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reuseHistory' => ['required', 'integer', 'min:0', 'max:24'],
            'lockoutThreshold' => ['nullable', 'integer', 'min:3', 'max:100'],
            // Public props, so a crafted wire request can set anything. Without these,
            // the ::from() below throws ValueError and the console 500s instead of
            // showing a field error.
            'mfa' => ['required', Rule::enum(MfaRequirement::class)],
            'sso' => ['required', Rule::enum(SsoEnforcement::class)],
        ], attributes: [
            'minLength' => 'minimum length',
            'maxAgeDays' => 'maximum age',
            'reuseHistory' => 'reuse history',
            'lockoutThreshold' => 'lockout threshold',
        ]);

        $policy = new AuthPolicy(
            minLength: $this->minLength,
            requireBreachCheck: $this->requireBreachCheck,
            maxAgeDays: $this->maxAgeDays === '' ? null : (int) $this->maxAgeDays,
            reuseHistory: $this->reuseHistory,
            mfa: MfaRequirement::from($this->mfa),
            sso: SsoEnforcement::from($this->sso),
            lockoutThreshold: $this->lockoutThreshold === '' ? null : (int) $this->lockoutThreshold,
        );

        return $this->onEnvironmentPlane() || $this->refuseLooseningOf($policy) === false
            ? $policy
            : null;
    }

    /**
     * Field errors for anything the override tries to loosen, and whether it did.
     *
     * Every one of these is a value `tightenedWith()` would throw away. Saying so on the
     * field is the difference between a console that appears to have saved something and
     * one an administrator can trust.
     */
    private function refuseLooseningOf(AuthPolicy $policy): bool
    {
        $baseline = app(AuthPolicies::class)->forEnvironment();
        $loosened = false;

        if ($policy->minLength < $baseline->minLength) {
            $this->addError('minLength', 'Your environment requires at least '.$baseline->minLength.' characters.');
            $loosened = true;
        }

        if ($policy->reuseHistory < $baseline->reuseHistory) {
            $this->addError('reuseHistory', 'Your environment blocks reuse of the last '.$baseline->reuseHistory.'.');
            $loosened = true;
        }

        // Null means "no limit", so it is the LOOSEST value either field can hold: an
        // override may shorten the environment's deadline, never remove it.
        if ($baseline->maxAgeDays !== null && ($policy->maxAgeDays === null || $policy->maxAgeDays > $baseline->maxAgeDays)) {
            $this->addError('maxAgeDays', 'Your environment forces a change after '.$baseline->maxAgeDays.' days at the latest.');
            $loosened = true;
        }

        if ($baseline->lockoutThreshold !== null && ($policy->lockoutThreshold === null || $policy->lockoutThreshold > $baseline->lockoutThreshold)) {
            $this->addError('lockoutThreshold', 'Your environment locks out after '.$baseline->lockoutThreshold.' failed attempts at the most.');
            $loosened = true;
        }

        if ($baseline->requireBreachCheck && ! $policy->requireBreachCheck) {
            $this->addError('requireBreachCheck', 'Your environment requires the breach check.');
            $loosened = true;
        }

        if ($policy->mfa->atLeast($baseline->mfa) !== $policy->mfa) {
            $this->addError('mfa', 'Your environment requires at least "'.$baseline->mfa->value.'".');
            $loosened = true;
        }

        if ($policy->sso->atLeast($baseline->sso) !== $policy->sso) {
            $this->addError('sso', 'Your environment requires at least "'.$baseline->sso->value.'".');
            $loosened = true;
        }

        return $loosened;
    }

    /**
     * Whether saving this would revoke sessions.
     *
     * The same question {@see \App\Platform\RevokingAuthPolicies} asks itself before it
     * writes, asked here so the page can warn about what that decorator is going to do.
     * Deliberately NOT a second implementation of the revocation — the write below goes
     * through the {@see AuthPolicies} contract, so the decorator does the actual work.
     */
    private function endsSessions(AuthPolicy $policy): bool
    {
        $policies = app(AuthPolicies::class);

        if ($this->onEnvironmentPlane()) {
            return $policies->resolve()->sso->allowsPasswordLogin()
                && ! $policy->sso->allowsPasswordLogin();
        }

        $organizationId = $this->organizationId();

        return $policies->resolve($organizationId)->sso->allowsPasswordLogin()
            && ! $policies->forEnvironment()->tightenedWith($policy)->sso->allowsPasswordLogin();
    }

    /**
     * The write itself — through the CONTRACT on both planes, which is what makes the
     * session revocation happen at all: it lives in a decorator around this interface, so
     * a page that reached for the concrete store would tighten the mandate and leave every
     * password session it just invalidated wide open.
     */
    private function write(AuthPolicy $policy): void
    {
        $policies = app(AuthPolicies::class);

        if ($this->onEnvironmentPlane()) {
            $policies->setForEnvironment($policy);
        } else {
            $policies->setForOrganization($this->organizationId(), $policy);
        }

        $this->confirmingTightening = false;

        $this->dispatch('toast', message: 'Sign-in rules saved.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $policies = app(AuthPolicies::class);
        $onEnvironmentPlane = $this->onEnvironmentPlane();
        $baseline = $policies->forEnvironment();
        $override = $onEnvironmentPlane ? null : $policies->overrideFor($this->organizationId());

        return [
            'onEnvironmentPlane' => $onEnvironmentPlane,
            'baseline' => $baseline,
            'inheriting' => ! $onEnvironmentPlane && $override === null,
            // Which FIELDS this organization has actually taken over, so a badge can say
            // so beside each one. Compared against the baseline rather than reported as
            // "there is an override": an override that restates the baseline in six fields
            // and tightens the seventh should read as one decision, not seven.
            'overridden' => $override === null ? [] : array_keys(array_filter([
                'minLength' => $override->minLength !== $baseline->minLength,
                'requireBreachCheck' => $override->requireBreachCheck !== $baseline->requireBreachCheck,
                'maxAgeDays' => $override->maxAgeDays !== $baseline->maxAgeDays,
                'reuseHistory' => $override->reuseHistory !== $baseline->reuseHistory,
                'mfa' => $override->mfa !== $baseline->mfa,
                'sso' => $override->sso !== $baseline->sso,
                'lockoutThreshold' => $override->lockoutThreshold !== $baseline->lockoutThreshold,
            ])),
            'scopeName' => $this->scopeName(),
            'organizations' => $onEnvironmentPlane ? $this->organizationRows() : null,
        ];
    }

    /**
     * What this page is about, in the words the administrator would use: the environment
     * on one plane, the organization on the other.
     *
     * Named rather than left as "this organization" wherever it can be, because the copy
     * that carries the consequence — "this will sign people out of …" — is only useful if
     * it says WHOSE people.
     */
    private function scopeName(): string
    {
        if (! $this->onEnvironmentPlane()) {
            $name = app(ConsoleScope::class)->organizationName();

            return $name === null || $name === '' ? 'this organization' : $name;
        }

        $environment = app(CurrentEnvironment::class)->get();

        return $environment === null ? 'this environment' : $environment->name;
    }

    /**
     * What each organization in the environment ends up with — the environment plane's
     * answer to "did my baseline actually land".
     *
     * Paginated, and the overrides are read in ONE query for the page rather than one per
     * row: the previous page walked every organization in the environment unpaginated and
     * asked {@see AuthPolicies::overrideFor()} for each, which is the N+1 the batch reader
     * on that contract was added to remove.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, array{id: string, name: string, overridden: bool, effective: AuthPolicy}>
     */
    private function organizationRows(): mixed
    {
        $page = Organization::query()->orderBy('name')->paginate(25, ['id', 'name']);
        $policies = app(AuthPolicies::class);

        $ids = array_values(array_map(
            static fn (Organization $organization): string => $organization->id,
            $page->items(),
        ));

        $overrides = $policies->overridesFor($ids);
        $baseline = $policies->forEnvironment();

        return $page->through(fn (Organization $organization): array => [
            'id' => $organization->id,
            'name' => $organization->name,
            'overridden' => isset($overrides[$organization->id]),
            'effective' => isset($overrides[$organization->id])
                ? $baseline->tightenedWith($overrides[$organization->id])
                : $baseline,
        ]);
    }
}; ?>

<div class="space-y-6">
    <x-page-header
        title="Sign-in rules"
        :subtitle="$onEnvironmentPlane
            ? 'The baseline every organization in this environment inherits. An organization can ask for stricter rules — never looser.'
            : 'How people sign in to ' . $scopeName . '. These start as your environment\'s defaults; anything you change here can only make them stricter.'" />

    @unless ($onEnvironmentPlane)
        {{-- The inheritance state, said out loud before any control is read. An admin
             looking at "Require SSO: off" cannot otherwise tell whose decision that is. --}}
        <div class="rounded-xl border p-4 flex flex-wrap items-center justify-between gap-3"
             style="border-color:var(--border);background:var(--surface-2)">
            <p class="text-sm" style="color:var(--muted)">
                @if ($inheriting)
                    <strong style="color:var(--text)">Using your environment's defaults.</strong>
                    Nothing here is set by {{ $scopeName }} yet — change anything below and it becomes this organization's own rule.
                @else
                    <strong style="color:var(--text)">{{ $scopeName }} has its own rules.</strong>
                    {{ count($overridden) }} {{ \Illuminate\Support\Str::plural('setting', count($overridden)) }}
                    {{ count($overridden) === 1 ? 'differs' : 'differ' }} from your environment's defaults.
                @endif
            </p>

            @unless ($inheriting)
                <button type="button" wire:click="returnToInherited"
                        wire:confirm="Drop this organization's own sign-in rules and go back to the environment defaults?"
                        class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="returnToInherited">
                    <span wire:loading.remove wire:target="returnToInherited">Use environment defaults</span>
                    <span wire:loading wire:target="returnToInherited" class="inline-flex items-center gap-2"><span class="spinner"></span> Restoring…</span>
                </button>
            @endunless
        </div>
    @endunless

    <form wire:submit="save" class="rounded-xl border p-5 space-y-5" style="border-color:var(--border)">
        {{-- Every control on this page is live. It briefly was not: rotation, lockout and
             two-factor were saved and inherited while nothing read them, and this space
             said so rather than quietly presenting them as controls. If a field here ever
             ships ahead of its enforcement again, say so here again. --}}
        <div class="grid sm:grid-cols-2 gap-5">
            <div>
                <label for="min-length" class="text-sm font-medium">
                    Minimum password length
                    @if (in_array('minLength', $overridden, true)) <span class="badge ml-1">Overridden</span> @endif
                </label>
                <input id="min-length" wire:model="minLength" type="number" min="8" max="128" class="input mt-1.5"
                       @error('minLength') aria-invalid="true" aria-describedby="min-length-error" @enderror>
                @unless ($onEnvironmentPlane)
                    <p class="mt-1.5 text-xs" style="color:var(--faint)">Environment default: {{ $baseline->minLength }}. You can require more, not fewer.</p>
                @endunless
                @error('minLength') <p id="min-length-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="reuse-history" class="text-sm font-medium">
                    Block reuse of the last
                    @if (in_array('reuseHistory', $overridden, true)) <span class="badge ml-1">Overridden</span> @endif
                </label>
                <input id="reuse-history" wire:model="reuseHistory" type="number" min="0" max="24" class="input mt-1.5"
                       @error('reuseHistory') aria-invalid="true" aria-describedby="reuse-history-error" @enderror>
                <p class="mt-1.5 text-xs" style="color:var(--faint)">
                    Passwords. 0 turns reuse checking off. Only hashes are kept.
                    @unless ($onEnvironmentPlane) Environment default: {{ $baseline->reuseHistory }}. @endunless
                </p>
                @error('reuseHistory') <p id="reuse-history-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="max-age" class="text-sm font-medium">
                    Force a change after
                    @if (in_array('maxAgeDays', $overridden, true)) <span class="badge ml-1">Overridden</span> @endif
                </label>
                <input id="max-age" wire:model="maxAgeDays" type="number" min="1" max="3650" class="input mt-1.5" placeholder="Never"
                       @error('maxAgeDays') aria-invalid="true" aria-describedby="max-age-error" @enderror>
                <p class="mt-1.5 text-xs" style="color:var(--faint)">
                    Days. Leave empty to never force a rotation.
                    @unless ($onEnvironmentPlane)
                        Environment default: {{ $baseline->maxAgeDays === null ? 'never' : $baseline->maxAgeDays . ' days' }}.
                    @endunless
                </p>
                @error('maxAgeDays') <p id="max-age-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="lockout" class="text-sm font-medium">
                    Lock out after
                    @if (in_array('lockoutThreshold', $overridden, true)) <span class="badge ml-1">Overridden</span> @endif
                </label>
                <input id="lockout" wire:model="lockoutThreshold" type="number" min="3" max="100" class="input mt-1.5" placeholder="Off"
                       @error('lockoutThreshold') aria-invalid="true" aria-describedby="lockout-error" @enderror>
                <p class="mt-1.5 text-xs" style="color:var(--faint)">
                    Failed attempts. Leave empty to disable lockout.
                    @unless ($onEnvironmentPlane)
                        Environment default: {{ $baseline->lockoutThreshold === null ? 'off' : $baseline->lockoutThreshold }}.
                    @endunless
                </p>
                @error('lockoutThreshold') <p id="lockout-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="mfa" class="text-sm font-medium">
                    Two-factor authentication
                    @if (in_array('mfa', $overridden, true)) <span class="badge ml-1">Overridden</span> @endif
                </label>
                <select id="mfa" wire:model="mfa" class="input mt-1.5"
                        @error('mfa') aria-invalid="true" aria-describedby="mfa-error" @enderror>
                    <option value="off">Not offered</option>
                    <option value="optional">Optional — users may enrol</option>
                    <option value="required">Required — users must enrol to sign in</option>
                </select>
                @error('mfa') <p id="mfa-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="sso" class="text-sm font-medium">
                    Single sign-on
                    @if (in_array('sso', $overridden, true)) <span class="badge ml-1">Overridden</span> @endif
                </label>
                {{-- .live, alone on this form: the submit control below changes shape when
                     "Require SSO" is chosen, and a deferred binding would only tell the
                     administrator that after they had already pressed Save. --}}
                <select id="sso" wire:model.live="sso" class="input mt-1.5"
                        @error('sso') aria-invalid="true" aria-describedby="sso-error" @enderror>
                    <option value="off">Passwords and SSO both available</option>
                    <option value="preferred">Prefer SSO, passwords still work</option>
                    <option value="required">Require SSO — password sign-in is refused</option>
                </select>
                @error('sso') <p id="sso-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <label class="flex items-start gap-2 text-sm cursor-pointer" style="color:var(--muted)">
            <input type="checkbox" wire:model="requireBreachCheck" class="rounded mt-0.5"
                   @error('requireBreachCheck') aria-invalid="true" aria-describedby="breach-error" @enderror>
            <span>Refuse passwords found in known data breaches. Checked against Have I Been Pwned without ever sending the password — if the service is unreachable the sign-up is allowed rather than blocked.</span>
        </label>
        @error('requireBreachCheck') <p id="breach-error" class="field-error" role="alert">{{ $message }}</p> @enderror

        @if ($confirmingTightening)
            {{-- The consequence, before the change. Turning the mandate on ends every
                 password session it governs — RevokingAuthPolicies does that on the way
                 through the contract — and the person most likely to be holding one is
                 the administrator reading this. --}}
            <div role="alert" class="rounded-xl border p-4" style="border-color:var(--danger);background:var(--danger-soft)">
                <p class="font-semibold" style="color:var(--danger-strong)">This will sign people out of {{ $scopeName }}</p>
                <ul class="mt-2 space-y-1 text-sm list-disc pl-5" style="color:var(--danger-strong)">
                    <li>Password sign-in stops working for everyone in {{ $scopeName }}, immediately.</li>
                    <li>Every session that was opened with a password ends — including yours, if you signed in that way.</li>
                    <li>People get back in through your identity provider. Anyone without one connected cannot sign in at all.</li>
                    <li>You can set this back to "Prefer SSO" or "Both available" at any time; sessions that ended stay ended.</li>
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="confirmSave" class="btn btn-danger"
                            wire:loading.attr="disabled" wire:target="confirmSave">
                        <span wire:loading.remove wire:target="confirmSave">Require SSO and sign everyone out</span>
                        <span wire:loading wire:target="confirmSave" class="inline-flex items-center gap-2"><span class="spinner"></span> Applying…</span>
                    </button>
                    <button type="button" wire:click="cancelTightening" class="btn btn-ghost">Keep passwords working</button>
                </div>
            </div>
        @else
            <div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save rules</span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2"><span class="spinner"></span> Saving…</span>
                </button>
            </div>
        @endif
    </form>

    @if ($onEnvironmentPlane && $organizations !== null)
        {{-- What each organization actually ends up with. --}}
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Per organization</h2>
            <p class="mt-1 text-xs" style="color:var(--faint)">The rules in force after this environment's baseline is applied. An organization's own override can only make these stricter.</p>
            <p role="status" aria-live="polite" class="sr-only">{{ $organizations->total() }} {{ \Illuminate\Support\Str::plural('organization', $organizations->total()) }} found.</p>

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

            @if ($organizations->hasPages())
                <div class="mt-4">{{ $organizations->links() }}</div>
            @endif
        </div>
    @endif
</div>
