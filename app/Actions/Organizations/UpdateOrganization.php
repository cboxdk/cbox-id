<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Exceptions\SlugAlreadyTaken;
use Cbox\Id\Organization\ValueObjects\OrganizationChanges;
use Illuminate\Validation\ValidationException;

/**
 * Rename an organization, change its slug, and/or replace its metadata.
 *
 * Name and slug go through {@see Organizations::update()}, which announces
 * `organization.updated` and records WHICH fields changed; the values before and after go on
 * the trail here, as `organization.renamed`, so "what was it called before" has an answer
 * whichever door changed it. Metadata goes through the settings writer, which announces and
 * records its own change. Only what was sent changes, and a change that alters nothing
 * records nothing.
 *
 * The consoles' "rename this organization" (Settings, on both planes) is this action with
 * the organization they are administering named explicitly; on an organization's own
 * console that can only ever be the person's own ({@see OrganizationFields::find()}).
 */
#[AsAction(
    name: 'organizations.update',
    summary: 'Rename an organization, change its slug, or replace its metadata. Only what is sent changes.',
    scope: 'organizations:write',
    danger: Danger::Write,
    schema: 'Organization',
    tag: 'Organizations',
    rest: ['PATCH', '/organizations/{id}'],
    consoleRoutes: ['environment.organizations.update', 'settings.rename'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateOrganization implements Action
{
    public function __construct(
        private Organizations $organizations,
        private AuditLog $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('name')->min(1)->max(190),
            Field::string('slug')->min(1)->max(190)->describe('Letters, digits, dashes and underscores.'),
            Field::object('metadata', [])->nullable()->describe('Replaces the metadata as a whole; null or {} clears it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('id'));

        $slug = $context->has('slug') ? $context->string('slug') : null;

        if ($slug !== null && preg_match('/^[\pL\pM\pN_-]+$/u', $slug) !== 1) {
            throw ValidationException::withMessages(['slug' => 'The slug may only contain letters, numbers, dashes and underscores.']);
        }

        $changes = new OrganizationChanges(
            name: $context->has('name') ? trim($context->string('name')) : null,
            slug: $slug,
        );

        $actor = $context->actor();
        $before = ['name' => $organization->name, 'slug' => $organization->slug];

        if (! $changes->isEmpty()) {
            try {
                $organization = $this->organizations->update($organization->id, $changes, $actor->id);
            } catch (SlugAlreadyTaken) {
                throw ActionRefused::because('slug_taken', 'That slug is already in use in this environment.', 'slug');
            }

            $nameChanged = $organization->name !== $before['name'];
            $slugChanged = $organization->slug !== $before['slug'];

            if ($nameChanged || $slugChanged) {
                $this->audit->record(new AuditEvent(
                    action: 'organization.renamed',
                    actorType: $actor->type,
                    actorId: $actor->id,
                    organizationId: $organization->id,
                    targetType: 'organization',
                    targetId: $organization->id,
                    context: ['from' => $before['name'], 'to' => $organization->name] + ($slugChanged
                        ? ['slug_from' => $before['slug'], 'slug_to' => $organization->slug]
                        : []),
                ));
            }
        }

        if ($context->has('metadata')) {
            $metadata = OrganizationFields::metadata($context->array('metadata'));

            if ($metadata !== OrganizationFields::metadataOf($organization)) {
                $organization = $this->organizations->updateSettings($organization->id, ['metadata' => $metadata]);
            }
        }

        return ActionResult::item($organization, OrganizationFields::present($organization));
    }
}
