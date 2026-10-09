<?php

declare(strict_types=1);

namespace App\Actions\Fga;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;

/**
 * This environment's authorization schema: the source as it was written (comments and
 * all), the parsed types and relations, how many times it has been replaced, and the
 * revision the model is at. `defined` is false until a schema is first saved.
 */
#[AsAction(
    name: 'fga.schema.get',
    summary: 'Read this environment\'s fine-grained authorization schema: the source text, its parsed types and relations, its version, and the current consistency token.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaSchema',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/schema'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ShowFgaSchema implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $state = $this->fga->schema();

        return ActionResult::item($state, FgaFields::presentSchema($state));
    }
}
