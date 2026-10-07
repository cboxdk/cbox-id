<?php

declare(strict_types=1);

namespace App\Actions\FrontendKeys;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\Exceptions\UnusableOrigin;

/**
 * Create a publishable key for a browser-side app, allowed from exactly the origins given.
 *
 * Environment-owned — publishable keys have no organization column — so the console offers
 * this on the environment console only. A Write rather than Critical: the value is public by
 * design and works only from the origins listed, which the caller states here. A list with
 * an unusable origin is refused whole: a silently shortened allow-list is a key that stops
 * working somewhere nobody looked.
 */
#[AsAction(
    name: 'frontend_keys.create',
    summary: 'Create a publishable frontend key for a browser app, usable only from the origins listed. The key is public by design.',
    scope: 'frontend_keys:write',
    danger: Danger::Write,
    schema: 'FrontendKey',
    tag: 'Frontend keys',
    rest: ['POST', '/frontend-keys'],
    status: 201,
    consoleRoutes: ['environment.keys.frontend.store'],
)]
final readonly class CreateFrontendKey implements Action
{
    public function __construct(private PublishableKeys $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120)->describe('What the key is for, so a person recognises it later.'),
            Field::string('mode')->required()->oneOf(array_map(static fn (KeyMode $mode): string => $mode->value, KeyMode::cases()))->describe('`test` or `live`; it is in the key\'s prefix.'),
            FrontendKeyFields::origins()->required(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $key = $this->keys->issue(
                trim($context->string('name')),
                KeyMode::from($context->string('mode')),
                self::strings($context->array('origins')),
            );
        } catch (UnusableOrigin $e) {
            throw ActionRefused::because('unusable_origin', $e->getMessage(), 'origins');
        }

        return ActionResult::item($key, FrontendKeyFields::present($key), 201);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function strings(array $values): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $values),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
