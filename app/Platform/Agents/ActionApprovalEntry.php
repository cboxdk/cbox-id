<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use App\Platform\Actions\Approvals\ActionApprovalRequest;
use Carbon\CarbonImmutable;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;

/**
 * One action approval with both of its halves joined: what was asked for (the app's row)
 * and where it stands (the framework's CIBA request, in the platform root).
 */
final readonly class ActionApprovalEntry
{
    public function __construct(
        public ActionApprovalRequest $request,
        /** The platform-root subject the approval was raised for. */
        public string $approverId,
        public ActionApprovalStatus $status,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $decidedAt,
    ) {}

    public function pending(): bool
    {
        return $this->status === ActionApprovalStatus::Pending;
    }
}
