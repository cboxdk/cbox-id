<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Principal\OperatorPrincipal;

/** An operator's delegated token: the deployment, within the `operator:*` scopes given. */
final readonly class FakeOperatorToken extends FakePersonToken implements OperatorPrincipal
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        private string $operatorId,
        string $subjectId,
        array $scopes,
    ) {
        parent::__construct($subjectId, $scopes);
    }

    public function operatorId(): string
    {
        return $this->operatorId;
    }

    protected function planes(): array
    {
        return [ActionPlane::Platform, ActionPlane::Account];
    }
}
