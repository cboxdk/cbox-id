<?php

declare(strict_types=1);

namespace App\Actions\Apis;

use App\Http\Resources\Environment\ApiResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\Apis;

#[AsAction(
    name: 'apis.get',
    summary: 'Get one registered API and its scopes.',
    scope: 'apis:read',
    danger: Danger::Read,
    rest: ['GET', '/apis/{id}'],
)]
final readonly class ShowApi implements Action
{
    public function __construct(private Apis $apis) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The API id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $api = $this->apis->find($context->string('id')) ?? throw ActionRefused::notFound('API');

        return ActionResult::item($api, ApiResource::from($api));
    }
}
