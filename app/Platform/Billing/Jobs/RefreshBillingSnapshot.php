<?php

declare(strict_types=1);

namespace App\Platform\Billing\Jobs;

use App\Platform\Billing\BillingSnapshots;
use App\Platform\Billing\ProductBilling;
use App\Platform\ServiceAccess\ProductAdmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Asks Billing again about one answer whose snapshot went stale, so the page that noticed did
 * not wait ({@see BillingSnapshots}). Unique per answer while queued: a burst of page views
 * queues one question. When Billing is away the kept answer stays and the next stale page
 * asks again.
 */
final class RefreshBillingSnapshot implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string ADMISSION = 'admission';

    public const string FEATURES = 'features';

    public const string POLICY = 'policy';

    public int $tries = 1;

    public int $uniqueFor = 60;

    /** @param  list<string>  $parts */
    public function __construct(public readonly string $kind, public readonly array $parts = []) {}

    public static function admission(string $subject, string $organization): void
    {
        self::dispatch(self::ADMISSION, [$subject, $organization]);
    }

    public static function features(string $workspace): void
    {
        self::dispatch(self::FEATURES, [$workspace]);
    }

    public static function policy(): void
    {
        self::dispatch(self::POLICY);
    }

    public function uniqueId(): string
    {
        return BillingSnapshots::key($this->kind, $this->parts);
    }

    public function handle(ProductAdmission $admission, ProductBilling $billing): void
    {
        try {
            match ($this->kind) {
                self::ADMISSION => $admission->refresh($this->parts[0] ?? '', $this->parts[1] ?? ''),
                self::FEATURES => $billing->refreshFeatures($this->parts[0] ?? ''),
                self::POLICY => $admission->refreshPolicy(),
                default => null,
            };
        } catch (Throwable $error) {
            report($error);
        }
    }
}
