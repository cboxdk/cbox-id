<?php

declare(strict_types=1);

namespace App\Platform\Erasure;

use App\Models\Radar\RadarDevice;
use App\Platform\Radar\RadarPseudonyms;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;

/**
 * The devices Radar remembers a person signing in from, and where — deleted.
 *
 * Every row is keyed by pseudonyms only, but a pseudonym of their address under this
 * environment is still them, and the coarse location of their last sign-in on each browser
 * is the kind of thing an erasure request is about. The decision rows are handled by
 * {@see RiskDecisionsErasureStep}, which also unlinks the device pseudonym from them.
 *
 * NOT covered, deliberately: allow- and deny-list entries. They are values an administrator
 * typed — an address on the deny list is the environment's own fraud-prevention record, kept
 * under that legitimate interest — and they are listed and removed by hand
 * (`radar.lists.remove`). docs/guides/radar.md says so.
 */
final readonly class RadarErasureStep implements ErasureStep
{
    public function __construct(private RadarPseudonyms $pseudonyms) {}

    public function name(): string
    {
        return 'app.radar_devices';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        if ($request->email === null || trim($request->email) === '') {
            return ErasureStepResult::of($this->name(), ['radar_devices' => 0]);
        }

        $deleted = RadarDevice::query()
            ->where('subject_hash', $this->pseudonyms->subject($request->email))
            ->delete();

        return ErasureStepResult::of($this->name(), ['radar_devices' => is_int($deleted) ? $deleted : 0]);
    }
}
