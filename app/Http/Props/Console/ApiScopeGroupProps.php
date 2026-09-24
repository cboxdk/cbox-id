<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;

/**
 * A registered API and the scopes of it ONE app may hold — a group in the app's scope
 * picker, headed by the API's name and the audience its tokens will carry.
 *
 * Only the holdable scopes are in it. An API whose scopes this app may not hold is not a
 * group at all, so an organization's administrator is never shown another organization's
 * API, or a scope the environment kept for its own apps.
 */
final readonly class ApiScopeGroupProps implements Prop
{
    /**
     * @param  list<ApiScopeOptionProps>  $scopes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $identifier,
        public string $owner,
        public array $scopes,
    ) {}

    /**
     * @return array{id: string, name: string, identifier: string, owner: string, scopes: list<array{key: string, description: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'identifier' => $this->identifier,
            'owner' => $this->owner,
            'scopes' => array_map(static fn (ApiScopeOptionProps $scope): array => $scope->toArray(), $this->scopes),
        ];
    }
}
