<?php

declare(strict_types=1);

namespace App\Actions\Platform\Workspaces;

use App\Actions\Platform\AsOperator;
use App\Mail\PasswordResetMail;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Locale\MailLocale;
use App\Platform\MailLinks;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Platform\Exceptions\EnvironmentLimitReached;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stand up a whole customer workspace — the organization, its owner, its first IdP product
 * and that product's first environment — and send the owner a link to choose a password.
 *
 * `TenantProvisioner::provision()` is the package's own entry point and does all of it in
 * one transaction. NO PASSWORD IS TAKEN: the blueprint requires a credential, so it gets 64
 * random characters nobody ever reads, and the owner is emailed a reset link. An operator
 * who typed a password for somebody else would be an operator who knows a customer's
 * credential — and over an API, one that sat in a request log too.
 *
 * The mail goes AFTER the transaction commits: a reset link for a workspace that rolled
 * back is a link to nowhere. Whether it could be sent is `owner_invited` — false only when
 * the owner has no account to reset, which provisioning has just made impossible.
 */
#[AsAction(
    name: 'platform.workspaces.create',
    summary: 'Create a workspace with its owner, first project and first environment; the owner is emailed a link to set their password.',
    scope: 'operator:workspaces:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['POST', '/workspaces'],
    status: 201,
    consoleRoutes: ['platform.workspaces.store'],
    consoleGate: ConsoleGate::Operator,
    schema: 'ProvisionedWorkspace',
    tag: 'Workspaces',
)]
final readonly class CreateWorkspace implements Action
{
    /** The plan allowances an operator may hand out. */
    public const array LIMITS = [1, 2, 3, 5, 10, 25];

    public function __construct(
        private TenantProvisioner $provisioner,
        private PasswordReset $resets,
        private MailLinks $links,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->min(2)->max(120)->describe('The workspace\'s name — usually the company it belongs to.'),
            Field::string('owner_email')->required()->format('email')->max(255)->describe('The owner. An address that already has an account keeps it; the workspace is added to it.'),
            Field::string('owner_name')->required()->min(2)->max(120),
            Field::integer('environment_limit')->required()->min(1)->max(25)->describe('The first project\'s environment allowance: 1, 2, 3, 5, 10 or 25.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        AsOperator::id($context->principal);

        $limit = (int) $context->string('environment_limit');

        if (! in_array($limit, self::LIMITS, true)) {
            throw ActionRefused::because('invalid_limit', 'Choose an environment allowance of 1, 2, 3, 5, 10 or 25.', 'environment_limit');
        }

        $ownerEmail = mb_strtolower(trim($context->string('owner_email')));

        try {
            $tenant = $this->provisioner->provision(new TenantBlueprint(
                organizationName: trim($context->string('name')),
                ownerEmail: $ownerEmail,
                ownerName: trim($context->string('owner_name')),
                // Discarded on the next line — see the class note.
                ownerPassword: Str::random(64),
                environmentLimit: $limit,
            ));
        } catch (EnvironmentLimitReached|InvalidArgumentException $e) {
            // A domain already in use, or a deployment with no platform root ("run the
            // installer"): both sentences somebody can act on.
            throw ActionRefused::because('not_provisioned', $e->getMessage(), 'name');
        }

        // The owner is a subject of the PLATFORM ROOT, wherever the operator's console is
        // pointed, so the reset is asked for there.
        $token = $this->platformRoot->run(fn (): ?string => $this->resets->request($ownerEmail));

        if ($token !== null) {
            $link = $this->links->route('password.reset', $token);
            $locale = app(MailLocale::class)->forRecipient();

            DB::afterCommit(static fn () => Mail::to($ownerEmail)->locale($locale)->send(new PasswordResetMail($link)));
        }

        return ActionResult::item($tenant, [
            'id' => $tenant->organization->id,
            'name' => $tenant->organization->name,
            'owner' => ['id' => $tenant->owner->id, 'email' => $ownerEmail],
            'project_id' => $tenant->project->id,
            'environment_id' => $tenant->environment->id,
            'owner_invited' => $token !== null,
        ]);
    }
}
