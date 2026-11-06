<?php

declare(strict_types=1);

namespace App\SignalsData;

use App\Platform\Radar\RadarPseudonyms;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;

final readonly class SourceErasureStep implements ErasureStep
{
    public function __construct(private SourceLedger $ledger, private RadarPseudonyms $pseudonyms) {}

    public function name(): string
    {
        return 'signals.source';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        $subjects = [$request->subjectId];
        if ($request->email !== null) {
            $subjects[] = 'target:'.$this->pseudonyms->subject($request->email);
        }

        return ErasureStepResult::of($this->name(), ['records' => $this->ledger->erase($subjects, $request->subjectId)]);
    }
}
