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
use App\Platform\Actions\Preflight;
use App\Platform\Fga\FgaTrail;
use Cbox\Id\Kernel\Authorization\Contracts\FineGrainedAuthorization;
use Cbox\Id\Kernel\Authorization\Schema\SchemaParser;

/**
 * Replace this environment's authorization schema.
 *
 * CRITICAL: the schema decides every check — one changed line can hand every member of a
 * group edit rights on every document, or take them from everybody. So it is refused,
 * with every problem by line, unless it holds together (`invalid_schema`), and refused
 * while tuples exist that it would no longer allow (`schema_conflict`, naming them):
 * a schema change never deletes access silently. An invalid schema is refused before any
 * approval is asked for ({@see self::preflight()}); `fga.schema.validate` checks one
 * without saving.
 */
#[AsAction(
    name: 'fga.schema.update',
    summary: 'Replace this environment\'s fine-grained authorization schema (types, relations and how each is decided). Changes the answer to every check at once; refused if invalid or if existing tuples would no longer fit.',
    scope: 'fga:schema',
    danger: Danger::Critical,
    schema: 'FgaSchema',
    tag: FgaFields::TAG,
    rest: ['PUT', '/fga/schema'],
    consoleRoutes: ['environment.fga.schema.update'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class UpdateFgaSchema implements Action, Preflight
{
    public function __construct(
        private FineGrainedAuthorization $fga,
        private FgaTrail $trail,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('schema')->required()->max(SchemaParser::MAX_LENGTH)->describe('The schema, in the schema language: `type document` then `  relation viewer: [user, group#member] or editor or viewer from parent`.'),
        ]);
    }

    /**
     * An invalid schema is refused before anybody is asked to approve it: an agent drafting
     * one hears every problem at once, and nobody's phone buzzes for text that could never
     * be saved. Whether existing tuples would be stranded is decided when it runs, against
     * the tuples as they are then.
     */
    public function preflight(ActionContext $context): void
    {
        FgaFields::refusing(fn () => $this->fga->validateSchema($context->string('schema')));
    }

    public function handle(ActionContext $context): ActionResult
    {
        $state = FgaFields::refusing(fn () => $this->fga->updateSchema($context->string('schema')));

        $this->trail->record(FgaTrail::SCHEMA_UPDATED, $context->actor(), [
            'version' => $state->version,
            'revision' => $state->consistency->revision,
            'types' => array_keys($state->schema->types ?? []),
        ]);

        return ActionResult::item($state, FgaFields::presentSchema($state));
    }
}
