<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\EnvironmentWorkspace;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Exceptions\InvalidCustomDomain;

/**
 * Ask to serve this environment on a custom domain. Nothing is served there yet: the answer
 * is a DNS TXT record to publish, and {@see VerifyDomain} promotes the domain once it is
 * visible. Asking again replaces the pending domain; the verified one keeps serving.
 *
 * Whether a string is a domain this deployment may serve — not an IP, not one of the
 * platform's own base domains, not another environment's — is the framework's question,
 * and its refusal names what is wrong.
 */
#[AsAction(
    name: 'domains.add',
    summary: 'Start serving this environment on a custom domain: returns the DNS TXT record that proves you control it.',
    scope: 'domains:write',
    danger: Danger::Write,
    schema: 'CustomDomain',
    tag: 'Domains',
    rest: ['POST', '/domains'],
)]
final readonly class AddDomain implements Action
{
    public function __construct(
        private EnvironmentWorkspace $workspace,
        private EnvironmentDomains $domains,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('domain')->required()->max(253)->describe('The domain, for example login.example.com.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $environment = $this->workspace->environment() ?? throw ActionRefused::notFound('environment');

        try {
            $this->domains->request($environment->id, $context->string('domain'));
        } catch (InvalidCustomDomain $e) {
            throw ActionRefused::because('invalid_domain', $e->getMessage(), 'domain');
        }

        $fresh = $environment->fresh() ?? $environment;

        return ActionResult::item($fresh, DomainFields::present($fresh, $this->domains));
    }
}
