<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Cbox\Id\OAuthServer\Models\ApiScope;

/**
 * One scope an API owns, on the API's own page — with where to change it and remove it.
 *
 * `tenantRequestable` is "organizations' apps may request this": on an API the environment
 * owns, whether an app an organization registered may be given the scope. On an API an
 * organization owns it decides nothing — only that organization's apps may hold its
 * scopes — and the page says so rather than drawing a switch that does nothing.
 */
final readonly class ApiScopeProps implements Prop
{
    public function __construct(
        public string $id,
        public string $key,
        public ?string $description,
        public bool $tenantRequestable,
        public string $updateHref,
        public string $destroyHref,
    ) {}

    public static function of(ApiScope $scope, string $updateHref, string $destroyHref): self
    {
        return new self($scope->id, $scope->key, $scope->description, $scope->tenant_requestable, $updateHref, $destroyHref);
    }

    /**
     * @return array{id: string, key: string, description: string|null, tenantRequestable: bool, updateHref: string, destroyHref: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'description' => $this->description,
            'tenantRequestable' => $this->tenantRequestable,
            'updateHref' => $this->updateHref,
            'destroyHref' => $this->destroyHref,
        ];
    }
}
