<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use App\Platform\Actions\ActionRefused;
use Illuminate\Support\Facades\Validator;

/** The deployment's exact old identity and verified copy-manifest checksum, never caller-selected grants. */
final readonly class SitesAssetsTransitionPlan
{
    public const string SERVICE = 'cbox_assets';

    public const string AUDIENCE = 'https://api.cboxassets.com';

    public const array OLD_SCOPES = ['assets:consumer:read', 'assets:consumer:write'];

    public const array NEW_SCOPES = ['organization:read', 'assets:read', 'assets:write', 'assets:webhooks:write'];

    public function __construct(
        public string $environmentId,
        public string $organizationId,
        public string $connectionId,
        public string $consumerClientId,
        public string $providerClientId,
        public string $oldServiceClientId,
        public string $oldServiceAccountId,
        public string $manifestDigest,
        public string $expiresAt,
    ) {}

    public static function configured(): self
    {
        $manifest = config('service-connections.sites_assets_transition');
        $rules = [
            'environment_id' => ['required', 'ulid'],
            'organization_id' => ['required', 'ulid'],
            'connection_id' => ['required', 'ulid'],
            'consumer_client_id' => ['required', 'max:191'],
            'provider_client_id' => ['required', 'max:191'],
            'old_service_client_id' => ['required', 'max:191'],
            'old_service_account_id' => ['required', 'ulid'],
            'manifest_digest' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
            'expires_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z', 'after:now'],
        ];
        if (! is_array($manifest) || count($manifest) !== count($rules)) {
            throw self::unavailable();
        }
        $strings = [];
        foreach ($rules as $key => $rule) {
            $value = $manifest[$key] ?? null;
            if (! is_string($value)) {
                throw self::unavailable();
            }
            $strings[$key] = $value;
        }
        if (Validator::make($strings, $rules)->fails()) {
            throw self::unavailable();
        }

        return new self($strings['environment_id'], $strings['organization_id'], $strings['connection_id'],
            $strings['consumer_client_id'], $strings['provider_client_id'], $strings['old_service_client_id'],
            $strings['old_service_account_id'], $strings['manifest_digest'], $strings['expires_at']);
    }

    /** @return array<string, string|list<string>> */
    public function toArray(): array
    {
        return [
            'environment_id' => $this->environmentId, 'organization_id' => $this->organizationId,
            'connection_id' => $this->connectionId, 'consumer_client_id' => $this->consumerClientId,
            'provider_client_id' => $this->providerClientId, 'old_service_client_id' => $this->oldServiceClientId,
            'old_service_account_id' => $this->oldServiceAccountId, 'manifest_digest' => $this->manifestDigest,
            'expires_at' => $this->expiresAt, 'service' => self::SERVICE, 'audience' => self::AUDIENCE,
            'old_scopes' => self::OLD_SCOPES, 'new_scopes' => self::NEW_SCOPES,
        ];
    }

    public function digest(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    private static function unavailable(): ActionRefused
    {
        return new ActionRefused('transition_unavailable', 'Deploy an exact, unexpired Sites transition manifest before requesting approval.', 503);
    }
}
