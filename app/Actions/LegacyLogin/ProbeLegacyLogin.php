<?php

declare(strict_types=1);

namespace App\Actions\LegacyLogin;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Migration\LegacyLoginApprovals;
use App\Platform\Migration\LegacyLoginProbe;

/**
 * Ask the declared endpoint whether it is alive, before anybody approves it.
 *
 * Without this an approval is blind, and the first thing to discover that the endpoint does
 * not answer is a real person's sign-in — failing closed. It changes nothing here, but it is
 * not a read: it calls somebody else's system with an address, so it needs the write scope.
 *
 * IT SENDS NO PASSWORD ({@see LegacyLoginProbe}): it asks "do you know this address" and
 * nothing more. The address should be the caller's own — the answer is about an account at
 * another system — and the result is a sentence returned to the caller, never stored.
 */
#[AsAction(
    name: 'legacy_login.probe',
    summary: 'Ask the declared legacy login endpoint whether it knows an address — your own — to test it before approving. Sends no password.',
    scope: 'signin:write',
    danger: Danger::Write,
    tag: 'Legacy login',
    rest: ['POST', '/legacy-login/probe'],
    consoleRoutes: ['environment.legacy-login.probe'],
    schema: 'LegacyLoginProbe',
)]
final readonly class ProbeLegacyLogin implements Action
{
    public function __construct(
        private LegacyLoginApprovals $approvals,
        private LegacyLoginProbe $probe,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('email')->required()->max(254)->format('email')->describe('An address that exists in your old system — your own.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $email = trim($context->string('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ActionRefused::because('invalid_email', 'Enter an address that exists in your old system.', 'email');
        }

        $declaration = $this->approvals->current()
            ?? throw ActionRefused::because('no_declaration', 'No app has declared an endpoint to probe.', 'email');

        $result = $this->probe->describe($declaration, $email);

        return ActionResult::item($result, ['result' => $result]);
    }
}
