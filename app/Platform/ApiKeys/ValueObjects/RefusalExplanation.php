<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys\ValueObjects;

use App\Platform\Actions\ActionMessage;
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
    public string $message;

    public function __construct(
        public string $field,
        public ActionMessage $sourceMessage,
    ) {
        $this->message = $sourceMessage->render('en');
    }

    public static function of(CustomerApiKeyRefused $refused, ?string $appName): self
    {
        $app = $appName ?? new ActionMessage('console.projection_9b1a53644f87');

        return match ($refused->reason) {
            ApiKeyRefusal::UnknownClient,
            ApiKeyRefusal::KeysNotEnabled => new self('client_id', new ActionMessage('console.projection_f4c1563cb200', ['app' => $app])),
            ApiKeyRefusal::NotAMember => new self('organization_id', new ActionMessage('console.projection_a463253e1e77')),
            ApiKeyRefusal::OrganizationInactive => new self('organization_id', new ActionMessage('console.projection_dce3f8eae16c')),
            ApiKeyRefusal::HolderInactive => new self('organization_id', new ActionMessage('console.projection_aada5bb0564f')),
            ApiKeyRefusal::PermissionNotHeld => new self('permissions', self::notHeld($refused->permissions, $app)),
            ApiKeyRefusal::ExpiryInPast => new self('expiresOn', new ActionMessage('console.projection_c0b95f451e84')),
            ApiKeyRefusal::InvalidInput => new self('name', new ActionMessage('console.projection_511bfa171b72')),
        };
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function notHeld(array $permissions, string|ActionMessage $app): ActionMessage
    {
        $list = implode(', ', $permissions);

        return count($permissions) === 1
            ? new ActionMessage('console.projection_13814f592d62', ['permissions' => $list, 'app' => $app])
            : new ActionMessage('console.projection_f6177d13293c', ['permissions' => $list, 'app' => $app]);
    }
}
