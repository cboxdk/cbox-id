<?php

declare(strict_types=1);

namespace App\Actions\Keys;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Keys\ManagementKeys;

/**
 * Revoke a key, and every key it minted, immediately.
 */
#[AsAction(
    name: 'keys.revoke',
    summary: 'Revoke a management key and every key it minted, immediately.',
    scope: 'keys:write',
    danger: Danger::Destructive,
    tag: 'Secret keys',
    rest: ['DELETE', '/keys/{id}'],
    status: 204,
    consoleRoutes: ['environment.keys.destroy'],
)]
final readonly class RevokeKey implements Action
{
    public function __construct(private ManagementKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The key to revoke.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $key = KeyFields::find($context->string('id'));

        return ActionResult::none($this->keys->revoke($context->principal, $key));
    }
}
