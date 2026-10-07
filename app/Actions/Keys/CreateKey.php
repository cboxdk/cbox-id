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
use Carbon\CarbonImmutable;

/**
 * Mint a management key for this environment. From another key, the new key is never wider
 * than its parent: at most its scopes, expiring no later than it and within the configured
 * maximum ({@see ManagementKeys}). The value is in `token`, once.
 */
#[AsAction(
    name: 'keys.create',
    summary: 'Mint a management key for this environment, at most as wide as the caller. The value is returned once, as `token`.',
    scope: 'keys:write',
    danger: Danger::Critical,
    tag: 'Management keys',
    rest: ['POST', '/keys'],
    status: 201,
    consoleRoutes: ['environment.keys.store'],
    redact: ['token'],
)]
final readonly class CreateKey implements Action
{
    public function __construct(private ManagementKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120),
            Field::list('scopes', Field::string('scope')->max(64))->required()->min(1)->max(64)->describe('The scopes the key carries. From a key: a subset of its own.'),
            Field::string('expires_at')->nullable()->format('date-time')->describe('When it stops working. From a key: required to be no later than the parent; defaults to the latest allowed.'),
            Field::string('description')->nullable()->max(500)->describe('What it is for — shown beside it in the console.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $expires = $context->nullableString('expires_at');

        $issued = $this->keys->mint(
            $context->principal,
            KeyFields::environmentId($context->principal),
            trim($context->string('name')),
            array_values(array_filter($context->array('scopes'), 'is_string')),
            $expires === null ? null : CarbonImmutable::parse($expires),
            $context->nullableString('description'),
        );

        return ActionResult::item($issued, ManagementKeyResource::from($issued->key, $issued->plaintext));
    }
}
