<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Onboarding\IntegrationProgress;

#[AsAction(name: 'onboarding.get', summary: 'Read current environment onboarding evidence. Does not certify application security or production readiness.', scope: 'signin:read', danger: Danger::Read, schema: 'OnboardingProgress', tag: 'Onboarding', rest: ['GET', '/onboarding'])]
final readonly class ShowOnboarding implements Action
{
    public function __construct(private IntegrationProgress $progress) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        return ActionResult::item(null, $this->progress->read());
    }
}
