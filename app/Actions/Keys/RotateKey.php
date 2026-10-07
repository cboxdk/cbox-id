<?php

declare(strict_types=1);

namespace App\Actions\Keys;

use App\Http\Resources\Environment\ManagementKeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Keys\ManagementKeys;

/**
 * Replace a key with a successor carrying the same name, scopes and policy, and keep the
 * old one working for `grace_hours` (default 24) so whatever uses it can switch without an
 * outage. The successor's value is in `token`, once.
 */
#[AsAction(
    name: 'keys.rotate',
    summary: 'Rotate a management key: mint a successor with the same scopes and retire the old one after a grace period.',
    scope: 'keys:write',
    danger: Danger::Critical,
    tag: 'Management keys',
    rest: ['POST', '/keys/{id}/rotate'],
    status: 201,
    redact: ['token'],
)]
final readonly class RotateKey implements Action
{
    public function __construct(private ManagementKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The key to rotate.'),
            Field::integer('grace_hours')->min(0)->max(168)->describe('How long the old key keeps working. Default 24.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $old = KeyFields::find($context->string('id'));
        $grace = $context->has('grace_hours') ? (int) $context->string('grace_hours') : 24;

        $issued = $this->keys->rotate($context->principal, $old, $grace);

        return ActionResult::item($issued, ManagementKeyResource::from($issued->key, $issued->plaintext));
    }
}
