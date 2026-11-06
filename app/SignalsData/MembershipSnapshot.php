<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\Kernel\Tenancy\Scopes\TenantScope;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Observe current members without inventing historical membership intervals. */
final readonly class MembershipSnapshot
{
    public function __construct(private SourceLedger $ledger, private SourceRelationships $relationships) {}

    /** @return array{scanned: int, next: ?string, has_more: bool} */
    public function page(?string $after, int $limit = 500): array
    {
        $environment = $this->ledger->environment();
        if ($environment === null || $limit < 1 || $limit > 1000) {
            throw ValidationException::withMessages(['snapshot' => 'An enabled owner environment and a limit from 1 to 1000 are required.']);
        }
        // This operator scan explicitly crosses organization scopes, never environment
        // scopes. Live capture must be deployed and enabled before starting the scan.
        $query = Membership::query()->withoutGlobalScope(TenantScope::class)->where('environment_id', $environment)
            ->where('status', MembershipStatus::Active->value);
        $candidates = (clone $query)->when($after !== null, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')->limit($limit)->get(['id']);
        foreach ($candidates as $candidate) {
            DB::transaction(function () use ($query, $candidate): void {
                // Same owner-row lock as remove/leave: deletion cannot commit between
                // observing a member and committing its assertion. Re-read after locking.
                $member = (clone $query)->where('id', $candidate->id)->lockForUpdate()->first();
                if ($member !== null) {
                    $this->relationships->membership($member->id, $member->user_id, $member->organization_id, true);
                }
            });
        }
        $next = $candidates->last()->id ?? $after;

        return ['scanned' => $candidates->count(), 'next' => $next,
            'has_more' => $next !== null && (clone $query)->where('id', '>', $next)->exists()];
    }
}
