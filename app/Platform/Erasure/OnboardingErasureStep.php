<?php

declare(strict_types=1);

namespace App\Platform\Erasure;

use App\Models\OnboardingDismissal;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;

/**
 * Which organizations' setup checklists the person put away. Small, but it is a row per
 * organization they administered, under their id — the shape of who they worked for.
 */
final class OnboardingErasureStep implements ErasureStep
{
    public function name(): string
    {
        return 'app.onboarding';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        $deleted = OnboardingDismissal::query()->where('subject_id', $request->subjectId)->toBase()->delete();

        return ErasureStepResult::of($this->name(), ['checklist_dismissals' => $deleted]);
    }
}
