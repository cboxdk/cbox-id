<?php

declare(strict_types=1);

namespace App\SignalsData\Bundled;

use App\SignalsData\Models\SourceHead;
use App\SignalsData\SourceEnvironments;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;
use Cbox\Signals\Contracts\ManagedSources;
use Cbox\Signals\Data\IssuedSecret;
use Cbox\Signals\Data\ManagedEnvironment;
use DateTimeImmutable;

/**
 * Cbox ID's half of the bundled Signals integration. The environments it
 * offers are the ones whose Signals source feed is live (the capture
 * allowlist plus any environment that already has a source head); the owner
 * is the organization that holds the environment's project in the platform
 * root; the secret is an environment API key scoped to the export only.
 */
final readonly class IdManagedSources implements ManagedSources
{
    public const string KEY_NAME = 'Cbox Signals (bundled)';

    public const string SCOPE = 'signals_source:read';

    public function __construct(private EnvironmentApiKeys $keys, private IssuerResolver $issuers) {}

    public function provider(): string
    {
        return 'cbox_id';
    }

    public function environments(): iterable
    {
        $retention = config('signals-data.retention_days');
        $importFrom = now()->subDays(is_int($retention) && $retention > 0 ? $retention : 7)->toImmutable();
        foreach (Environment::query()->whereIn('id', $this->known())->where('status', EnvironmentStatus::Active)->orderBy('id')->get() as $environment) {
            $owner = $this->owner($environment);
            if ($owner === null) {
                continue;
            }
            yield new ManagedEnvironment($environment->id, $owner, rtrim($this->issuers->forEnvironment($environment->id), '/'), DateTimeImmutable::createFromInterface($importFrom));
        }
    }

    public function retired(): iterable
    {
        $live = Environment::query()->whereIn('id', $this->known())->where('status', EnvironmentStatus::Active)->pluck('id')->all();
        foreach (SourceHead::query()->orderBy('environment_id')->pluck('environment_id') as $id) {
            if (is_string($id) && ! in_array($id, $live, true)) {
                yield $id;
            }
        }
    }

    public function issueSecret(ManagedEnvironment $environment): IssuedSecret
    {
        $issued = $this->keys->issue($environment->ownerId, self::KEY_NAME, [self::SCOPE], null, new KeyProvenance(createdByType: 'system', description: 'Issued by the bundled Cbox Signals integration; rotated automatically.'));

        return new IssuedSecret($issued->plaintext, $issued->key->id);
    }

    public function retireSecrets(ManagedEnvironment $environment, string $keepReference): void
    {
        foreach ($this->keys->forEnvironment($environment->ownerId) as $key) {
            if ($key->name === self::KEY_NAME && $key->id !== $keepReference && $key->revoked_at === null) {
                $this->keys->revoke($environment->ownerId, $key->id);
            }
        }
    }

    public function discardSecret(ManagedEnvironment $environment, string $reference): void
    {
        $this->keys->revoke($environment->ownerId, $reference);
    }

    /** @return list<string> */
    private function known(): array
    {
        $configured = SourceEnvironments::listed();
        $heads = array_values(array_filter(SourceHead::query()->pluck('environment_id')->all(), is_string(...)));

        return array_values(array_unique([...$configured, ...$heads]));
    }

    private function owner(Environment $environment): ?string
    {
        $projectId = $environment->getAttribute('project_id');
        if (! is_string($projectId) || $projectId === '') {
            return null;
        }
        $project = Project::query()->find($projectId);
        $organization = $project?->getAttribute('organization_id');

        return $project !== null && $project->isActive() && is_string($organization) && $organization !== '' ? $organization : null;
    }
}
