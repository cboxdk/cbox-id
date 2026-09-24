<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\EnvironmentKeyScopes;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;

/**
 * One environment API scope as a person reads it: what it lets a key do, in words, with
 * the key it is known by in the API reference beside it.
 *
 * The page drew raw keys — `organizations:write` — although the framework has always
 * carried a label for every scope. The key alone is what the API reference speaks; the
 * label alone would leave a reader unable to match a 403 to the box they did not tick.
 */
final readonly class EnvironmentScopeProps implements Prop
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $writes,
    ) {}

    public static function from(EnvironmentApiScope $scope): self
    {
        return new self($scope->value, self::label($scope), EnvironmentKeyScopes::writes($scope));
    }

    /**
     * The framework's label, except where the console names the thing differently.
     *
     * The keys people create for an app's API are "API keys" to the person holding one and
     * "Member API keys" to the organization and the environment that see them all. The
     * framework calls them customer API keys; a box on this page saying so would be the one
     * place in the console using a third name for them.
     */
    private static function label(EnvironmentApiScope $scope): string
    {
        return match ($scope) {
            EnvironmentApiScope::ApiKeysRead => 'Read member API keys',
            EnvironmentApiScope::ApiKeysWrite => 'Revoke member API keys',
            default => $scope->label(),
        };
    }

    /**
     * A scope as stored on a key — which may be one this release no longer knows, and is
     * then shown by its key rather than dropped: a credential's grants are exactly the
     * thing a list must not hide.
     */
    public static function stored(string $value): self
    {
        $scope = EnvironmentApiScope::tryFrom($value);

        return $scope !== null
            ? self::from($scope)
            : new self($value, $value, ! str_ends_with($value, ':read'));
    }

    /**
     * @return array{value: string, label: string, writes: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'writes' => $this->writes,
        ];
    }
}
