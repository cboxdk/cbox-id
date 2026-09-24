<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys\ValueObjects;

use Cbox\Id\Organization\Enums\ApiKeyRefusal;
use Cbox\Id\Organization\Exceptions\CustomerApiKeyRefused;

/**
 * Why a key was not created, in a sentence the person can act on, and the form field it
 * belongs beside.
 *
 * The framework's messages name ids and are written for a log. A refusal is a management
 * answer the person is owed — the framework reports its reason for exactly that — so each
 * reason gets its own sentence here, and the permission refusal names the permissions:
 * "you no longer hold returns:file" is something to take to an administrator, "refused" is
 * not.
 */
final readonly class RefusalExplanation
{
    public function __construct(
        public string $field,
        public string $message,
    ) {}

    public static function of(CustomerApiKeyRefused $refused, ?string $appName): self
    {
        $app = $appName ?? 'That app';

        return match ($refused->reason) {
            ApiKeyRefusal::UnknownClient,
            ApiKeyRefusal::KeysNotEnabled => new self('client_id', "{$app} does not offer API keys to this organization."),
            ApiKeyRefusal::NotAMember => new self('organization_id', 'You are not an active member of that organization.'),
            ApiKeyRefusal::OrganizationInactive => new self('organization_id', 'That organization is suspended, so no new keys can be created in it.'),
            ApiKeyRefusal::HolderInactive => new self('organization_id', 'Your account is not active, so it cannot hold a key.'),
            ApiKeyRefusal::PermissionNotHeld => new self('permissions', self::notHeld($refused->permissions, $app)),
            ApiKeyRefusal::ExpiryInPast => new self('expiresOn', 'Choose a date after today.'),
            ApiKeyRefusal::InvalidInput => new self('name', 'A key name is at most 255 characters.'),
        };
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function notHeld(array $permissions, string $app): string
    {
        $list = implode(', ', $permissions);

        return count($permissions) === 1
            ? "You do not hold {$list} in {$app}, so a key cannot carry it. A key can only do what you can do yourself."
            : "You do not hold {$list} in {$app}, so a key cannot carry them. A key can only do what you can do yourself.";
    }
}
