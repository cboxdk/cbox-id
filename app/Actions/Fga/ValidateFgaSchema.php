<?php

declare(strict_types=1);

namespace App\Actions\Fga;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;
use Cbox\Id\Kernel\Authorization\Exceptions\InvalidSchema;
use Cbox\Id\Kernel\Authorization\Schema\SchemaParser;

/**
 * Check a schema without saving it: every problem, by line, or the parsed types and the
 * canonical text. Answers 200 either way — an invalid schema is this action's answer, not
 * its refusal — so an editor (the console's, an agent drafting one) can ask as it goes.
 *
 * A read, so the schema travels in the query string: over MCP that is no constraint at
 * all, over REST it is the URL limit of whatever sits in front of the app (commonly 8 KB).
 * A larger schema is checked by `fga.schema.update` itself, which refuses an invalid one
 * before any approval is asked for.
 *
 * It does not check the environment's tuples against it; `fga.schema.update` does, and
 * refuses with the ones that would no longer fit.
 */
#[AsAction(
    name: 'fga.schema.validate',
    summary: 'Check a fine-grained authorization schema without saving it: every error by line, or the parsed types and canonical text.',
    scope: 'fga:read',
    danger: Danger::Read,
    schema: 'FgaSchemaValidation',
    tag: FgaFields::TAG,
    rest: ['GET', '/fga/schema/validate'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ValidateFgaSchema implements Action
{
    public function __construct(private FineGrainedAuthorization $fga) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('schema')->required()->max(SchemaParser::MAX_LENGTH)->describe('The schema to check, in the schema language.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $schema = $this->fga->validateSchema($context->string('schema'));
        } catch (InvalidSchema $invalid) {
            return ActionResult::item($invalid, FgaFields::presentValidation(null, $invalid->errors));
        }

        return ActionResult::item($schema, FgaFields::presentValidation($schema, []));
    }
}
