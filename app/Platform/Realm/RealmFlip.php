<?php

declare(strict_types=1);

namespace App\Platform\Realm;

use App\Platform\Organizations\OrganizationLinks;
use App\Platform\SignInThrough;
use Cbox\Id\Kernel\Audit\Chain\CboxIdEntryCodec;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\DatabaseAuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\DatabaseEnvironmentResolver;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\EnvironmentResolutionCache;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * TWO REALMS BECOME ONE.
 *
 * Cbox's own customers have lived twice: as an organization and a person in the platform
 * root (where the console's projects, keys, billing and team hang), and again in the
 * `cbox` environment (where every Cbox app keeps them). `organization_links` joined the
 * two. This makes the `cbox` environment the platform root and retires the old one: the
 * organization and the person that survive are the `cbox` ones, with the ids every app
 * and every token already carries, and everything the old root owned is re-keyed to them.
 *
 * HOW IT RUNS. One database transaction. A dry run does exactly what `--execute` does and
 * rolls back, so the plan it prints is not a prediction: it is what happened, undone. The
 * post-conditions are checked INSIDE the transaction, and a flip that would leave a row
 * naming a retired id never commits. That check searches every text column of every table
 * for the retired ids rather than trusting {@see FlipTables}: the list says what to do
 * with a table, the search proves nothing was left out of it.
 *
 * WHAT IS NOT CARRIED, and why, is in {@see FlipTables} table by table. The three that
 * matter: a person's root-only credentials (their `cbox` account is the one that
 * survives, with its own password, passkeys and second factor); the old root's audit
 * trail (hash-chained over its environment id, so it is archived verbatim instead of
 * re-chained); and every session and token, which are revoked.
 *
 * UNLINKED ORGANIZATIONS AND UNMATCHED PEOPLE. A root organization with no link that owns
 * projects, keys or billing blocks the flip, and so does a member of a surviving
 * organization, or an operator, with no `cbox` account. `--create-missing` resolves both
 * the same way: the row MOVES into the realm with the id it has, so nothing that names it
 * changes (Billing's account key included). There is deliberately no "skip": skipping an
 * organization that owns projects would leave its environments owned by nobody, and
 * {@see DatabaseEnvironmentResolver} stops serving those.
 */
final class RealmFlip
{
    private string $rootId = '';

    private string $realmId = '';

    /** @var array<string, string|null> root organization id => surviving id, null when dropped */
    private array $organizations = [];

    /** @var array<string, string|null> */
    private array $users = [];

    /** @var array<string, string|null> */
    private array $memberships = [];

    /** @var array<string, list<array<string, mixed>>> rows of a table that were not carried */
    private array $dropped = [];

    /** @var list<string> client ids of the platform's own clients that must exist afterwards */
    private array $carriedClients = [];

    private FlipOptions $options;

    private FlipReport $report;

    public function __construct(
        private readonly FlipSchema $schema,
        private readonly EnvironmentContext $context,
    ) {}

    public function run(FlipOptions $options, bool $commit): FlipReport
    {
        $this->options = $options;
        $this->report = $report = new FlipReport;
        $this->organizations = $this->users = $this->memberships = $this->dropped = [];
        $this->carriedClients = [];

        if ($this->alreadyFlipped($report)) {
            return $report;
        }

        if (! $this->locate($report)) {
            return $report;
        }

        DB::beginTransaction();

        try {
            $this->preconditions();
            $report->before = $this->inventory();

            if (! $report->blocked()) {
                $this->apply();
                $report->after = $this->inventory();
                $this->postconditions($report, $this->summary(), fresh: true);

                foreach ($report->failedChecks() as $failed) {
                    $report->block('Post-condition failed, nothing was kept: '.$failed);
                }
            }

            if ($commit && ! $report->blocked()) {
                DB::table('realm_flips')->insert([
                    'id' => Str::lower((string) Str::ulid()),
                    'retired_environment' => $this->rootId,
                    'realm_environment' => $this->realmId,
                    'summary' => json_encode($this->summary(), JSON_THROW_ON_ERROR),
                    'executed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::commit();
                $report->committed = true;
            } else {
                DB::rollBack();
            }
        } catch (Throwable $failure) {
            DB::rollBack();

            throw $failure;
        } finally {
            // A dry run moved rows and put them back; what was cached along the way is stale
            // either way, and after a real flip every cached host answer is.
            $this->forget();
        }

        if ($report->committed) {
            // The flip is committed whatever happens here; a trail entry that cannot be
            // written is said, not thrown.
            try {
                $this->recordInTrail();
            } catch (Throwable $unwritten) {
                $report->warn('The flip is committed, but its `realm.flipped` audit entry could not be written: '.$unwritten->getMessage());
            }
        }

        return $report;
    }

    /**
     * Check a flipped database, any time after the flip: the same post-conditions the
     * flip checked before it committed, read from the record it left.
     */
    public function verify(): FlipReport
    {
        $this->report = $report = new FlipReport;
        $flip = $this->schema->has('realm_flips') ? DB::table('realm_flips')->orderByDesc('executed_at')->first() : null;

        if ($flip === null) {
            $report->check('a flip has been executed', false, 'no row in realm_flips; run --execute first');

            return $report;
        }

        $this->rootId = self::str($flip->retired_environment);
        $this->realmId = self::str($flip->realm_environment);
        $report->rootId = $this->rootId;
        $report->realmId = $this->realmId;

        /** @var array<string, mixed> $summary */
        $summary = json_decode(self::str($flip->summary), true, 512, JSON_THROW_ON_ERROR);
        $summary['executed_at'] = self::str($flip->executed_at);

        $report->fact('Flip executed at '.self::str($flip->executed_at).': '.$this->rootId.' retired, '.$this->realmId.' is the platform root.');
        $this->postconditions($report, $summary, fresh: false);
        $report->after = $this->inventory();

        return $report;
    }

    /*
    |--------------------------------------------------------------------------
    | Finding the two environments
    |--------------------------------------------------------------------------
    */

    private function alreadyFlipped(FlipReport $report): bool
    {
        if (! $this->schema->has('realm_flips')) {
            $report->block('The realm_flips table is missing: run `php artisan migrate` first.');

            return true;
        }

        $flip = DB::table('realm_flips')->orderByDesc('executed_at')->first();

        if ($flip === null) {
            return false;
        }

        $report->alreadyFlipped = true;
        $report->rootId = self::str($flip->retired_environment);
        $report->realmId = self::str($flip->realm_environment);
        $report->fact('This deployment was flipped at '.self::str($flip->executed_at).' ('.$report->rootId.' retired, '.$report->realmId.' is the platform root). Nothing to do; `--verify` checks the result.');

        return true;
    }

    private function locate(FlipReport $report): bool
    {
        $defaults = DB::table('environments')->where('is_default', true)->get(['id', 'slug', 'project_id']);
        $slug = config('cbox-id.cbox_environment');
        $realm = is_string($slug) && $slug !== '' ? DB::table('environments')->where('slug', $slug)->first(['id', 'slug', 'is_default', 'status']) : null;

        if ($defaults->count() !== 1) {
            $report->block('Expected exactly one default environment (the platform root); found '.$defaults->count().'.');
        }

        if ($realm === null) {
            $report->block('No environment with the configured slug `'.(is_string($slug) ? $slug : '').'` (cbox-id.cbox_environment).');
        } elseif ((bool) $realm->is_default) {
            $report->block('The `'.self::str($realm->slug).'` environment is already the default, but no flip is on record. Refusing to guess what state this is.');
        } elseif (self::str($realm->status) !== 'active') {
            $report->block('The `'.self::str($realm->slug).'` environment is '.self::str($realm->status).', not active.');
        }

        if ($report->blocked() || $realm === null) {
            return false;
        }

        $root = $defaults->first();
        $this->rootId = $report->rootId = self::str($root?->id);
        $this->realmId = $report->realmId = self::str($realm->id);

        if ($root?->project_id !== null) {
            $report->block('The default environment belongs to a project: it is not a platform root.');
        }

        $report->fact('Platform root today: '.self::str($root?->slug).' ('.$this->rootId.'). Becomes the root: '.self::str($realm->slug).' ('.$this->realmId.').');

        return ! $report->blocked();
    }

    /*
    |--------------------------------------------------------------------------
    | Preconditions: who is who, and what stands in the way
    |--------------------------------------------------------------------------
    */

    private function preconditions(): void
    {
        $this->undecidedTables();
        $this->mapOrganizations();
        $this->mapPeople();
        $this->operators();
        $this->roleChanges();
        $this->credentials();
        $this->pendingJobs();
        $this->undeliveredEvents();
        $this->auditTables();

        foreach (FlipTables::all() as $table => $entry) {
            if ($this->schema->has($table)) {
                $this->report->decided($table, $entry['decision'], $entry['reason']);
            }
        }
    }

    /** A table that holds rows of the old root and that nobody has decided about. */
    private function undecidedTables(): void
    {
        $decided = FlipTables::all();

        foreach ($this->schema->tables() as $table) {
            $column = $this->schema->environmentColumn($table);

            if ($column === null || isset($decided[$table])) {
                continue;
            }

            $rows = DB::table($table)->where($column, $this->rootId)->count();

            if ($rows > 0) {
                $this->report->block("Table `{$table}` holds {$rows} row(s) of the old root and has no decision in FlipTables. Decide (move, drop, follow the person, archive) before flipping.");
            }
        }
    }

    private function mapOrganizations(): void
    {
        $links = DB::table('organization_links')->pluck('cbox_organization_id', 'organization_id')->all();
        $realm = DB::table('organizations')->where('environment_id', $this->realmId)->get(['id', 'slug', 'status'])->keyBy('id');
        $realmSlugs = $realm->pluck('id', 'slug')->all();

        foreach (DB::table('organizations')->where('environment_id', $this->rootId)->orderBy('slug')->get(['id', 'slug', 'name', 'status']) as $organization) {
            $id = self::str($organization->id);
            $label = self::str($organization->name).' ('.self::str($organization->slug).', '.$id.')';
            $linked = isset($links[$id]) ? self::str($links[$id]) : null;

            if ($linked !== null) {
                $twin = $realm->get($linked);

                if ($twin === null) {
                    $this->report->block("Root organization {$label} is linked to {$linked}, which is not an organization of the realm.");

                    continue;
                }

                if (self::str($twin->status) !== self::str($organization->status)) {
                    $this->report->warn("Root organization {$label} is ".self::str($organization->status).' and its realm organization '.self::str($twin->slug).' is '.self::str($twin->status).': the realm organization keeps its status.');
                }

                if (! in_array(self::str($twin->status), ['active', 'suspended'], true) && $this->owns($id) !== []) {
                    $this->report->block("Root organization {$label} owns ".implode(', ', $this->owns($id)).' but is linked to a realm organization that is '.self::str($twin->status).'.');
                }

                $this->organizations[$id] = $linked;

                continue;
            }

            $owns = $this->owns($id);

            if ($this->options->createMissing) {
                $slug = self::str($organization->slug);
                $note = 'moved into the realm with its own id';

                if (isset($realmSlugs[$slug])) {
                    $note .= ', as `'.$this->freeSlug($slug, $id).'` (its slug is taken there)';
                }

                $this->organizations[$id] = $id;
                $this->report->fact("Unlinked root organization {$label}: {$note}.");

                continue;
            }

            $this->organizations[$id] = null;

            if ($owns !== []) {
                $this->report->block("Root organization {$label} owns ".implode(', ', $owns).' and has no link to a realm organization. Link it (`php artisan orgs:link`), or pass --create-missing to move it into the realm with the id it has.');
            } else {
                $members = DB::table('memberships')->where('organization_id', $id)->count();
                $this->report->warn("Unlinked root organization {$label} owns nothing and is DROPPED with its {$members} membership(s). Pass --create-missing to move it into the realm instead.");
            }
        }
    }

    /** @return list<string> what a root organization owns that must not be orphaned */
    private function owns(string $organizationId): array
    {
        $owns = [];

        foreach (['projects' => 'organization_id', 'organization_api_keys' => 'organization_id', 'entitlements' => 'organization_id', 'usage_counters' => 'organization_id', 'product_billing_usage_outbox' => 'workspace_id', 'connections' => 'organization_id', 'verified_domains' => 'organization_id', 'directories' => 'organization_id', 'oauth_clients' => 'organization_id'] as $table => $column) {
            if (! $this->schema->hasColumn($table, $column)) {
                continue;
            }

            $rows = DB::table($table)->where($column, $organizationId)->count();

            if ($rows > 0) {
                $owns[] = "{$rows} {$table}";
            }
        }

        return $owns;
    }

    private function mapPeople(): void
    {
        $realmByEmail = [];
        $realmStatus = [];

        foreach (DB::table('users')->where('environment_id', $this->realmId)->get(['id', 'email', 'status']) as $user) {
            $realmByEmail[mb_strtolower(self::str($user->email))] = self::str($user->id);
            $realmStatus[self::str($user->id)] = self::str($user->status);
        }

        // "Linked sub": the root signs in through the realm, so a root person carries a
        // federated identity whose subject is their user id in the realm.
        $linked = [];

        foreach (DB::table('identities')->where('environment_id', $this->rootId)->get(['user_id', 'subject']) as $identity) {
            if (isset($realmStatus[self::str($identity->subject)])) {
                $linked[self::str($identity->user_id)][] = self::str($identity->subject);
            }
        }

        $needed = $this->neededPeople();

        foreach (DB::table('users')->where('environment_id', $this->rootId)->orderBy('email')->get(['id', 'email']) as $user) {
            $id = self::str($user->id);
            $email = mb_strtolower(self::str($user->email));
            $bySub = array_values(array_unique($linked[$id] ?? []));
            $byEmail = $realmByEmail[$email] ?? null;

            if (count($bySub) > 1) {
                $this->report->block("Root person {$email} ({$id}) is linked to more than one realm account: ".implode(', ', $bySub).'.');

                continue;
            }

            $match = $bySub[0] ?? $byEmail;

            if ($bySub !== [] && $byEmail !== null && $bySub[0] !== $byEmail) {
                $this->report->warn("Root person {$email} ({$id}) signs in as realm account {$bySub[0]}, but the realm account with that address is {$byEmail}. The linked account is used.");
            }

            if ($match !== null) {
                if (($realmStatus[$match] ?? '') !== 'active' && isset($needed[$id])) {
                    $this->report->block("Root person {$email} ({$id}) matches realm account {$match}, which is ".($realmStatus[$match] ?? 'missing').'. '.$needed[$id].'.');
                }

                $this->users[$id] = $match;

                continue;
            }

            if ($this->options->createMissing) {
                $this->users[$id] = $id;
                $this->report->fact("Root person {$email} ({$id}) has no realm account: moved into the realm with their id, password, second factor and passkeys.");

                continue;
            }

            $this->users[$id] = null;

            if (isset($needed[$id])) {
                $this->report->block("Root person {$email} ({$id}) has no account in the realm (no linked sub, no matching address). {$needed[$id]}. Have them sign in to the realm once, or pass --create-missing to move the account.");
            } else {
                $this->report->warn("Root person {$email} ({$id}) has no account in the realm and belongs to nothing that survives: DROPPED. Pass --create-missing to move the account instead.");
            }
        }
    }

    /** @return array<string, string> root user id => why the flip needs them to exist afterwards */
    private function neededPeople(): array
    {
        $needed = [];
        $surviving = array_keys(array_filter($this->organizations, static fn (?string $new): bool => $new !== null));

        foreach (DB::table('memberships')->where('environment_id', $this->rootId)->whereIn('organization_id', $surviving)->get(['user_id', 'role']) as $membership) {
            $needed[self::str($membership->user_id)] = 'They are '.self::str($membership->role).' of an organization that survives';
        }

        foreach (DB::table('platform_operators')->whereNotNull('subject_id')->get(['subject_id']) as $operator) {
            $needed[self::str($operator->subject_id)] = 'They are a platform operator';
        }

        return $needed;
    }

    private function operators(): void
    {
        $operators = DB::table('platform_operators')->get(['id', 'email', 'status', 'subject_id']);

        if ($operators->where('status', 'active')->isEmpty()) {
            $this->report->block('No active platform operator: nobody could open /admin after the flip. Grant one first (`php artisan cbox-id:operator:grant`).');
        }

        foreach ($operators as $operator) {
            $subject = $operator->subject_id === null ? null : self::str($operator->subject_id);

            if ($subject === null) {
                $this->report->warn('Operator '.self::str($operator->email).' is not linked to a person (a pre-subject record). It is left as it is; grant them again after the flip.');
            } elseif (! array_key_exists($subject, $this->users)) {
                $this->report->block('Operator '.self::str($operator->email)." is linked to {$subject}, who is not a person of the old root.");
            }
        }
    }

    /** Where the root and the realm disagree about a person's role, say which one they keep. */
    private function roleChanges(): void
    {
        foreach (DB::table('memberships')->where('environment_id', $this->rootId)->get() as $membership) {
            $organization = $this->organizations[self::str($membership->organization_id)] ?? null;
            $user = $this->users[self::str($membership->user_id)] ?? null;

            if ($organization === null || $user === null) {
                continue;
            }

            $existing = DB::table('memberships')->where('organization_id', $organization)->where('user_id', $user)->where('id', '!=', $membership->id)->first();

            if ($existing === null) {
                continue;
            }

            $role = $this->roleFor(self::row($membership), self::row($existing));

            if ($role !== self::str($existing->role)) {
                $this->report->warn("Membership of {$user} in {$organization}: root says ".self::str($membership->role).', realm says '.self::str($existing->role).". They keep {$role} (--role-conflict={$this->options->roleConflict}); this is the role every Cbox app sees.");
            } elseif ($role !== self::str($membership->role)) {
                $this->report->warn("Membership of {$user} in {$organization}: root says ".self::str($membership->role).", realm says {$role}. They keep {$role}, so their console authority changes.");
            }
        }
    }

    /** People whose root-only credentials are not carried, and who have none in the realm. */
    private function credentials(): void
    {
        $operatorSubjects = DB::table('platform_operators')->whereNotNull('subject_id')->pluck('subject_id')->map(static fn (mixed $id): string => self::str($id))->all();

        foreach ($this->users as $old => $new) {
            if ($new === null || $new === $old) {
                continue;
            }

            foreach (['webauthn_credentials' => ['user_id', 'passkey(s)'], 'mfa_factors' => ['user_id', 'second factor(s)'], 'id_devices' => ['subject_id', 'Authenticator handset(s)']] as $table => [$column, $what]) {
                if (! $this->schema->has($table)) {
                    continue;
                }

                $root = DB::table($table)->where($column, $old)->count();
                $realm = DB::table($table)->where($column, $new)->count();

                if ($root > 0) {
                    $this->report->fact("Person {$old} → {$new}: {$root} root {$what} not carried; their realm account has {$realm}.");
                }

                if ($root > 0 && $realm === 0) {
                    $this->report->warn("Person {$new} had {$root} {$what} on the old root and has none in the realm: they enrol again after the flip.");
                }
            }

            if (in_array($old, $operatorSubjects, true)
                && DB::table('webauthn_credentials')->where('user_id', $new)->count() === 0
                && DB::table('mfa_factors')->where('user_id', $new)->whereNotNull('confirmed_at')->count() === 0) {
                $this->report->warn("Operator {$new} has no passkey or confirmed second factor in the realm: /admin stays closed to them until they enrol one.");
            }
        }
    }

    private function pendingJobs(): void
    {
        $ids = [$this->rootId, ...array_keys($this->organizations), ...array_keys($this->users)];
        $driver = config('queue.default');
        $driver = is_string($driver) ? config("queue.connections.{$driver}.driver") : null;

        if ($this->schema->has('jobs')) {
            $referencing = 0;

            foreach (DB::table('jobs')->orderBy('id')->cursor() as $job) {
                $payload = self::str($job->payload);

                foreach ($ids as $id) {
                    if (str_contains($payload, $id)) {
                        $referencing++;

                        break;
                    }
                }
            }

            if ($referencing > 0) {
                $this->report->block("{$referencing} pending job(s) in the jobs table name an id of the old root. Let the workers drain the queues, stop them, then flip.");
            }
        }

        if ($driver === 'database' || $driver === 'sync' || $driver === 'null') {
            return;
        }

        // A queue that lives outside the database cannot be searched for an id from here.
        $waiting = 0;

        try {
            $queues = array_values(array_unique(array_filter([
                config('queue.connections.'.self::str(config('queue.default')).'.queue'),
                'default',
            ], 'is_string')));

            foreach ($queues as $queue) {
                $waiting += app('queue')->connection()->size($queue);
            }
        } catch (Throwable $unreadable) {
            $this->report->warn('The queue could not be read ('.$unreadable->getMessage().'). Drain and stop the workers before flipping.');

            return;
        }

        if ($waiting > 0 && ! $this->options->allowPendingJobs) {
            $this->report->block("{$waiting} job(s) are waiting on the `".self::str($driver).'` queue, which cannot be searched for old-root ids. Drain and stop the workers, or pass --allow-pending-jobs.');
        } elseif ($waiting === 0) {
            $this->report->fact('The `'.self::str($driver).'` queue is empty. Keep the workers and the scheduler stopped until the flip has committed.');
        }
    }

    /** Events of the old root that no receiver has been handed yet. */
    private function undeliveredEvents(): void
    {
        if (! $this->schema->hasColumn('events', 'dispatched_at')) {
            return;
        }

        $waiting = DB::table('events')->where('environment_id', $this->rootId)->whereNull('dispatched_at')->whereNull('dead_lettered_at')->count();

        if ($waiting > 0) {
            $this->report->warn("{$waiting} event(s) of the old root have not been dispatched yet and are dropped with it. Let the event relay drain first if a receiver must see them.");
        }
    }

    /** An audit table that refuses DELETE cannot give its old-root rows up. */
    private function auditTables(): void
    {
        if ($this->options->keepRootAudit || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['audit_logs', 'audit_checkpoints'] as $table) {
            $guarded = DB::table('pg_trigger')
                ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
                ->where('pg_class.relname', $table)
                ->where('pg_trigger.tgisinternal', false)
                ->whereRaw('(pg_trigger.tgtype & 8) = 8')
                ->exists();

            if ($guarded) {
                $this->report->block("`{$table}` has a trigger on DELETE (an append-only guard). Pass --keep-root-audit to leave the old root's trail in place (it is archived either way), or drop the trigger for the flip.");
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The flip
    |--------------------------------------------------------------------------
    */

    private function apply(): void
    {
        $this->archiveAudit();
        $this->moveOrganizationsAndPeople();
        $this->mergeMemberships();
        $this->recordIds();
        $this->retireTwinClients();
        $this->retireSignInThrough();
        $this->carryRows();
        $this->repointEverywhere();
        $this->mergeSettings();
        $this->promote();
        $this->removeOldRoot();
        $this->removeOrphans();
        $this->revokeSessionsAndTokens();
        $this->followUps();
    }

    /**
     * The old root's audit trail, kept as it was written.
     *
     * A chain is keyed by (environment, scope) and each entry's hash covers its environment
     * id, scope, organization id and sequence ({@see CboxIdEntryCodec}).
     * Moving an entry into the realm would mean a new environment id, a new organization id
     * and a sequence after the realm chain's head: a different hash for every entry, which
     * is a forged trail, not a migrated one. So each chain is verified where it stands,
     * copied verbatim (hashes and checkpoints included) into `realm_flip_archive`, and
     * removed from the live tables. The realm's own chains are not touched and keep
     * verifying; the console's activity page starts from the flip.
     */
    private function archiveAudit(): void
    {
        $audit = app(AuditLog::class);
        $scopes = DB::table('audit_logs')->where('environment_id', $this->rootId)->distinct()->pluck('scope')->map(static fn (mixed $scope): string => self::str($scope))->all();

        foreach ($scopes as $scope) {
            $organization = $scope === DatabaseAuditLog::SYSTEM_SCOPE ? null : $scope;
            $verified = $this->context->runAs(GenericEnvironment::of($this->rootId), static fn () => $audit->verifyChain($organization));
            $entries = DB::table('audit_logs')->where('environment_id', $this->rootId)->where('scope', $scope)->count();
            $head = DB::table('audit_logs')->where('environment_id', $this->rootId)->where('scope', $scope)->orderByDesc('sequence')->value('hash');

            if ($verified->valid) {
                $this->report->fact("Old-root audit chain `{$scope}`: {$entries} entries verify; head ".self::str($head).'. Archived verbatim.');
            } else {
                $this->report->warn("Old-root audit chain `{$scope}` does NOT verify today (broken at {$verified->brokenAtSequence}: {$verified->reason}). It is archived exactly as it is.");
            }
        }

        foreach (FlipTables::all() as $table => $entry) {
            if ($entry['decision'] !== FlipTables::ARCHIVE || ! $this->schema->has($table)) {
                continue;
            }

            $column = $this->schema->environmentColumn($table);

            if ($column === null) {
                continue;
            }

            DB::table($table)->where($column, $this->rootId)->orderBy($this->schema->primaryKey($table)[0] ?? $column)->chunk(500, function ($rows) use ($table): void {
                $archive = [];

                foreach ($rows as $row) {
                    $row = self::row($row);
                    $archive[] = [
                        'source_table' => $table,
                        'source_key' => isset($row['id']) ? self::str($row['id']) : null,
                        'chain' => isset($row['scope']) ? self::str($row['scope']) : null,
                        'sequence' => isset($row['sequence']) && is_numeric($row['sequence']) ? (int) $row['sequence'] : null,
                        'payload' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'archived_at' => now(),
                    ];
                }

                DB::table('realm_flip_archive')->insert($archive);
                $this->report->count($table, 'archived', count($archive));
            });
        }
    }

    private function moveOrganizationsAndPeople(): void
    {
        foreach ($this->organizations as $old => $new) {
            if ($new !== $old) {
                continue;
            }

            $slug = self::str(DB::table('organizations')->where('id', $old)->value('slug'));
            $taken = DB::table('organizations')->where('environment_id', $this->realmId)->where('slug', $slug)->exists();

            DB::table('organizations')->where('id', $old)->update([
                'environment_id' => $this->realmId,
                'slug' => $taken ? $this->freeSlug($slug, $old) : $slug,
            ]);
            $this->report->count('organizations', 'moved', 1);
        }

        foreach ($this->users as $old => $new) {
            if ($new === $old) {
                DB::table('users')->where('id', $old)->update(['environment_id' => $this->realmId]);
                $this->report->count('users', 'moved', 1);
            }
        }
    }

    /**
     * Root team roles become realm memberships.
     *
     * A person with no membership of the realm organization yet takes their root row with
     * them, id and all, so the environments it was granted still hang off it. A person
     * who is already a member there keeps that row: it takes the root row's environment
     * grants, and the role {@see roleFor()} picks. Only an ACTIVE root membership has a
     * say; a suspended one changes nothing about the realm row.
     */
    private function mergeMemberships(): void
    {
        foreach (DB::table('memberships')->where('environment_id', $this->rootId)->get() as $membership) {
            $id = self::str($membership->id);
            $organization = $this->organizations[self::str($membership->organization_id)] ?? null;
            $user = $this->users[self::str($membership->user_id)] ?? null;

            if ($organization === null || $user === null) {
                $this->memberships[$id] = null;
                $this->report->count('memberships', 'dropped', 1);

                continue;
            }

            $existing = DB::table('memberships')->where('organization_id', $organization)->where('user_id', $user)->where('id', '!=', $id)->first();

            if ($existing === null) {
                $inviter = $membership->invited_by === null ? null : ($this->users[self::str($membership->invited_by)] ?? null);

                DB::table('memberships')->where('id', $id)->update([
                    'environment_id' => $this->realmId,
                    'organization_id' => $organization,
                    'user_id' => $user,
                    'invited_by' => $inviter,
                ]);
                $this->memberships[$id] = $id;
                $this->report->count('memberships', 'moved', 1);

                continue;
            }

            if (self::str($membership->status) === 'active') {
                DB::table('memberships')->where('id', $existing->id)->update([
                    'role' => $this->roleFor(self::row($membership), self::row($existing)),
                    'status' => 'active',
                    'all_environments' => (bool) $membership->all_environments,
                    'updated_at' => now(),
                ]);
            }

            // The grants go across before the root row does: they cascade with it.
            foreach (DB::table('membership_environments')->where('membership_id', $id)->pluck('environment_id') as $environment) {
                if (self::str($membership->status) === 'active') {
                    DB::table('membership_environments')->insertOrIgnore(['membership_id' => $existing->id, 'environment_id' => $environment]);
                }
            }

            DB::table('membership_environments')->where('membership_id', $id)->delete();
            DB::table('memberships')->where('id', $id)->delete();

            $this->memberships[$id] = self::str($existing->id);
            $this->report->count('memberships', 'merged into the realm membership', 1);
        }

        // The realm organization's directory changed: its revision moves past both counters.
        foreach ($this->organizations as $old => $new) {
            if ($new === null || $new === $old || ! $this->schema->has('membership_directory_revisions')) {
                continue;
            }

            $root = DB::table('membership_directory_revisions')->where('organization_id', $old)->value('revision');

            if ($root === null) {
                continue;
            }

            $realm = DB::table('membership_directory_revisions')->where('organization_id', $new)->value('revision');
            $next = max(is_numeric($root) ? (int) $root : 0, is_numeric($realm) ? (int) $realm : 0) + 1;

            DB::table('membership_directory_revisions')->where('organization_id', $old)->delete();
            DB::table('membership_directory_revisions')->updateOrInsert(['organization_id' => $new], ['revision' => $next]);
            $this->report->count('membership_directory_revisions', "set to {$next} on the realm organization", 1);
        }
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $realm
     */
    private function roleFor(array $root, array $realm): string
    {
        $rootRole = MembershipRole::tryFrom(self::str($root['role'] ?? null));
        $realmRole = MembershipRole::tryFrom(self::str($realm['role'] ?? null));

        if ($rootRole === null || $realmRole === null || self::str($root['status'] ?? null) !== 'active') {
            return self::str($realm['role'] ?? null);
        }

        if (self::str($realm['status'] ?? null) !== 'active') {
            return $rootRole->value;
        }

        return match ($this->options->roleConflict) {
            FlipOptions::ROLE_ROOT => $rootRole->value,
            FlipOptions::ROLE_REALM => $realmRole->value,
            default => $rootRole->outranks($realmRole) ? $rootRole->value : $realmRole->value,
        };
    }

    /** The map, written down: what the search for leftovers runs against, and what Billing follows. */
    private function recordIds(): void
    {
        $rows = [['kind' => 'environment', 'old_id' => $this->rootId, 'new_id' => $this->realmId, 'retired' => true, 'note' => null]];

        foreach (['organization' => $this->organizations, 'user' => $this->users, 'membership' => $this->memberships] as $kind => $map) {
            foreach ($map as $old => $new) {
                $rows[] = ['kind' => $kind, 'old_id' => $old, 'new_id' => $new, 'retired' => $new !== $old, 'note' => $new === null ? 'dropped' : ($new === $old ? 'moved' : 'merged')];
            }

            $this->report->ids[$kind] = $map;
        }

        DB::table('realm_flip_ids')->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('realm_flip_ids')->insert($chunk);
        }
    }

    /**
     * A platform-owned client the realm already has under the same name.
     *
     * The Authenticator, the CLI and the native product find their client BY NAME inside an
     * environment, and two of a name make that lookup arbitrary. So where the realm has a
     * first-party, organization-less client of the same name, the realm's is the one that
     * stays and the root's client id cannot be kept. `--carry-client` overrides that for a
     * client id shipped software depends on.
     */
    private function retireTwinClients(): void
    {
        $realm = DB::table('oauth_clients')->where('environment_id', $this->realmId)->whereNull('organization_id')->where('first_party', true)->pluck('client_id', 'name')->all();
        // The native product finds its client through its own registration row, not by name.
        $registered = $this->schema->has('native_product_clients') ? DB::table('native_product_clients')->pluck('client_id')->all() : [];

        foreach (DB::table('oauth_clients')->where('environment_id', $this->rootId)->get() as $client) {
            $clientId = self::str($client->client_id);
            $name = self::str($client->name);
            $platformOwned = $client->organization_id === null;
            $twin = $platformOwned && (bool) $client->first_party && ! in_array($clientId, $registered, true) ? ($realm[$name] ?? null) : null;

            if ($twin !== null && ! in_array($clientId, $this->options->carryClients, true)) {
                $this->dropped['oauth_clients'][] = self::row($client);
                DB::table('oauth_clients')->where('id', $client->id)->delete();
                $this->report->count('oauth_clients', 'not carried (the realm has a client of that name)', 1);
                $this->report->warn("Platform client `{$name}` ({$clientId}) is NOT carried: the realm already has `{$name}` as ".self::str($twin).', and the client is found by name. Software holding the old client id must use '.self::str($twin).' (pass --carry-client='.$clientId.' to keep both).');

                continue;
            }

            if ($platformOwned) {
                $this->carriedClients[] = $clientId;
                $this->report->fact("Platform client `{$name}` keeps its client id {$clientId} and its secrets.");
            }
        }
    }

    /**
     * The connection the old root signed in through.
     *
     * The root's sign-in page was a redirect into the realm ({@see SignInThrough}); the
     * realm IS the root now, so the connection, the setting that names it and the
     * identities it produced have nothing left to do.
     */
    private function retireSignInThrough(): void
    {
        $settings = json_decode(self::str(DB::table('environments')->where('id', $this->rootId)->value('settings')), true);
        $connection = is_array($settings) ? ($settings[SignInThrough::SETTING] ?? null) : null;

        if (! is_string($connection) || $connection === '') {
            return;
        }

        foreach (DB::table('connections')->where('environment_id', $this->rootId)->where('id', $connection)->get() as $row) {
            $this->dropped['connections'][] = self::row($row);
            DB::table('connections')->where('id', $row->id)->delete();
            $this->report->count('connections', 'not carried (the old root\'s sign-in through the realm)', 1);
        }
    }

    /** Every table the list says to carry: its old-root rows, one at a time, into the realm. */
    private function carryRows(): void
    {
        foreach (FlipTables::all() as $table => $entry) {
            if (! $this->schema->has($table) || in_array($table, FlipTables::BY_HAND, true) || ! in_array($entry['decision'], [FlipTables::MOVE, FlipTables::FOLLOW], true)) {
                continue;
            }

            $column = $this->schema->environmentColumn($table);

            if ($column === null) {
                continue;
            }

            foreach (DB::table($table)->where($column, $this->rootId)->get() as $row) {
                $this->carry($table, $column, self::row($row), $entry);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{decision: string, reason: string, refs: array<string, mixed>}  $entry
     */
    private function carry(string $table, string $environmentColumn, array $row, array $entry): void
    {
        $refs = $entry['refs'];
        $changes = [$environmentColumn => $this->realmId];
        $why = null;

        // A row whose parent was not carried goes with it.
        foreach (self::pairs($refs['parent'] ?? []) as [$parentTable, $parentColumn, $childColumn]) {
            foreach ($this->dropped[$parentTable] ?? [] as $parent) {
                if (($row[$childColumn] ?? null) !== null && ($parent[$parentColumn] ?? null) === $row[$childColumn]) {
                    $why = "its {$parentTable} row was not carried";
                }
            }
        }

        // A person's own rows follow the person: carried when the account moved, not when
        // it merged into the account they already have in the realm.
        if ($entry['decision'] === FlipTables::FOLLOW) {
            $owner = self::str($row[self::strings($refs['user'] ?? [])[0] ?? ''] ?? '');

            if (($this->users[$owner] ?? null) !== $owner) {
                $why ??= 'the person keeps their realm account';
            }
        }

        foreach (['organization' => $this->organizations, 'user' => $this->users, 'membership' => $this->memberships] as $kind => $map) {
            foreach (self::strings($refs[$kind] ?? []) as $column) {
                $value = $row[$column] ?? null;

                if (! is_string($value) || ! array_key_exists($value, $map)) {
                    continue;
                }

                if ($map[$value] !== null) {
                    $changes[$column] = $map[$value];
                } elseif ($kind === 'user' && $this->schema->nullable($table, $column)) {
                    $changes[$column] = null;
                } else {
                    $why ??= "its {$kind} was dropped";
                }
            }
        }

        foreach (self::strings($refs['inside'] ?? []) as $column) {
            if (is_string($row[$column] ?? null)) {
                $rewritten = $this->rewrite($row[$column]);

                if ($rewritten !== $row[$column]) {
                    $changes[$column] = $rewritten;
                }
            }
        }

        // One per environment where the organization is null: a unique index does not see
        // two NULLs as equal, so this is checked by hand.
        if ($why === null && isset($refs['one']) && is_array($refs['one']) && ($row['organization_id'] ?? null) === null) {
            $twin = DB::table($table)->where($environmentColumn, $this->realmId);

            if ($this->schema->hasColumn($table, 'organization_id')) {
                $twin = $twin->whereNull('organization_id');
            }

            foreach (self::strings($refs['one']) as $column) {
                $twin = ($row[$column] ?? null) === null ? $twin->whereNull($column) : $twin->where($column, $row[$column]);
            }

            if ($twin->exists()) {
                $why = 'the realm has its own';
            }
        }

        $key = $this->keyOf($table, $row);

        if ($why === null) {
            try {
                DB::transaction(static fn () => DB::table($table)->where($key)->update($changes));
                $this->report->count($table, 'moved', 1);

                return;
            } catch (UniqueConstraintViolationException $conflict) {
                if (($refs['conflict'] ?? null) === 'fail') {
                    throw new RuntimeException("Cannot carry a `{$table}` row into the realm: ".$conflict->getMessage(), previous: $conflict);
                }

                $why = 'the realm has its own';
            }
        }

        $this->dropped[$table][] = $row;
        DB::table($table)->where($key)->delete();
        $this->report->count($table, "not carried ({$why})", 1);
    }

    /**
     * Every column the list says names an organization, a person or a membership, in every
     * row of every environment: a tenant environment's rows name the console's people too.
     */
    private function repointEverywhere(): void
    {
        foreach (FlipTables::all() as $table => $entry) {
            if (! $this->schema->has($table)) {
                continue;
            }

            $refs = $entry['refs'];

            foreach (['organization' => $this->organizations, 'user' => $this->users, 'membership' => $this->memberships] as $kind => $map) {
                foreach (self::strings($refs[$kind] ?? []) as $column) {
                    if (! $this->schema->hasColumn($table, $column)) {
                        continue;
                    }

                    $present = DB::table($table)
                        ->whereIn($column, DB::table('realm_flip_ids')->select('old_id')->where('kind', $kind)->where('retired', true))
                        ->distinct()
                        ->pluck($column)
                        ->map(static fn (mixed $id): string => self::str($id))
                        ->all();

                    foreach ($present as $old) {
                        $this->repoint($table, $column, $kind, $old, $map[$old] ?? null, ($refs['conflict'] ?? null) === 'fail');
                    }
                }
            }

            foreach (self::strings($refs['inside'] ?? []) as $column) {
                if ($this->schema->hasColumn($table, $column)) {
                    $this->rewriteColumn($table, $column);
                }
            }
        }
    }

    private function repoint(string $table, string $column, string $kind, string $old, ?string $new, bool $failOnConflict): void
    {
        if ($new === null) {
            // The rows of the old root go with it anyway; what is left names the dropped id
            // from somewhere else.
            $query = DB::table($table)->where($column, $old);

            if ($kind === 'user' && $this->schema->nullable($table, $column)) {
                $this->report->count($table, "{$column} cleared (person dropped)", $query->update([$column => null]));
            } else {
                $this->report->count($table, "dropped with its {$kind}", $query->delete());
            }

            return;
        }

        try {
            $updated = DB::transaction(static fn (): int => DB::table($table)->where($column, $old)->update([$column => $new]));
            $this->report->count($table, "{$column} re-pointed", $updated);

            return;
        } catch (UniqueConstraintViolationException $conflict) {
            if ($failOnConflict) {
                throw new RuntimeException("Cannot re-point `{$table}.{$column}` from {$old} to {$new}: ".$conflict->getMessage(), previous: $conflict);
            }
        }

        // The realm already has a row for the same thing: row by row, the realm's wins.
        foreach (DB::table($table)->where($column, $old)->get() as $row) {
            $key = $this->keyOf($table, self::row($row));

            try {
                DB::transaction(static fn () => DB::table($table)->where($key)->update([$column => $new]));
                $this->report->count($table, "{$column} re-pointed", 1);
            } catch (UniqueConstraintViolationException) {
                $this->dropped[$table][] = self::row($row);
                DB::table($table)->where($key)->delete();
                $this->report->count($table, 'not carried (the realm has its own)', 1);
            }
        }
    }

    /** Ids inside JSON or text: a list of organization ids, `user:01…`, a settings blob. */
    private function rewriteColumn(string $table, string $column): void
    {
        $key = $this->schema->primaryKey($table);

        if ($key === []) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $rows = DB::select(
            'select '.implode(', ', array_map($grammar->wrap(...), [...$key, $column])).' from '.$grammar->wrapTable($table)
            .' where exists (select 1 from realm_flip_ids where retired = true and cast('.$grammar->wrap("{$table}.{$column}")." as text) like '%' || realm_flip_ids.old_id || '%')",
        );

        foreach ($rows as $row) {
            $row = is_object($row) ? self::row($row) : [];
            $rewritten = $this->rewrite(self::str($row[$column]));

            if ($rewritten !== $row[$column]) {
                DB::table($table)->where(array_intersect_key($row, array_flip($key)))->update([$column => $rewritten]);
                $this->report->count($table, "{$column} rewritten", 1);
            }
        }
    }

    /** Replace every retired id inside a value; a JSON list loses the ids that were dropped. */
    private function rewrite(string $value): string
    {
        $map = [$this->rootId => $this->realmId];
        $gone = [];

        foreach ([$this->organizations, $this->users, $this->memberships] as $ids) {
            foreach ($ids as $old => $new) {
                if ($new === null) {
                    $gone[$old] = true;
                } elseif ($new !== $old) {
                    $map[$old] = $new;
                }
            }
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded) && array_is_list($decoded) && $decoded === array_filter($decoded, 'is_string')) {
            $list = [];

            foreach ($decoded as $item) {
                if (! isset($gone[$item])) {
                    $list[] = $map[$item] ?? $item;
                }
            }

            return json_encode(array_values(array_unique($list)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return strtr($value, $map);
    }

    /**
     * What only the root had, on the environment and the organization rows themselves.
     *
     * The realm's own value wins wherever both have one: the realm's sign-in page is the
     * one people have actually been signing in on (the root's redirected to it), so its
     * branding, locales and policy are the live ones. A key only the root had is copied.
     */
    private function mergeSettings(): void
    {
        $root = json_decode(self::str(DB::table('environments')->where('id', $this->rootId)->value('settings')), true);
        $realm = json_decode(self::str(DB::table('environments')->where('id', $this->realmId)->value('settings')), true);
        $root = is_array($root) ? $root : [];
        $realm = is_array($realm) ? $realm : [];

        foreach ($root as $key => $value) {
            if (in_array($key, FlipTables::ROOT_ONLY_SETTINGS, true)) {
                $this->report->fact("Root setting `{$key}`: not carried (it only made sense on the old root).");
            } elseif (! array_key_exists($key, $realm)) {
                $realm[$key] = $value;
                $this->report->fact("Root setting `{$key}`: copied to the realm.");
            } elseif ($realm[$key] !== $value) {
                $this->report->warn("Root setting `{$key}` differs from the realm's: the realm's is kept.");
            }
        }

        DB::table('environments')->where('id', $this->realmId)->update(['settings' => json_encode($this->rewriteArray($realm), JSON_THROW_ON_ERROR)]);

        foreach ($this->organizations as $old => $new) {
            if ($new === null || $new === $old) {
                continue;
            }

            $from = json_decode(self::str(DB::table('organizations')->where('id', $old)->value('settings')), true);
            $to = json_decode(self::str(DB::table('organizations')->where('id', $new)->value('settings')), true);
            $from = is_array($from) ? $from : [];
            $to = is_array($to) ? $to : [];
            $copied = array_diff_key($from, $to);

            foreach (array_intersect_key($from, $to) as $key => $value) {
                if ($to[$key] !== $value) {
                    $this->report->warn("Organization {$old} → {$new}: setting `{$key}` differs; the realm organization's is kept.");
                }
            }

            if ($copied !== []) {
                DB::table('organizations')->where('id', $new)->update(['settings' => json_encode($this->rewriteArray([...$to, ...$copied]), JSON_THROW_ON_ERROR)]);
                $this->report->fact("Organization {$old} → {$new}: settings copied: ".implode(', ', array_keys($copied)).'.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function rewriteArray(array $value): array
    {
        $decoded = json_decode($this->rewrite(json_encode($value, JSON_THROW_ON_ERROR)), true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * The realm becomes the platform root.
     *
     * A platform root belongs to no project ({@see PlatformRoot}), so
     * `project_id` goes first. The project it was in stays, owned by the surviving
     * organization, with whatever other environments it holds. The grants that let the
     * console's people administer the realm AS A TENANT go too: only operators administer
     * the root.
     */
    private function promote(): void
    {
        $root = DB::table('environments')->where('id', $this->rootId)->first(['domain', 'domain_verified_at']);
        $domain = $this->domain(self::str($root?->domain) ?: null);

        // The old root gives the host up first: `domain` is unique.
        DB::table('environments')->where('id', $this->rootId)->update(['domain' => null]);

        $realm = Environment::query()->findOrFail($this->realmId);
        $realm->forceFill([
            'project_id' => null,
            'domain' => $domain,
            'domain_verified_at' => $domain === null ? null : ($realm->domain === $domain ? $realm->domain_verified_at : now()),
        ])->save();
        $realm->makeDefault();

        $this->report->fact('The realm leaves its project and becomes the default environment; its domain is '.($domain ?? 'none (it answers every unmapped host)').'.');

        $this->report->count('membership_environments', 'removed (grants to the realm as a tenant environment)', DB::table('membership_environments')->where('environment_id', $this->realmId)->delete());
    }

    private function domain(?string $rootDomain): ?string
    {
        $chosen = $this->options->domain;

        if ($chosen !== null) {
            return $chosen === 'none' ? null : strtolower($chosen);
        }

        $login = config('cbox-id.hosts.login');

        if (is_string($login) && $login !== '') {
            return strtolower($login);
        }

        $own = DB::table('environments')->where('id', $this->realmId)->value('domain');

        return is_string($own) && $own !== '' ? $own : $rootDomain;
    }

    /** Everything still standing in the old root, the root itself, and the links. */
    private function removeOldRoot(): void
    {
        foreach ($this->schema->tables() as $table) {
            $column = $this->schema->environmentColumn($table);

            if ($column === null) {
                continue;
            }

            if ($this->options->keepRootAudit && in_array($table, FlipTables::AUDIT_TRAIL, true)) {
                $this->report->count($table, 'left in place (--keep-root-audit)', DB::table($table)->where($column, $this->rootId)->count());

                continue;
            }

            // An organization or a person that merged is not lost: its realm row stands for it.
            $what = in_array($table, ['organizations', 'users'], true) ? 'old-root row removed (merged or dropped, see the id map)' : 'dropped';
            $this->report->count($table, $what, DB::table($table)->where($column, $this->rootId)->delete());
        }

        $this->report->count('organization_links', 'dropped', DB::table('organization_links')->delete());
        DB::table('environments')->where('id', $this->rootId)->delete();
    }

    /** Rows of a table without an environment column whose parent went with the old root. */
    private function removeOrphans(): void
    {
        foreach (FlipTables::ORPHANS as $table => $parents) {
            foreach ($parents as $column => [$parentTable, $parentColumn]) {
                if (! $this->schema->hasColumn($table, $column) || ! $this->schema->hasColumn($parentTable, $parentColumn)) {
                    continue;
                }

                $this->report->count($table, "dropped (its {$parentTable} row is gone)", DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, DB::table($parentTable)->select($parentColumn))
                    ->delete());
            }
        }
    }

    /**
     * Every session and every token, in every environment.
     *
     * The realm's issuer changes host and every tenant environment moves to another
     * domain at the same cutover, so nothing issued before it would be accepted again;
     * revoking makes that a fact of the database rather than of a host name. Sessions and
     * tokens are stamped revoked where the table can say so (the history stays readable)
     * and deleted where a row is only a pending step of a flow. API keys and personal
     * access tokens are credentials a machine holds, not sessions: they are re-pointed
     * ({@see FlipTables}) and keep working.
     */
    private function revokeSessionsAndTokens(): void
    {
        foreach (FlipTables::REVOKE as $table => $how) {
            if (! $this->schema->has($table)) {
                continue;
            }

            if ($how === 'delete') {
                $this->report->count($table, 'revoked (deleted)', DB::table($table)->delete());
            } elseif ($this->schema->hasColumn($table, $how)) {
                $this->report->count($table, 'revoked', DB::table($table)->whereNull($how)->update([$how => now()]));
            }
        }
    }

    /** What other systems must change, because this changed. */
    private function followUps(): void
    {
        foreach ($this->organizations as $old => $new) {
            if ($new !== null && $new !== $old) {
                $this->report->external[] = "Billing: the account keyed {$old} must be re-keyed to {$new} (ProductBilling asks with the organization id and requires the same id back). Until it is, the console's billing and entitlement reads for that organization fail closed.";
            } elseif ($new === null) {
                $this->report->external[] = "Billing: organization {$old} was dropped; close its account if it has one.";
            }
        }

        foreach ($this->users as $old => $new) {
            if ($new !== null && $new !== $old) {
                $this->report->external[] = "Any system that stored the old-root user id {$old} (Engage, Signals, Assistant) now knows that person as {$new}.";
            }
        }

        foreach ($this->configuredIds(config()->all(), '') as $key => $value) {
            $this->report->external[] = "Config `{$key}` names the retired environment ({$value}); point it at {$this->realmId}".(FlipTables::ENV_VARS[$key] ?? null ? ' (env '.FlipTables::ENV_VARS[$key].')' : '').'.';
        }

        foreach (FlipTables::ENV_VARS as $key => $variable) {
            $value = config($key);
            $shown = is_array($value) ? implode(',', array_filter($value, 'is_string')) : (is_scalar($value) ? (string) $value : '');
            $this->report->external[] = "Check {$variable} (config `{$key}`, now `{$shown}`): it names an environment by id or slug; the old root {$this->rootId} no longer exists and the platform root is {$this->realmId}.";
        }

        $this->report->external[] = 'Host env vars: set the login host and the console host for the single realm (CBOX_ID_LOGIN_HOST, CBOX_ID_CONSOLE_HOST, CBOX_ID_ISSUER=https://{login host}), CBOX_ID_WEBAUTHN_ORIGIN to the login origin (keep CBOX_ID_WEBAUTHN_RP_ID so existing passkeys stay valid), and CBOX_ID_CONNECTION_RESOURCE / SIGNALS_BUNDLED_TOKEN_URL, which name the realm\'s old host.';
        $this->report->external[] = 'Every app: CBOX_ID_ISSUER (and management/API URLs, CSP connect-src and form-action) moves to the new issuer; every app session and token is invalid from that change.';
        $this->report->external[] = 'Social sign-in providers of the realm (Google, GitHub, …): add the new login host\'s callback URL at each provider before the issuer moves.';
        $this->report->external[] = 'Caches: run `php artisan cache:clear` (billing snapshots and environment resolution are keyed by ids and hosts that changed). If SESSION_DRIVER is not `database`, flush the session store as well.';
    }

    /**
     * Config values that are, or contain, the retired environment's id.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<string, string>
     */
    private function configuredIds(array $config, string $prefix): array
    {
        $found = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $found = [...$found, ...$this->configuredIds($value, $path)];
            } elseif (is_string($value) && $this->rootId !== '' && str_contains($value, $this->rootId)) {
                $found[$path] = $value;
            }
        }

        return $found;
    }

    /*
    |--------------------------------------------------------------------------
    | Post-conditions
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'root' => $this->rootId,
            'realm' => $this->realmId,
            'counts' => $this->report->after['realm'] ?? [],
            'clients' => $this->carriedClients,
            'operators' => DB::table('platform_operators')->where('status', 'active')->count(),
            'keep_root_audit' => $this->options->keepRootAudit,
            'tables' => array_map(static fn (array $table): array => $table['counts'], array_filter($this->report->tables, static fn (array $table): bool => $table['counts'] !== [])),
            'warnings' => $this->report->warnings,
            'external' => $this->report->external,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function postconditions(FlipReport $report, array $summary, bool $fresh): void
    {
        $defaults = DB::table('environments')->where('is_default', true)->get(['id', 'project_id', 'status']);
        $default = $defaults->first();

        $report->check('exactly one default environment', $defaults->count() === 1, $defaults->count().' found');
        $report->check('the default environment is the realm', self::str($default?->id) === $this->realmId, self::str($default?->id));
        $report->check('the default environment belongs to no project', $default !== null && $default->project_id === null);
        $report->check('the old root environment is gone', DB::table('environments')->where('id', $this->rootId)->doesntExist());
        $report->check('organization_links is empty', ! $this->schema->has('organization_links') || DB::table('organization_links')->doesntExist());

        $keepAudit = (bool) ($summary['keep_root_audit'] ?? false);
        $leftovers = [];

        foreach ($this->schema->tables() as $table) {
            $column = $this->schema->environmentColumn($table);

            if ($column === null || ($keepAudit && in_array($table, FlipTables::AUDIT_TRAIL, true))) {
                continue;
            }

            $rows = DB::table($table)->where($column, $this->rootId)->count();

            if ($rows > 0) {
                $leftovers[] = "{$table} ({$rows})";
            }
        }

        $report->check('no row belongs to the old root environment', $leftovers === [], implode(', ', $leftovers));

        [$references, $history] = $this->references($keepAudit);
        $report->check('no row names a retired id', $references === [], implode(', ', $references));

        if ($history !== []) {
            $report->fact('History that still names a retired id and is deliberately not rewritten: '.implode(', ', $history).'.');
        }

        $planned = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];

        // Later, people sign up and organizations are made: only what existed at the flip is
        // held against the plan.
        $executedAt = $fresh ? null : ($summary['executed_at'] ?? null);

        foreach (['organizations', 'users'] as $table) {
            $query = DB::table($table)->where('environment_id', $this->realmId);
            $now = is_string($executedAt) ? $query->where('created_at', '<=', $executedAt)->count() : $query->count();
            $was = $planned[$table] ?? null;
            $report->check("{$table} in the realm as planned", ! is_int($was) || $was === $now, is_int($was) ? "planned {$was}, found {$now}".(is_string($executedAt) ? ' from before the flip' : '') : '');
        }

        $ownerless = DB::table('projects')
            ->whereNotIn('organization_id', DB::table('organizations')->select('id')->where('environment_id', $this->realmId))
            ->pluck('id')->all();
        $report->check('every project is owned by an organization of the realm', $ownerless === [], implode(', ', array_map(self::str(...), $ownerless)));

        $homeless = DB::table('environments')->where('is_default', false)
            ->where(static function (Builder $query): void {
                $query->whereNull('project_id')->orWhereNotIn('project_id', DB::table('projects')->select('id'));
            })->pluck('slug')->all();
        $report->check('every other environment belongs to a project', $homeless === [], implode(', ', array_map(self::str(...), $homeless)));

        $operators = DB::table('platform_operators')->where('status', 'active')->get(['email', 'subject_id']);
        $unlinked = $operators->filter(fn (object $operator): bool => $operator->subject_id !== null
            && DB::table('users')->where('id', $operator->subject_id)->where('environment_id', $this->realmId)->doesntExist())->pluck('email')->all();
        $report->check('an active platform operator exists', $operators->isNotEmpty());
        $report->check('every operator is a person of the realm', $unlinked === [], implode(', ', array_map(self::str(...), $unlinked)));

        $clients = array_values(array_filter(is_array($summary['clients'] ?? null) ? $summary['clients'] : [], 'is_string'));
        $present = DB::table('oauth_clients')->where('environment_id', $this->realmId)->whereIn('client_id', $clients)->pluck('client_id')->all();
        $missing = array_diff($clients, array_map(self::str(...), $present));
        $report->check('the platform clients are in the realm', $missing === [], implode(', ', $missing));

        if ($this->schema->has('native_product_clients')) {
            $stray = DB::table('native_product_clients')->where('environment_id', '!=', $this->realmId)->count();
            $report->check('the native product registration points at the realm', $stray === 0, "{$stray} elsewhere");
        }

        $live = DB::table('auth_sessions')->whereNull('revoked_at')->count()
            + DB::table('oauth_refresh_tokens')->whereNull('revoked_at')->count()
            + DB::table('oauth_access_tokens')->whereNull('revoked_at')->count();

        // Only the flip itself can promise this: people sign in again the minute it is done.
        if ($fresh) {
            $report->check('no live session or token', $live === 0, "{$live} live");
        }

        $this->verifyRealmChains($report);
    }

    /** The realm's own audit chains were not touched and must still verify. */
    private function verifyRealmChains(FlipReport $report): void
    {
        $audit = app(AuditLog::class);
        $broken = [];
        $scopes = DB::table('audit_logs')->where('environment_id', $this->realmId)->distinct()->pluck('scope')->all();

        foreach ($scopes as $scope) {
            $scope = self::str($scope);
            $result = $this->context->runAs(GenericEnvironment::of($this->realmId), static fn () => $audit->verifyChain($scope === DatabaseAuditLog::SYSTEM_SCOPE ? null : $scope));

            if (! $result->valid) {
                $broken[] = "{$scope} at {$result->brokenAtSequence}";
            }
        }

        $report->check('the realm\'s audit chains verify ('.count($scopes).')', $broken === [], implode(', ', $broken));
    }

    /**
     * Search every text column of every table for a retired id.
     *
     * @return array{0: list<string>, 1: list<string>} [what must not be there, tolerated history]
     */
    private function references(bool $keepAudit): array
    {
        $found = [];
        $history = [];
        $grammar = DB::getQueryGrammar();

        if (DB::table('realm_flip_ids')->where('retired', true)->doesntExist()) {
            return [[], []];
        }

        foreach ($this->schema->tables() as $table) {
            if (in_array($table, FlipTables::UNSEARCHED, true) || str_starts_with($table, 'realm_flip')) {
                continue;
            }

            $columns = $this->schema->searchable($table);
            $selects = [];

            foreach ($columns['exact'] as $column) {
                $selects[] = 'sum(case when '.$grammar->wrap($column).' in (select old_id from realm_flip_ids where retired = true) then 1 else 0 end) as '.$grammar->wrap($column);
            }

            foreach ($columns['inside'] as $column) {
                $selects[] = 'sum(case when exists (select 1 from realm_flip_ids where retired = true and cast('.$grammar->wrap("{$table}.{$column}")." as text) like '%' || realm_flip_ids.old_id || '%') then 1 else 0 end) as ".$grammar->wrap($column);
            }

            if ($selects === []) {
                continue;
            }

            $counted = DB::selectOne('select '.implode(', ', $selects).' from '.$grammar->wrapTable($table));
            $row = is_object($counted) ? self::row($counted) : [];
            $tolerated = in_array($table, FlipTables::HISTORY, true) || ($keepAudit && in_array($table, FlipTables::AUDIT_TRAIL, true));

            foreach ($row as $column => $rows) {
                if (is_numeric($rows) && (int) $rows > 0) {
                    if ($tolerated) {
                        $history[] = "{$table}.{$column} ({$rows})";
                    } else {
                        $found[] = "{$table}.{$column} ({$rows})";
                    }
                }
            }
        }

        return [$found, $history];
    }

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    */

    /** @return array<string, array<string, int>> */
    private function inventory(): array
    {
        $inventory = [];

        foreach (['root' => $this->rootId, 'realm' => $this->realmId] as $label => $environment) {
            foreach (['organizations', 'users', 'memberships', 'oauth_clients', 'connections', 'invitations', 'roles', 'webauthn_credentials', 'environment_api_keys', 'audit_logs'] as $table) {
                $inventory[$label][$table] = DB::table($table)->where('environment_id', $environment)->count();
            }
        }

        $inventory['deployment'] = [
            'environments' => DB::table('environments')->count(),
            'default environments' => DB::table('environments')->where('is_default', true)->count(),
            'projects' => DB::table('projects')->count(),
            'projects owned by a realm organization' => DB::table('projects')->whereIn('organization_id', DB::table('organizations')->select('id')->where('environment_id', $this->realmId))->count(),
            'organization api keys' => DB::table('organization_api_keys')->count(),
            'organization links' => DB::table('organization_links')->count(),
            'active operators' => DB::table('platform_operators')->where('status', 'active')->count(),
            'live sessions' => DB::table('auth_sessions')->whereNull('revoked_at')->count(),
            'live refresh tokens' => DB::table('oauth_refresh_tokens')->whereNull('revoked_at')->count(),
            'archived audit rows' => DB::table('realm_flip_archive')->count(),
        ];

        return $inventory;
    }

    /*
    |--------------------------------------------------------------------------
    | After the commit
    |--------------------------------------------------------------------------
    */

    private function forget(): void
    {
        app(OrganizationLinks::class)->flush();
        // Which environment is the root is memoised for the request; it just changed.
        app()->forgetInstance(PlatformRoot::class);

        $cache = app(EnvironmentResolutionCache::class);

        foreach (array_filter([$this->rootId, $this->realmId]) as $environment) {
            $cache->forgetEnvironment($environment);
        }
    }

    /** The flip, on the new root's own trail: the first entry of the single realm. */
    private function recordInTrail(): void
    {
        $this->context->runAs(GenericEnvironment::of($this->realmId), fn () => app(AuditLog::class)->record(new AuditEvent(
            action: 'realm.flipped',
            actorType: ActorType::System,
            actorId: 'cbox-id:realm:flip',
            targetType: 'environment',
            targetId: $this->realmId,
            context: [
                'retired_environment' => $this->rootId,
                'organizations' => count($this->organizations),
                'people' => count($this->users),
                'archived_audit_rows' => DB::table('realm_flip_archive')->count(),
            ],
        )));
    }

    /*
    |--------------------------------------------------------------------------
    | Small things
    |--------------------------------------------------------------------------
    */

    /** A slug for an organization moved into the realm where its own is taken: stable across runs. */
    private function freeSlug(string $slug, string $organizationId): string
    {
        return $slug.'-'.substr(strtolower($organizationId), -6);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function keyOf(string $table, array $row): array
    {
        $key = $this->schema->primaryKey($table);

        if ($key === []) {
            throw new RuntimeException("Table `{$table}` has no primary key: its rows cannot be carried one at a time. Give it a decision that does not need one.");
        }

        return array_intersect_key($row, array_flip($key));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function pairs(mixed $value): array
    {
        $pairs = [];

        foreach (is_array($value) ? $value : [] as $pair) {
            if (is_array($pair) && isset($pair[0], $pair[1], $pair[2]) && is_string($pair[0]) && is_string($pair[1]) && is_string($pair[2])) {
                $pairs[] = [$pair[0], $pair[1], $pair[2]];
            }
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    private static function row(object $row): array
    {
        $values = [];

        foreach (get_object_vars($row) as $name => $value) {
            $values[(string) $name] = $value;
        }

        return $values;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
