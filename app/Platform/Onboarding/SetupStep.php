<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Platform\Help\HelpTopic;

/**
 * One step of the setup checklist, as measured for a specific organization: the
 * step itself, plus whether that organization has done it.
 */
final readonly class SetupStep
{
    public function __construct(
        public SetupStepKey $key,
        public bool $done,
    ) {}

    public function title(): string
    {
        return $this->key->title();
    }

    public function description(): string
    {
        return $this->key->description();
    }

    public function route(): string
    {
        return $this->key->route();
    }

    public function actionLabel(): string
    {
        return $this->key->actionLabel();
    }

    public function helpTopic(): HelpTopic
    {
        return $this->key->helpTopic();
    }
}
