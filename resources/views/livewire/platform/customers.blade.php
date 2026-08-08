<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use App\Platform\Console\ConsoleScope;
use App\Platform\MailLinks;
use Carbon\CarbonInterface;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The operator's customer list — every customer on the install, and the
 * platform's off-switch for one.
 *
 * {@see Accounts::suspend()} has always been implemented (it also drops each owned
 * environment's resolution-cache entry, so the environments stop serving auth on the
 * NEXT request rather than at a TTL) but had no caller anywhere: operators got
 * environments, organizations, operators, security, usage and search, and no way to
 * reach a customer at all. A junk or abusive signup could not be turned off.
 *
 * A customer is an organization in the platform root, so these reads pin that scope —
 * every query here is global and spans planes, exactly like the Environments screen.
 * Suspension is reversible on this same screen; nothing here deletes or purges, which
 * is a separate, later stage.
 *
 * The counts are LINKS. They used to be numbers and nothing else — this file contained
 * no `route()` call at all — so an operator learned that Acme has three projects and
 * three environments and had nowhere to click: the only way to find out which three was
 * the flat environments list and prior knowledge of Acme's plane names. Every count now
 * lands on the customer's own page (`platform.customers.show`), where organization → project →
 * environment is walkable.
 */
new #[Layout('components.layouts.app', ['title' => 'Customers'])] class extends Component
{
    /** Re-check operator AUTHORITY on every request, including Livewire actions. */
    public function boot(ConsoleScope $scope): void
    {
        abort_unless($scope->isPlatformOperator(), 404);
    }

    /** Whether the new-customer form is open. */
    public bool $creating = false;

    public string $newName = '';

    public string $newOwnerEmail = '';

    public string $newOwnerName = '';

    public int $newEnvironmentLimit = 2;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'newName' => ['required', 'string', 'min:2', 'max:120'],
            // Not unique: one person can own several customers, and
            // TenantProvisioner reuses the subject that address already has rather than
            // minting a second identity for them.
            //
            // `email` rather than `email:rfc,dns`, matching signup. The `dns` check is a
            // live MX lookup on the request path, and refusing an address because its
            // domain has no mail record YET is wrong on exactly this form: an operator
            // onboards a customer before their DNS is cut over more often than after.
            'newOwnerEmail' => ['required', 'email', 'max:255'],
            'newOwnerName' => ['required', 'string', 'min:2', 'max:120'],
            'newEnvironmentLimit' => ['required', 'integer', Rule::in([1, 2, 3, 5, 10, 25])],
        ];
    }

    /**
     * Stand up a whole customer — the organization, its owner, its first IdP product and
     * that product's first environment.
     *
     * THERE WAS NO WAY TO DO THIS. `TenantProvisioner::provision()` is the package's own
     * entry point, exercised by the installer, by signup and by tests, and the operator
     * console — the one surface whose entire job is running the deployment — had no caller
     * for it anywhere. An operator could suspend a customer, walk their estate and
     * impersonate their people, and could not create one. Onboarding a customer who had
     * not self-served meant tinker on a production console.
     *
     * NO PASSWORD IS CHOSEN FOR THE OWNER, and that is the whole shape of this method.
     * The blueprint requires one, so it gets a throwaway that is generated here, never
     * rendered, never logged and never returned — the owner is then sent a reset link and
     * picks their own. An operator who typed a password for somebody else would be an
     * operator who knows a customer's credential, which is precisely the authority the
     * platform is not supposed to have over its customers' identities. If the address
     * already holds a Cbox ID the provisioner attaches to it and the existing password
     * stands untouched.
     *
     * `provision()` is one transaction, so a failure leaves no half-born customer. The
     * mail is sent AFTER it returns for the same reason: a reset link for an organization
     * that rolled back is a link to nowhere.
     */
    public function create(
        TenantProvisioner $provisioner,
        PasswordReset $resets,
        MailLinks $links,
        ConsoleScope $scope,
    ): void {
        abort_unless($scope->isPlatformOperator(), 404);

        $this->validate();

        // Normalized ONCE and reused for the mail, rather than read back off the created
        // subject: `Subject::$email` is nullable, and the honest way to satisfy that is to
        // send to the address that was actually provisioned rather than to assert the
        // model cannot have lost it.
        $ownerEmail = mb_strtolower(trim($this->newOwnerEmail));

        try {
            $tenant = $provisioner->provision(new TenantBlueprint(
                organizationName: trim($this->newName),
                ownerEmail: $ownerEmail,
                ownerName: trim($this->newOwnerName),
                // 64 random characters, discarded on the next line. It exists only because
                // the blueprint's contract requires a credential; nothing here can read it
                // back and nobody is ever told it.
                ownerPassword: Str::random(64),
                environmentLimit: $this->newEnvironmentLimit,
            ));
        } catch (EnvironmentLimitReached|\InvalidArgumentException $e) {
            // The two refusals a valid form can still hit: a domain already in use, and a
            // deployment with no platform root. Both are sentences worth showing rather
            // than a 500 — the second in particular means "run the installer", which an
            // operator can act on.
            $this->addError('newName', $e->getMessage());

            return;
        }

        // The owner's way in. `request()` returns null only for an address with no
        // account, which cannot happen here — provisioning just guaranteed one — so a null
        // means something changed underneath and the operator should be told the customer
        // exists but the invitation did not go.
        $token = $resets->request($ownerEmail);

        if ($token === null) {
            $this->dispatch('toast', message: 'Customer created, but the owner could not be sent a link. Ask them to use "Forgot password".');
        } else {
            Mail::to($ownerEmail)->send(new PasswordResetMail($links->route('password.reset', $token)));

            $this->dispatch('toast', message: 'Customer created. '.$ownerEmail.' has been emailed a link to set their password.');
        }

        $this->reset(['creating', 'newName', 'newOwnerEmail', 'newOwnerName', 'newEnvironmentLimit']);

        $this->redirectRoute('platform.customers.show', $tenant->organization->id, navigate: true);
    }

    /**
     * Suspend or reactivate a customer. Idempotent on the contract's side, so the current
     * status decides the direction rather than a separate flag.
     *
     * The audit entry is written by the CONTRACT, not here. It used to be written at this
     * call site because the customer writer took no actor, unlike
     * {@see Organizations::suspend()} which has always audited internally. An audit written
     * at the call site is one a second caller can silently forget, and this screen was the
     * only caller there had ever been.
     *
     * IN THE PLATFORM ROOT, both the read and the write: `organizations` is
     * environment-owned, so a suspension issued from a tenant host would find nothing to
     * suspend and report success. The account plane sat outside tenancy and never had to
     * think about it, which is exactly why it is easy to lose in the fold.
     */
    public function toggleStatus(string $id, Organizations $organizations, ConsoleScope $scope, PlatformRoot $platformRoot): void
    {
        $actorId = $scope->operator()?->id;
        if ($actorId === null) {
            abort(403);
        }

        $suspending = $platformRoot->run(function () use ($organizations, $id, $actorId): ?bool {
            $organization = $organizations->find($id);

            if ($organization === null) {
                return null;
            }

            $suspending = ! $organization->status->revokesAccess();

            $suspending
                ? $organizations->suspend($id, $actorId)
                : $organizations->reactivate($id, $actorId);

            return $suspending;
        });

        if ($suspending === null) {
            return;
        }

        $this->dispatch('toast', message: $suspending
            ? 'Customer suspended — its members can no longer sign in and its environments stop serving auth.'
            : 'Customer reactivated.');
    }

    /** @return array<string, mixed> */
    public function with(PlatformRoot $platformRoot): array
    {
        // IN THE PLATFORM ROOT: customers ARE organizations now, and organizations are
        // environment-owned. Read under whatever scope the request happened to carry, this
        // page shows a tenant's own end-user organizations — or, off a tenant host, nothing
        // at all. The account plane never had to think about it because `accounts` sat
        // outside tenancy entirely.
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $platformRoot->run(function (): array {
            $organizations = Organization::query()->orderBy('created_at')->get();

            // TENANT SCOPE SUSPENDED. A membership is tenant-owned and the tenant scope is
            // deny-by-default, so this roll-up across every customer counts ZERO from a
            // request that has no tenant in context — silently, and the page would render
            // "0 members" for organizations that have several.
            /** @var Collection<string, int> $memberCounts */
            $memberCounts = app(TenantContext::class)->withoutScope(
                static fn () => Membership::query()->selectRaw('organization_id, count(*) as c')
                    ->groupBy('organization_id')->pluck('c', 'organization_id'),
            );

            /** @var Collection<string, int> $projectCounts */
            $projectCounts = Project::query()->selectRaw('organization_id, count(*) as c')
                ->groupBy('organization_id')->pluck('c', 'organization_id');

            // Environments hang off PROJECTS, so the count runs through them rather than
            // off a denormalized owner column — `environments.account_id` was that column.
            /** @var Collection<string, int> $environmentCounts */
            $environmentCounts = Environment::query()
                ->join('projects', 'projects.id', '=', 'environments.project_id')
                ->selectRaw('projects.organization_id as organization_id, count(*) as c')
                ->groupBy('projects.organization_id')
                ->pluck('c', 'organization_id');

            return $organizations->map(function (Organization $organization) use ($memberCounts, $projectCounts, $environmentCounts): array {
                // The package's model does not declare the Eloquent timestamp columns as
                // @property, so read created_at off the attribute bag and narrow it rather
                // than trusting an undeclared property.
                $createdAt = $organization->getAttribute('created_at');

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'active' => ! $organization->status->revokesAccess(),
                    'members' => (int) ($memberCounts[$organization->id] ?? 0),
                    'projects' => (int) ($projectCounts[$organization->id] ?? 0),
                    'environments' => (int) ($environmentCounts[$organization->id] ?? 0),
                    'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toDayDateTimeString() : null,
                ];
            })->all();
        }) ?? [];

        return ['rows' => $rows];
    }
}; ?>

<div>
    <x-page-header title="Customers"
                   subtitle="Every customer on this install. Open one to walk its products and environments; suspending it signs out its members and stops every environment it owns from serving auth.">
        <x-slot:actions>
            <button type="button" wire:click="$toggle('creating')" class="btn btn-primary"
                    :aria-expanded="@js($creating)">
                <x-icon name="plus" class="w-4 h-4" aria-hidden="true" /> New customer
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Onboarding a customer who did not self-serve. This page could suspend a customer,
         walk their estate and impersonate their people, and could not create one — so the
         only way to stand one up was tinker on a production console. --}}
    @if ($creating)
        <form wire:submit="create" class="cbx-panel mt-6 p-6">
            <h2 class="text-base font-semibold">New customer</h2>
            <p class="mt-1 text-sm" style="color:var(--muted-foreground)">
                Creates the organization, its owner, its first product and that product's production environment.
                The owner is emailed a link to set their own password — you do not choose one for them.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="new-name" class="label">Customer name</label>
                    <input id="new-name" type="text" wire:model="newName" class="input" autocomplete="organization" required>
                    @error('newName')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new-owner-name" class="label">Owner name</label>
                    <input id="new-owner-name" type="text" wire:model="newOwnerName" class="input" autocomplete="name" required>
                    @error('newOwnerName')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new-owner-email" class="label">Owner email</label>
                    <input id="new-owner-email" type="email" wire:model="newOwnerEmail" class="input" autocomplete="email" required>
                    @error('newOwnerEmail')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs" style="color:var(--muted-foreground)">
                        If this address already holds a Cbox ID, it is reused — they will own both.
                    </p>
                </div>

                <div>
                    <label for="new-env-limit" class="label">Environment allowance</label>
                    <select id="new-env-limit" wire:model="newEnvironmentLimit" class="input">
                        @foreach ([1, 2, 3, 5, 10, 25] as $limit)
                            <option value="{{ $limit }}">{{ $limit }}</option>
                        @endforeach
                    </select>
                    @error('newEnvironmentLimit')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs" style="color:var(--muted-foreground)">
                        The plan allowance on their first product. Billing hangs off the product, not the customer.
                    </p>
                </div>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">
                    <span wire:loading.remove wire:target="create">Create customer</span>
                    <span wire:loading wire:target="create">Creating…</span>
                </button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <p role="status" aria-live="polite" class="sr-only">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('customer', count($rows)) }} found.</p>

    {{-- A real table, not a div grid with matching grid-template-columns. The two rows
         resolved their `fr` tracks against different content — the header's last cell was
         an empty span, the body's a button — so by "Created" the data sat 121px left of
         its own heading. A table cannot disagree with itself, and it is what the rest of
         the console uses, so a screen reader gets the column association too. --}}
    @if ($rows === [])
        <div class="cbx-empty mt-8">
            <div class="cbx-empty-icon"><x-icon name="settings" class="w-5 h-5" /></div>
            <h3>No customers yet</h3>
            <p>A customer appears here the moment somebody signs up. Nothing to do until then.</p>
        </div>
    @else
        <div class="cbx-panel overflow-hidden mt-8">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Customer</th>
                            <th scope="col" class="text-right">Members</th>
                            <th scope="col" class="text-right">Projects</th>
                            <th scope="col" class="text-right">Environments</th>
                            <th scope="col">Created</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $accountHref = route('platform.customers.show', $row['id']); @endphp
                            <tr wire:key="account-{{ $row['id'] }}">
                                <td>
                                    <p class="font-semibold">
                                        <a href="{{ $accountHref }}" wire:navigate class="hover:underline">{{ $row['name'] }}</a>
                                        @unless ($row['active'])
                                            <span class="cbx-pill cbx-pill--destructive align-middle ml-1"><span class="dot"></span>Suspended</span>
                                        @endunless
                                    </p>
                                    <p class="text-xs font-mono" style="color:var(--faint)">{{ $row['id'] }}</p>
                                </td>
                                {{-- A count you cannot click is a fact you have to go and look for
                                     somewhere else. Each of these is the same destination — the
                                     account's own page — with an accessible name that says which
                                     part of it you are asking about, because "3" repeated three
                                     times is what a screen reader would otherwise announce. --}}
                                <td class="text-right tabular-nums">
                                    <a href="{{ $accountHref }}" wire:navigate class="hover:underline"
                                       aria-label="{{ $row['members'] }} {{ \Illuminate\Support\Str::plural('member', $row['members']) }} on {{ $row['name'] }}">{{ $row['members'] }}</a>
                                </td>
                                <td class="text-right tabular-nums">
                                    <a href="{{ $accountHref }}" wire:navigate class="hover:underline"
                                       aria-label="{{ $row['projects'] }} {{ \Illuminate\Support\Str::plural('project', $row['projects']) }} on {{ $row['name'] }}">{{ $row['projects'] }}</a>
                                </td>
                                <td class="text-right tabular-nums">
                                    <a href="{{ $accountHref }}" wire:navigate class="hover:underline"
                                       aria-label="{{ $row['environments'] }} {{ \Illuminate\Support\Str::plural('environment', $row['environments']) }} on {{ $row['name'] }}">{{ $row['environments'] }}</a>
                                </td>
                                <td class="whitespace-nowrap text-xs" style="color:var(--faint)">{{ $row['created_at'] ?? '—' }}</td>

                                {{-- A reversible two-way switch, so wire:confirm rather than the
                                     type-to-confirm dialog: that component exists for actions with no way
                                     back, and it stamps the operator's pinned ENVIRONMENT into the dialog,
                                     which is meaningless for an account (accounts sit above every plane).
                                     The copy still names the customer and its blast radius. --}}
                                <td class="text-right whitespace-nowrap">
                                    <a href="{{ $accountHref }}" wire:navigate class="btn btn-ghost btn-sm">Open</a>
                                    <button wire:click="toggleStatus('{{ $row['id'] }}')"
                                            class="btn {{ $row['active'] ? 'btn-ghost' : 'btn-primary' }} btn-sm"
                                            wire:loading.attr="disabled"
                                            wire:target="toggleStatus('{{ $row['id'] }}')"
                                            wire:confirm="{{ $row['active']
                                                ? 'Suspend '.$row['name'].'? Its members are signed out and all '.$row['environments'].' environment(s) it owns stop serving auth on the next request. You can reactivate it here.'
                                                : 'Reactivate '.$row['name'].'? Its members can sign in again and its environments resume serving auth.' }}">
                                        <span class="spinner" wire:loading wire:target="toggleStatus('{{ $row['id'] }}')" aria-hidden="true"></span>
                                        {{ $row['active'] ? 'Suspend' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-4 text-xs" style="color:var(--faint)">
            Suspension is the only lever here, and it is reversible. Nothing on this screen
            deletes or purges an account. An install also holds environments that no account
            owns — the platform root, and any unattached leftover; both are named for what
            they are on <a href="{{ route('platform.environments') }}" wire:navigate class="underline">Environments</a>.
        </p>
    @endif
</div>
