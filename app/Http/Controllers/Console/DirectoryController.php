<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Actions\Directories\ConnectPullDirectory;
use App\Actions\Directories\DeleteDirectory;
use App\Actions\Directories\MapDirectoryGroup;
use App\Actions\Directories\RegisterScimDirectory;
use App\Actions\Directories\RotateDirectoryToken;
use App\Actions\Directories\SetDirectoryStatus;
use App\Actions\Directories\UpdateDirectory;
use App\Actions\PortalLinks\CreatePortalLink;
use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\PaginationProps;
use App\Http\Requests\Console\ConnectDirectoryRequest;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\Console\Vocabulary;
use App\Platform\Entitlements;
use App\Platform\Enums\PortalIntent;
use App\Platform\Help\HelpTopic;
use App\Platform\OrgAccessRoles;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\AccessControl\Contracts\GroupRoleMappings;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Directory\ValueObjects\RegisteredDirectory;
use Cbox\Id\Federation\ProviderCatalog;
use Cbox\Id\Federation\ValueObjects\ProviderParameter;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › SYNC USERS IN — every directory connection that feeds people INTO the
 * platform: a SCIM endpoint the customer's identity provider posts to, or an API-pull
 * connector fetched from on a schedule.
 *
 * ONE CONTROLLER, BOTH PLANES, and this pair had the worst drift in the console. The
 * organization plane offered Google Workspace and Microsoft Entra as pull directories;
 * the environment plane offered SCIM and nothing else, so an administrator who holds every
 * organization in the environment could not connect either of the two providers the
 * product ships connectors for. Both are offered on both planes now.
 *
 * Directories are environment-owned, so every read is already fenced to this environment;
 * the organization scoping below is the second fence, between the tenants inside it.
 */
final readonly class DirectoryController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();

        // The organization this page is about — the member's own, or the one an
        // organization's Directory Sync tab names — and null on the environment-wide list.
        $organizationId = $this->routeOrganizationId();

        $query = $filter->apply(Directory::query())->orderByDesc('created_at');

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $directories = $query->paginate(self::PER_PAGE)->withQueryString();

        // Only the organizations this PAGE names. Plucking the whole environment's to
        // label at most 25 rows pulled a table that grows with the tenant count into
        // memory on every render.
        $owners = Organization::query()
            ->whereIn('id', $directories->pluck('organization_id')->filter()->unique())
            ->pluck('name', 'id');

        return $this->page('console/directories/index', Vocabulary::DIRECTORY_SYNC, [
            'help' => HelpProps::for(HelpTopic::SyncUsersIn),
            'directories' => $directories->getCollection()->map(fn (Directory $directory): array => [
                'id' => $directory->id,
                'name' => $directory->name,
                'provider' => $directory->provider->label(),
                'pull' => $directory->provider->isPull(),
                'active' => $directory->status === DirectoryStatus::Active,
                'status' => ucfirst($directory->status->value),
                'lastSyncError' => $directory->last_sync_error,
                'owner' => $owners[$directory->organization_id] ?? $directory->organization_id,
                'href' => $this->url('directories.show', $directory->id),
            ])->values()->all(),
            'pagination' => PaginationProps::from($directories),
            'search' => $term,
            // The view's own authorization question, asked through the SCOPE so it gets
            // the same answer on both planes. It used to read `CurrentUser::isAdmin()`,
            // which is a question only the organization plane can answer — on the
            // environment plane that renders a read-only shell with no way to connect
            // anything.
            'mayAdminister' => $this->scope->mayAdminister(),
            /*
             * The environment-wide list is about no organization, so it is never told an
             * organization's plan lacks this — an upsell there would be about a decision
             * nobody has made. Entitlement is asked on the organization's own page.
             */
            'entitled' => $organizationId === null || $this->scope->entitled('scim'),
            'showsEveryOrganization' => $organizationId === null,
            'organizationFilter' => $this->organizationFilterProps($filter),
            'scimBaseUrl' => url('/scim/v2'),
            'createHref' => $this->createUrl('directories.create'),
            // A portal link is for ONE organization's IT administrator.
            'inviteHref' => $organizationId === null ? null : $this->url('directories.invite'),
        ]);
    }

    /**
     * Mint a single-use Admin Portal link so an administrator can hand SCIM setup to an
     * external IT administrator without granting them an account.
     *
     * The organization console had this and the environment console did not — the plane
     * whose administrator is most likely to be setting a tenant up in the first place.
     */
    public function invite(): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // Resolved BEFORE the entitlement is asked, so a request about no organization is
        // refused for the reason that is true rather than told a plan does not cover this.
        // On the environment console this route lives under the organization's own URL.
        $organizationId = $this->scope->requireOrganizationId();

        abort_unless($this->scope->entitled('scim'), 403);

        // The action a management key mints the same link with; it records WHO minted it,
        // asked through the scope — the two consoles once recorded ids from two tables.
        $result = $this->act(CreatePortalLink::class, ['organization_id' => $organizationId, 'intents' => [PortalIntent::Dsync->value]]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        // ON THE FLASH CHANNEL: the link admits its holder to this tenant's SCIM setup
        // with no account at all, and page props are written into the history entry.
        $this->inertia->flash('portalUrl', $result->value);

        return back();
    }

    public function create(DirectoryConnectors $connectors): Response|RedirectResponse
    {
        $this->scope->assertMayAdminister();

        // The step-up at the door, before there is a form to lose. The half that ENFORCES
        // is in the two writes below.
        $sudo = $this->registrationChallenge();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $organizationId = $this->prefilledOrganizationId();

        /*
         * The enum is the public contract, so both planes offer what it publishes — minus
         * any provider with no registered connector, which could not be reached if it were
         * chosen. Still the enum and not the catalogue: this is the list of things that
         * can be STORED on a directory row, and SCIM is one of them without being a
         * catalogue provider at all.
         */
        $providers = array_values(array_filter(
            DirectoryProvider::cases(),
            fn (DirectoryProvider $option): bool => ! $option->isPull() || $connectors->has($option),
        ));

        return $this->page('console/directories/create', 'New directory', [
            'providers' => array_map(fn (DirectoryProvider $provider): array => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'pull' => $provider->isPull(),
                /*
                 * The provider's own setup guide, when there is one.
                 *
                 * This page used to have nothing to say. The steps for connecting Google as
                 * a directory existed — in the framework, beside the steps for connecting
                 * Google for sign-in — and the two registries naming the same provider
                 * shared nothing, so the screen could not reach them.
                 *
                 * Null for SCIM, which has no catalogue entry BY DESIGN: it is a protocol
                 * the customer's provider speaks to US, so the fields on that form are ours
                 * rather than theirs.
                 */
                'setup' => self::setupProps($provider),
            ], $providers),
            // "For which organization?" on the environment console; prefilled and locked
            // when the form was opened from an organization's own page.
            'organization' => $this->organizationPicker(),
            'entitled' => $this->organizationEntitled($organizationId, 'scim'),
            'indexHref' => $this->url('directories'),
            'urls' => [
                'register' => $this->url('directories.store'),
                'connect' => $this->url('directories.connect'),
            ],
        ]);
    }

    /**
     * Register a SCIM (push) directory, mint its bearer token, and route to the page that
     * reveals it.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        /*
         * OUTBOUND REACH, handed to an account whose address nobody has confirmed. This
         * connection makes the platform ITSELF send requests to a URL the creator chose,
         * or pull identities from one — the same class as a webhook, which this console
         * has always gated.
         */
        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('connect a directory');
        }

        $organizationId = $this->entitledOrganizationId($request);

        $request->validate(['name' => ['required', 'string', 'max:120']]);

        // LAST, after authorization and after the refusals above — the same order the
        // token rotation uses. Normally a no-op, because `create()` already asked.
        $sudo = $this->registrationChallenge();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $result = $this->act(RegisterScimDirectory::class, [
            'organization_id' => $organizationId,
            'name' => trim((string) $request->string('name')),
        ], fallback: 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var RegisteredDirectory $registered */
        $registered = $result->value;

        // The plaintext bearer token, revealed exactly once on the detail page. Only its
        // hash is persisted, so it can never be retrieved again after this hand-off.
        $this->inertia->flash('newToken', $registered->token);

        return to_route($this->scope->routeName('directories.show'), $registered->directory->id)
            ->with('status', 'Directory registered — copy the bearer token, it is shown only once.');
    }

    /**
     * Connect an API-pull directory: verify the credentials against the provider, register
     * it with them sealed, and run the first sync now.
     *
     * THE VERIFY STEP IS THE POINT OF THE PAGE. Storing credentials we have never used
     * means the first anyone hears of a wrong key is a nightly sync that quietly
     * provisions nobody.
     */
    public function connect(ConnectDirectoryRequest $request, DirectoryConnectors $connectors): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        if ($this->scope->plane() === ConsolePlane::Organization) {
            app(VerifiedEmailGate::class)->require('connect a directory');
        }

        $organizationId = $this->entitledOrganizationId($request);
        $provider = $request->provider();

        // Refused here rather than left to raise inside the registry: `scim` is a real
        // case of the enum with no pull connector at all.
        if (! $provider->isPull() || ! $connectors->has($provider)) {
            return back()->withInput()->withErrors(['provider' => 'Choose a directory provider.']);
        }

        // Asked here too, in the form's own words for its own fields, before a password
        // prompt stands between the person and a typo.
        if ($request->credentials($provider) === null) {
            return back()->withInput()->withErrors([
                'credentials' => $request->credentialProblem($provider),
            ]);
        }

        /*
         * The same gate, before the write. The pull path mints nothing and reveals
         * nothing, so it is not the exfiltration shape the rest of this guards — but it
         * seals a customer's provider credentials into the environment and opens a standing
         * sync that creates and deactivates their people, which a session that cannot be
         * shown to still be the administrator's has no business starting. Asked before the
         * action, which verifies the credentials and registers in one step; `create()`
         * already asked, so this is normally a no-op.
         */
        $sudo = $this->registrationChallenge();

        if ($sudo !== null) {
            return to_route($sudo);
        }

        // Verified against the provider, registered with the credentials sealed, and the
        // first sync run — so the administrator watches people arrive rather than an empty
        // directory. A failed first fetch is recorded on the directory, not rolled back.
        $result = $this->act(ConnectPullDirectory::class, [
            'organization_id' => $organizationId,
            'provider' => $provider->value,
            'credentials' => $provider === DirectoryProvider::GoogleWorkspace
                ? ['service_account_json' => (string) $request->string('googleServiceAccountJson'), 'admin_email' => (string) $request->string('googleAdminEmail')]
                : ['tenant_id' => (string) $request->string('entraTenantId'), 'client_id' => (string) $request->string('entraClientId'), 'client_secret' => (string) $request->string('entraClientSecret')],
        ], ['credentials' => 'credentials', 'provider' => 'provider'], fallback: 'credentials');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /** @var Directory $directory */
        $directory = $result->value;

        return to_route($this->scope->routeName('directories.show'), $directory->id)
            ->with('status', $provider->label().' connected — users are syncing.');
    }

    public function show(string $directory, GroupRoleMappings $mappings, OrgAccessRoles $catalog): Response
    {
        $this->scope->assertMayAdminister();

        $model = $this->directory($directory);
        $organizationId = $model->organization_id;

        $groups = DirectoryGroup::query()
            ->where('directory_id', $model->id)
            ->orderBy('display_name')
            ->get();

        /*
         * Roles a group may map onto: the TENANT plane's set, on both consoles. Who is in
         * a group is decided by the customer's own identity provider, so a mapping is a
         * grant the customer controls — the framework refuses a staff-only role in one
         * ({@see GroupRoleMappings::map()}), and offering it here would be a checkbox that
         * cannot be ticked.
         */
        $roles = $catalog->tenantAssignable($organizationId);

        $appNames = Client::query()
            ->whereIn('client_id', $roles->pluck('client_id')->filter()->unique())
            ->pluck('name', 'client_id');

        $mapped = GroupRoleMapping::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->groupBy('group_id')
            ->map(fn ($group): array => $group->pluck('role_id')->all());

        return $this->page('console/directories/show', $model->name, [
            'directory' => [
                'id' => $model->id,
                'name' => $model->name,
                'provider' => $model->provider->value,
                'providerLabel' => $model->provider->label(),
                'scim' => $model->provider === DirectoryProvider::Scim,
                'active' => $model->status === DirectoryStatus::Active,
                'status' => ucfirst($model->status->value),
                'lastSyncError' => $model->last_sync_error,
            ],
            /*
             * The provider's own guide, for the one place a person needs it after setup: a
             * sync that has started failing. "Graph users request failed (403)" is a
             * permission that was never granted or a secret that expired, and the steps
             * that say which are the same ones the create page shows.
             */
            'setup' => self::setupProps($model->provider),
            'organizationName' => Organization::query()->whereKey($organizationId)->value('name') ?? $organizationId,
            'scimBaseUrl' => url('/scim/v2'),
            'groups' => $groups->map(fn (DirectoryGroup $group): array => [
                'id' => $group->id,
                'name' => $group->display_name,
                'roleIds' => $mapped[$group->id] ?? [],
            ])->values()->all(),
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'app' => $role->client_id === null ? null : ($appNames[$role->client_id] ?? $role->client_id),
            ])->values()->all(),
            // Asked once, so the buttons are not offered and then refused.
            'mayChange' => $this->mayChange($model),
            'indexHref' => $this->url('directories'),
            'urls' => [
                'update' => $this->url('directories.update', $model->id),
                'rotate' => $this->url('directories.rotate', $model->id),
                'toggle' => $this->url('directories.toggle', $model->id),
                'destroy' => $this->url('directories.destroy', $model->id),
                'map' => $this->url('directories.map', $model->id),
            ],
        ]);
    }

    public function update(Request $request, string $directory): RedirectResponse
    {
        $model = $this->changeable($directory);

        $request->validate(['name' => ['required', 'string', 'max:120']]);

        $result = $this->act(UpdateDirectory::class, [
            'id' => $model->id,
            'organization_id' => $this->scope->organizationId(),
            'name' => trim((string) $request->string('name')),
        ], fallback: 'name');

        return $result instanceof RedirectResponse ? $result : back()->with('status', 'Directory updated.');
    }

    /**
     * Rotate the SCIM bearer token: mint a fresh secret, store only its hash, and reveal
     * the plaintext once. Any token the customer's IdP already holds stops working
     * immediately.
     *
     * BEHIND A STEP-UP, for the same reason the app-secret rotation is: this reveals a
     * live inbound credential for a tenant's directory, and on the environment plane the
     * administrator reaches every tenant's. It is also destructive in its own right —
     * whatever Okta or Entra currently holds stops working the moment this runs.
     */
    public function rotate(string $directory): RedirectResponse
    {
        $model = $this->changeable($directory);

        // Only meaningful for a SCIM (push) directory — a pull directory authenticates
        // OUTWARD to the provider's API and has no inbound token.
        if ($model->provider !== DirectoryProvider::Scim) {
            return back()->with('error', 'A pull directory authenticates to the provider and has no inbound token to rotate.');
        }

        // LAST, after authorization and after the provider refusal.
        $sudo = app(ConsoleStepUp::class)->challenge(
            'directories.show',
            'environment.directories.show',
            ['directory' => $model->id],
            'Rotating this directory\'s bearer token stops the one your identity provider holds, until you paste the new one in.',
        );

        if ($sudo !== null) {
            return to_route($sudo);
        }

        $result = $this->act(RotateDirectoryToken::class, ['id' => $model->id, 'organization_id' => $this->scope->organizationId()]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        // Shown once on the next render; only its hash persists.
        $this->inertia->flash('newToken', $result->value);

        return back()->with('status', 'Bearer token rotated — the previous token no longer works.');
    }

    public function toggle(string $directory): RedirectResponse
    {
        $model = $this->changeable($directory);

        // The switch sends the state it moves TO, read from the row it was drawn from.
        $resume = $model->status !== DirectoryStatus::Active;

        $result = $this->act(SetDirectoryStatus::class, [
            'id' => $model->id,
            'active' => $resume,
            'organization_id' => $this->scope->organizationId(),
        ]);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', $resume
            ? 'Directory enabled — provisioning resumes.'
            : 'Directory paused — provisioning is suspended.');
    }

    public function destroy(string $directory): RedirectResponse
    {
        $model = $this->changeable($directory);

        $result = $this->act(DeleteDirectory::class, ['id' => $model->id, 'organization_id' => $this->scope->organizationId()]);

        return $result instanceof RedirectResponse ? $result : to_route($this->scope->routeName('directories'))->with('status', 'Directory deleted.');
    }

    /**
     * Map a directory group onto a role, or unmap it — everyone in the group gets the role
     * as membership syncs.
     *
     * ONE ENDPOINT for both directions, because they are one control: a checkbox. Two
     * endpoints is how the environment console ended up scoping only the map half.
     */
    public function map(Request $request, string $directory): RedirectResponse
    {
        $model = $this->changeable($directory);

        $request->validate([
            'group' => ['required', 'string'],
            'role' => ['required', 'string'],
            'mapped' => ['required', 'boolean'],
        ]);

        // The group id comes straight off the page, so it is resolved WITHIN the directory
        // the caller has already been authorized for rather than trusted. The organization
        // console scoped neither direction, and the environment console scoped only the
        // map half — leaving an id from another directory able to be unmapped through this
        // page.
        $group = $this->group($model, (string) $request->string('group'));

        // Another organization's role, an orphaned one, or a staff-only one — none of which
        // the picker offers — is a posted id, refused by the action in words on `role`.
        $result = $this->act(MapDirectoryGroup::class, [
            'id' => $model->id,
            'organization_id' => $this->scope->organizationId(),
            'group_id' => $group->id,
            'role_id' => (string) $request->string('role'),
            'mapped' => $request->boolean('mapped'),
        ], ['group_id' => 'group', 'role_id' => 'role'], fallback: 'role');

        return $result instanceof RedirectResponse ? $result : back();
    }

    /**
     * The directory, re-resolved and re-scoped on every read and write.
     *
     * The organization scoping is the second fence; the model's own environment scope is
     * the first.
     */
    private function directory(string $id): Directory
    {
        $organizationId = $this->scope->organizationId();

        $directory = Directory::query()
            ->whereKey($id)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->first();

        abort_if($directory === null, 404);

        return $directory;
    }

    /** The directory, refused unless this administrator may CHANGE it. */
    private function changeable(string $id): Directory
    {
        $this->scope->assertMayAdminister();

        $directory = $this->directory($id);

        abort_unless($this->mayChange($directory), 403);

        return $directory;
    }

    /**
     * Whether this administrator may change this directory.
     *
     * The entitlement is asked of the directory's OWN organization rather than of the
     * page's. They are the same whenever the page is about one organization — the lookup
     * guarantees it — and when it is not (an environment administrator's directory page)
     * the directory's organization is the only honest answer; asking the scope there would
     * answer "not entitled" for every directory in the environment.
     */
    private function mayChange(Directory $directory): bool
    {
        return $this->scope->mayAdminister()
            && app(Entitlements::class)->entitled($directory->organization_id, 'scim');
    }

    /**
     * A group, resolved INSIDE this directory.
     *
     * 404 rather than 403: an id from another directory is a row this page has no business
     * confirming the existence of.
     */
    private function group(Directory $directory, string $groupId): DirectoryGroup
    {
        $group = DirectoryGroup::query()
            ->whereKey($groupId)
            ->where('directory_id', $directory->id)
            ->first();

        abort_if($group === null, 404);

        return $group;
    }

    /**
     * The organization to connect a directory for, with the entitlement enforced.
     *
     * The form's "For which organization?" on the environment console (checked against this
     * environment, and bound for the request), the member's own elsewhere. The entitlement is
     * a hard 403 — but only once an organization IS resolved: a form that named none is told
     * to name one, not that a plan lacks something.
     */
    private function entitledOrganizationId(Request $request): string
    {
        $organizationId = (string) $this->chosenOrganizationId($request);

        abort_unless($this->scope->entitled('scim'), 403);

        return $organizationId;
    }

    /**
     * The step-up in front of registering a directory, or null when it is already open.
     *
     * A SCIM registration mints `scim_…` — the bearer token that authenticates every
     * inbound provisioning call for this organization — and hands it over in plaintext,
     * which is precisely what the rotation is gated for. Gating only rotation left the
     * cheaper path: register a second directory and read its token off the confirmation.
     */
    private function registrationChallenge(): ?string
    {
        return app(ConsoleStepUp::class)->challenge(
            'directories.create',
            'environment.directories.create',
            [],
            'Connecting a directory issues a bearer token that provisions your people, shown once on the next screen.',
        );
    }

    /**
     * A provider's setup guide, flattened for the page.
     *
     * @return array<string, mixed>|null
     */
    private static function setupProps(DirectoryProvider $provider): ?array
    {
        $setup = ProviderCatalog::forDirectory($provider)?->directory;

        if ($setup === null) {
            return null;
        }

        return [
            'steps' => $setup->setupSteps,
            'docs' => $setup->documentationUrl,
            // The credential fields the CONNECTOR actually reads, so the labels and the
            // help on the form come from the same declaration the connector is checked
            // against rather than from a second copy that ages here.
            'credentials' => array_map(fn (ProviderParameter $credential): array => [
                'key' => $credential->key,
                'label' => $credential->label,
                'help' => $credential->help,
                'example' => $credential->example,
            ], $setup->credentials),
        ];
    }
}
