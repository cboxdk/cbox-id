<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Invitations\ValueObjects\PendingInvitationSummary;

/**
 * One row of "invited, not joined yet", as every invite surface draws it — with the two
 * things you can do about it. Either URL is null where the viewer may not do it.
 *
 * Timestamps travel as ISO 8601 and are made relative in the browser: "expires in 3 days"
 * computed on the server is wrong the moment the page sits open, and these pages sit open.
 */
final readonly class PendingInvitationProps implements Prop
{
    public function __construct(
        public string $id,
        public string $email,
        public string $roleLabel,
        public string $expiresAt,
        public ?string $invitedAt,
        public ?string $inviterName,
        public ?string $appName,
        public ?string $resendUrl,
        public ?string $revokeUrl,
    ) {}

    public static function from(PendingInvitationSummary $summary, ?string $resendUrl, ?string $revokeUrl): self
    {
        return new self(
            id: $summary->id,
            email: $summary->email,
            roleLabel: $summary->role->label(),
            expiresAt: $summary->expiresAt->toIso8601String(),
            invitedAt: $summary->invitedAt?->toIso8601String(),
            inviterName: $summary->inviterName,
            appName: $summary->appName,
            resendUrl: $resendUrl,
            revokeUrl: $revokeUrl,
        );
    }

    /**
     * @return array{id: string, email: string, roleLabel: string, expiresAt: string, invitedAt: string|null, inviterName: string|null, appName: string|null, resendUrl: string|null, revokeUrl: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'roleLabel' => $this->roleLabel,
            'expiresAt' => $this->expiresAt,
            'invitedAt' => $this->invitedAt,
            'inviterName' => $this->inviterName,
            'appName' => $this->appName,
            'resendUrl' => $this->resendUrl,
            'revokeUrl' => $this->revokeUrl,
        ];
    }
}
