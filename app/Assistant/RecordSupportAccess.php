<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Platform\OrganizationActivity;
use Cbox\AiAssistant\Connector\Events\SupportAccessUsed;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Request;

/**
 * Writes a Cbox support elevation into the workspace's own audit trail, beside what
 * Assistant logs on its side: which staff member, why, until when, and through which
 * surface and keys.
 *
 * Only into a workspace the principal is currently an active member of. An account id is a
 * claim; an elevation naming a workspace the customer does not belong to served nothing, and
 * must not be able to append to that workspace's chain.
 */
final readonly class RecordSupportAccess
{
    public const string ACTION = 'organization.support_access_used';

    public function __construct(private OrganizationActivity $activity, private PlatformRoot $root, private Memberships $memberships) {}

    public function handle(SupportAccessUsed $event): void
    {
        $account = $event->accountId;
        $subject = $event->subject;
        if ($account === null || $subject === null || $this->root->run(fn () => $this->memberships->activeRole($account, $subject)) === null) {
            return;
        }
        $request = app()->bound('request') ? app('request') : null;

        $this->activity->record(
            $account,
            self::ACTION,
            $event->access->staffId,
            targetType: 'user',
            targetId: $subject,
            context: [
                'staff_name' => $event->access->staffName,
                'reason' => $event->access->reason,
                'until' => $event->access->until->format(DATE_ATOM),
                'surface' => $event->surface,
                'keys' => $event->keys,
            ],
            request: $request instanceof Request ? $request : null,
            actorType: ActorType::Operator,
        );
    }
}
