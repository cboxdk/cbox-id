<?php

declare(strict_types=1);

use App\Platform\OrganizationActivity;
use App\Platform\AccountAuth;
use App\Platform\OrganizationCapabilities;
use App\Platform\Console\ConsoleScope;
use App\Platform\MemberEmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Platform\Contracts\Projects;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Livewire;
use Livewire\Volt\Component;

/**
 * Identity platform › Projects — the account's launchpad.
 *
 * Everything the account owns is on ONE page: each project with its environments
 * listed under it. Opening an environment's console is a single click from here, and
 * so is adding one. The previous design showed project cards only, so reaching the
 * thing people actually come for — an environment — meant drilling into a project
 * page first.
 *
 * The project row carries only what belongs to the project (settings, the plan's seat
 * count); environment work happens on the environment rows.
 */
new #[Layout('components.layouts.app', ['title' => 'Projects'])] class extends Component
{
    /** The project whose inline "new environment" form is open, if any. */
    public ?string $creatingIn = null;

    public string $newEnvironment = '';

    public string $newEnvironmentType = 'production';

    /** What the last resend attempt has to say, rendered inside the banner itself. */
    public string $resendNotice = '';

    /**
     * Re-asked here, because boot() is the only hook that runs on EVERY Livewire action.
     *
     * A mount() guard is a page-LOAD guard: the snapshot the first render hands the
     * browser can be replayed straight at /livewire/update, and mount() never runs again.
     *
     * The two answers differ, and the condition is which of them is being asked for rather
     * than a detail of transport. Arriving at this URL — a bookmark, a stale link, an
     * account suspended since the tab was opened — deserves somewhere to go, and mount()
     * below sends them there. A replayed ACTION deserves nothing but a refusal: there is
     * no page to land on, only a component being driven by somebody the console has
     * already decided this page is not for.
     */
    public function boot(ConsoleScope $scope): void
    {
        abort_if(
            Livewire::isLivewireRequest() && $scope->accountRole() === null,
            403,
        );
    }

    /**
     * Re-send the signup confirmation — the only way back for an owner whose email is
     * lost, filtered or expired, now that the environment waits on it.
     *
     * NO ADDRESS IS ACCEPTED HERE. The action takes the member the session resolved, and
     * mails whatever address is on that row; there is deliberately no argument an
     * attacker (or a crafted Livewire payload) could point somewhere else.
     */
    public function resendVerification(AccountAuth $auth, MemberEmailVerification $verification): void
    {
        $member = $auth->current();
        abort_if($member === null, 403);

        // Outbound mail is the scarce, abusable resource: anyone who got as far as an
        // account can otherwise pump mail at their own inbox and burn the sending
        // reputation for everyone. Keyed on the MEMBER, not the address or the IP —
        // the caller is authenticated, so there is no cheaper key to rotate.
        $key = 'account-verify-resend|'.$member->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->resendNotice = 'That is a lot of emails. Try again in '.RateLimiter::availableIn($key).' seconds, or check your spam folder in the meantime.';

            return;
        }

        RateLimiter::hit($key, 600);

        $this->resendNotice = $verification->resend($member)->message($member->email);
    }

    public function startCreate(string $projectId): void
    {
        $this->creatingIn = $projectId;
        $this->newEnvironment = '';
        $this->newEnvironmentType = 'production';
        $this->resetErrorBag();
    }

    public function cancelCreate(): void
    {
        $this->reset('creatingIn', 'newEnvironment', 'newEnvironmentType');
    }

    /**
     * Add an environment to a project without leaving this page.
     *
     * The project is re-resolved against the member's account and the manage capability
     * re-checked here: the inline form is a convenience, never a second authorization
     * path — a posted project id is honoured only if it genuinely belongs to this
     * account and this member may manage it.
     */
    public function addEnvironment(AccountAuth $auth, TenantProvisioner $provisioner, OrganizationActivity $activity): void
    {
        $member = $auth->current();
        abort_if($member === null || ! app(ConsoleScope::class)->capabilities()?->canManageEnvironments() === true, 403);

        $project = Project::query()->whereKey($this->creatingIn)->first();
        abort_if($project === null || $project->account_id !== $member->account_id, 404);

        $this->validate([
            'newEnvironment' => 'required|string|max:120',
            'newEnvironmentType' => ['required', Rule::enum(EnvironmentType::class)],
        ], attributes: ['newEnvironment' => 'environment name']);

        try {
            $environment = $provisioner->addEnvironment(
                $project,
                trim($this->newEnvironment),
                type: EnvironmentType::from($this->newEnvironmentType),
            );
        } catch (EnvironmentLimitReached) {
            $this->addError('newEnvironment', 'This project is at its environment limit. Upgrade its plan to add more.');

            return;
        }

        $activity->record($member->account_id, 'account.environment_created', $member->id,
            targetType: 'environment', targetId: $environment->id,
            context: ['name' => trim($this->newEnvironment), 'type' => $this->newEnvironmentType], request: request());

        $this->cancelCreate();
        $this->dispatch('toast', message: 'Environment created.');
    }

    /**
     * This page belongs to whoever's organization owns the identity providers on it.
     *
     * Everybody else is sent somewhere real rather than refused, and that is not
     * politeness — it is the whole of what `workspace.no-access` used to be for. As a
     * PLANE's landing page this gave two answers, and both were dead ends: a 403 for a
     * signed-in subject with no membership, rendered on an error layout with no rail and
     * therefore no sign-out control; and, for the operator who runs the deployment and
     * buys nothing on it, a paragraph explaining what a project is under a CTA gated off
     * by a role they do not hold.
     *
     * There is no dead end left to catch. This is one page inside the one console,
     * `/dashboard` is the console root for every signed-in subject, and it has the rail —
     * and the sign-out — the refusal screen lacked. The operator still goes to the
     * platform pages, because that is where their work is, not because this one refuses
     * them.
     *
     * The rail already hides this page from anyone {@see ConsoleScope::accountRole()}
     * answers null for. The guard is here as well because a rail is not an authorization
     * check, and because a bookmark outlives a nav entry.
     */
    public function mount(ConsoleScope $scope): void
    {
        if ($scope->accountRole() !== null) {
            return;
        }

        $this->redirectRoute(
            $scope->isPlatformOperator() ? 'platform.environments' : 'dashboard',
            navigate: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(AccountAuth $auth, Projects $projects, Memberships $members, ConsoleScope $scope): array
    {
        $member = $auth->current();
        $account = $member?->account;
        $allAccess = $member !== null && $member?->all_environments === true;

        // The environments this member may reach — an all-access member sees every one
        // the account owns; a scoped member only their grants.
        $accessibleIds = $member === null ? [] : $members->accessibleEnvironmentIds($member);

        $rows = [];

        if ($account !== null) {
            $projectList = $projects->forOrganization($account->id);
            $projectIds = [];
            foreach ($projectList as $project) {
                $projectIds[] = $project->id;
            }

            // One query for every environment across every project, grouped in memory.
            // This page renders the whole tree, so a per-project query would be an N+1
            // that grows with the account.
            $environments = Environment::query()
                ->whereIn('project_id', $projectIds)
                ->orderBy('name')
                ->get()
                ->groupBy('project_id');

            foreach ($projectList as $project) {
                /** @var Collection<int, Environment> $owned */
                $owned = $environments->get($project->id) ?? new Collection;
                $visible = $allAccess
                    ? $owned
                    : $owned->filter(static fn (Environment $e): bool => in_array($e->id, $accessibleIds, true));

                // A project with nothing reachable is not this member's to see.
                if ($visible->isEmpty() && ! $allAccess) {
                    continue;
                }

                $rows[] = [
                    'id' => $project->id,
                    'name' => $project->name,
                    'status' => $project->status,
                    'limit' => $project->environment_limit,
                    'used' => $owned->count(),
                    'atLimit' => $owned->count() >= $project->environment_limit,
                    'environments' => $visible->map(static fn (Environment $e): array => [
                        'id' => $e->id,
                        'name' => $e->name,
                        'type' => $e->type,
                        'slug' => $e->slug,
                        'domain' => $e->domain,
                    ])->values()->all(),
                ];
            }
        }

        return [
            'projects' => $rows,
            'canManage' => $scope->capabilities()?->canManageEnvironments() === true,
            'awaitingVerification' => $this->awaitingVerification($member),
            'verificationEmail' => $member->email ?? '',
            // Named in the banner so "it never arrived" has somewhere to go: this is the
            // address to search the inbox for and to allow past a spam filter.
            'verificationSender' => is_string($from = config('mail.from.address')) ? $from : '',
        ];
    }

    /**
     * True while the account's first environment is still held back pending the owner's
     * email confirmation — otherwise the page shows a project with no environments and
     * no explanation of why.
     */
    private function awaitingVerification(?Membership $member): bool
    {
        $subjectId = $member?->subject_id;

        if ($member === null || ! is_string($subjectId) || $subjectId === '') {
            return false;
        }

        if (Environment::query()->where('account_id', $member->account_id)->exists()) {
            return false;
        }

        // Account members are subjects in the PLATFORM ROOT, so the lookup has to run in
        // that scope — the tenancy kernel is deny-by-default and would otherwise see
        // nothing.
        $verified = app(PlatformRoot::class)->run(
            fn (): bool => app(Subjects::class)->find($subjectId)?->emailVerified === true,
        );

        return $verified === false;
    }
}; ?>

<div>
    <x-page-header title="Projects" subtitle="Each project is a separate IdP product — its own environments, sign-in, and plan.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('projects.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New project</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($awaitingVerification)
        <div class="mt-6 rounded-xl border p-4" style="border-color:var(--border)" role="status">
            <p class="font-medium">Confirm your email to finish</p>
            <p class="mt-1 text-sm" style="color:var(--muted)">
                We sent a link to <span class="mono">{{ $verificationEmail }}</span>. Your first environment — your live IdP,
                with its own sign-in, users and signing keys — is created the moment you open it, and you land straight back here.
                The link stays valid for 24 hours.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                <button type="button" class="btn btn-secondary btn-sm shrink-0" wire:click="resendVerification"
                        wire:loading.attr="disabled" wire:target="resendVerification">
                    <span wire:loading.remove wire:target="resendVerification">Send the link again</span>
                    <span wire:loading wire:target="resendVerification" class="inline-flex items-center gap-2"><span class="spinner"></span> Sending…</span>
                </button>
                @if ($verificationSender !== '')
                    <span class="text-xs" style="color:var(--faint)">Nothing in your inbox? It comes from <span class="mono">{{ $verificationSender }}</span> — check spam too.</span>
                @endif
            </div>
        </div>
    @endif

    {{-- Outside the banner on purpose: a resend that lands just as the environment
         is released (or after) makes the banner disappear, and the answer to a click
         must not disappear with it. --}}
    @if ($resendNotice !== '')
        <p class="mt-3 text-sm" style="color:var(--muted)" role="alert" aria-live="polite" data-resend-notice>{{ $resendNotice }}</p>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($projects as $project)
            <section class="rounded-xl border" style="border-color:var(--border)" wire:key="project-{{ $project['id'] }}">

                {{-- Project header: the project's own identity and controls only. --}}
                <header class="flex items-center gap-3 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="font-medium truncate">{{ $project['name'] }}</h2>
                            @if ($project['status']->value !== 'active')
                                <span class="badge badge-warn">{{ $project['status']->value }}</span>
                            @endif
                        </div>
                        <p class="mt-0.5 text-xs" style="color:var(--faint)">
                            <span class="tabular-nums">{{ $project['used'] }} of {{ $project['limit'] }}</span>
                            {{ \Illuminate\Support\Str::plural('environment', $project['limit']) }}
                        </p>
                    </div>

                    @if ($canManage)
                        <a href="{{ route('projects.show', $project['id']) }}"
                           class="btn btn-ghost btn-sm shrink-0"
                           aria-label="Project settings for {{ $project['name'] }}"
                           title="Project settings">
                            <x-icon name="settings" class="w-4 h-4" />
                        </a>
                    @endif
                </header>

                {{-- Environments — what people actually come here to open. --}}
                <ul class="border-t" style="border-color:var(--border)">
                    @forelse ($project['environments'] as $env)
                        <li class="flex items-center gap-3 px-5 py-3" wire:key="env-{{ $env['id'] }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm truncate">{{ $env['name'] }}</span>
                                    <span class="badge">{{ $env['type']->value }}</span>
                                </div>
                                <p class="mt-0.5 text-xs mono truncate" style="color:var(--faint)">{{ $env['domain'] ?? $env['slug'] }}</p>
                            </div>

                            <a href="{{ route('environment.open', $env['id']) }}"
                               class="btn btn-secondary btn-sm shrink-0">Open</a>
                        </li>
                    @empty
                        <li class="px-5 py-3 text-sm" style="color:var(--faint)">No environments you can reach in this project.</li>
                    @endforelse
                </ul>

                {{-- Add an environment without leaving the page. --}}
                @if ($canManage)
                    <div class="border-t px-5 py-3" style="border-color:var(--border)">
                        @if ($creatingIn === $project['id'])
                            <form wire:submit="addEnvironment" class="flex flex-wrap items-start gap-2">
                                <div class="min-w-[12rem] flex-1">
                                    <input wire:model="newEnvironment" type="text" class="input" placeholder="Staging"
                                           aria-label="Environment name"
                                           @error('newEnvironment') aria-invalid="true" aria-describedby="new-env-error" @enderror>
                                    @error('newEnvironment') <p id="new-env-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                                </div>
                                <select wire:model="newEnvironmentType" class="input w-auto shrink-0" aria-label="Environment type">
                                    <option value="production">Production</option>
                                    <option value="sandbox">Sandbox</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm shrink-0" wire:loading.attr="disabled" wire:target="addEnvironment">Create</button>
                                <button type="button" class="btn btn-ghost btn-sm shrink-0" wire:click="cancelCreate">Cancel</button>
                            </form>
                        @elseif ($project['atLimit'])
                            <p class="text-xs" style="color:var(--faint)">This project is at its environment limit. Upgrade its plan to add more.</p>
                        @else
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="startCreate('{{ $project['id'] }}')">
                                <x-icon name="plus" class="w-4 h-4" /> New environment
                            </button>
                        @endif
                    </div>
                @endif
            </section>
        @empty
            <div class="rounded-xl border p-8 text-center" style="border-color:var(--border)">
                <p class="font-medium">No projects yet</p>
                <p class="mx-auto mt-1 max-w-md text-sm" style="color:var(--muted)">A <strong>project</strong> is one product's IdP. It holds isolated <strong>environments</strong> — production and sandbox — each with its own users, keys and sign-in, and is billed on its own plan.</p>
                @if ($canManage)
                    <a href="{{ route('projects.create') }}" class="btn btn-primary btn-sm mt-4"><x-icon name="plus" class="w-4 h-4" /> Create your first project</a>
                @endif
            </div>
        @endforelse
    </div>
</div>
