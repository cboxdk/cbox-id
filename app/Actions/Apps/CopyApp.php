<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Apps\CopyTargets;
use App\Platform\Console\AppHeader;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Cbox\Id\OAuthServer\ValueObjects\RegisteredClient;

/**
 * Register this app again in ANOTHER environment of the same project, from its
 * {@see ClientBlueprint}: a new client id there, and — for an app that holds one — a new
 * secret, in the answer ONCE. What travels is the configuration; the identity and the
 * credentials never do.
 *
 * The target is an authorization question, answered by {@see CopyTargets}: an environment
 * of THIS project, one the person acting may administer, and one that is serving. That is
 * a question about a PERSON — which environments of the workspace they may reach — so only
 * a person on the environment console can copy. A management key belongs to the one
 * environment it was minted in, and writing into another is authority it was never given:
 * it exports the blueprint here and registers it with the other environment's own key
 * (`apps.blueprint`, then `apps.create` there), and is refused here
 * (`environment_not_reachable`) with that said.
 *
 * WHO IS ACTING is read before the environment moves: inside the target, the console
 * session anchored to this environment resolves to nobody — by design — and the copy would
 * be recorded as the system's.
 */
#[AsAction(
    name: 'apps.copy',
    summary: 'Copy an app into another environment of this project, with a new client id and secret there. A person on the environment console only; a key exports the blueprint instead.',
    scope: 'apps:write',
    danger: Danger::Critical,
    schema: 'App',
    tag: 'Applications',
    rest: ['POST', '/apps/{id}/copy'],
    status: 201,
    consoleRoutes: ['environment.clients.copy'],
    redact: ['client_secret'],
)]
final readonly class CopyApp implements Action
{
    public const string UNREACHABLE = 'Choose one of the environments offered. It has to be in this project, and one you administer.';

    public function __construct(
        private ClientRegistry $clients,
        private CopyTargets $targets,
        private EnvironmentContext $environments,
        private IssuerResolver $issuers,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::string('environment_id')->required()->max(64)->describe('The environment to copy into: another environment of this project.'),
            Field::string('name')->required()->min(1)->max(190)->describe('What to call the copy there.'),
            AppFields::uris('redirect_uris')->describe('Its redirect URIs there — staging\'s callback is rarely production\'s. Left out, none.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));

        if (! $context->principal instanceof ConsoleSessionPrincipal) {
            throw ActionRefused::because(
                'environment_not_reachable',
                'A management key acts in its own environment only. Export this app\'s blueprint (GET /apps/{id}/blueprint) and register it with the other environment\'s key (POST /apps with `blueprint`).',
                'environment_id',
            );
        }

        $refusal = AppHeader::copyRefusal($client);

        if ($refusal !== null) {
            throw ActionRefused::because('not_copyable', $refusal);
        }

        $target = $this->targets->find($context->string('environment_id'))
            ?? throw ActionRefused::because('environment_not_reachable', self::UNREACHABLE, 'environment_id');

        $blueprint = $this->clients->blueprint($client)
            ->withName(trim($context->string('name')))
            ->withRedirectUris(array_values(array_filter(
                $context->array('redirect_uris'),
                static fn (mixed $uri): bool => is_string($uri) && trim($uri) !== '',
            )));

        $actor = $context->actor();

        try {
            $copied = $this->environments->runAs($target, fn (): RegisteredClient => $this->clients->import($blueprint, null, null, $actor));
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused);
        } catch (ScopeNotGrantable $refused) {
            throw ActionRefused::because('scope_not_grantable', $refused->getMessage());
        }

        return ActionResult::item($copied, [
            ...AppResource::from($copied->client, $copied->secret),
            'environment' => ['id' => $target->id, 'name' => $target->name],
            'issuer' => rtrim($this->issuers->forEnvironment($target->id), '/'),
        ]);
    }
}
