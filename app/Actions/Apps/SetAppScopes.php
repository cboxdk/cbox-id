<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\ScopeCatalog;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\ValueObjects\ScopeHolder;
use Illuminate\Validation\ValidationException;

/**
 * Make an app's scopes exactly this set: the CEILING of what it may ask for, applied from
 * the next token. A device or agent request naming a scope removed here is refused outright
 * rather than downscoped, so this is a live change to what an integration can ask for.
 *
 * A REGISTERED API'S SCOPES ARE ITS OWNER'S TO HAND OUT: the framework refuses, on save, a
 * scope of an API this app's owner may not hold, and the refusal names the API and what to
 * do about it. The platform scopes only the console grants
 * ({@see ScopeCatalog::RESERVED_FOR_CONSOLE}) are refused to every other caller when they
 * are being GIVEN — an app an administrator already granted one keeps it through an edit
 * that leaves it in place.
 */
#[AsAction(
    name: 'apps.scopes.set',
    summary: 'Replace an app\'s complete scope set — the ceiling of what it may request, applied from its next token.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Apps',
    rest: ['PUT', '/apps/{id}/scopes'],
    consoleRoutes: ['clients.scopes.update', 'environment.clients.scopes.update'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppScopes implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            // Not `required()`: Laravel's `required` refuses an empty list, and an empty list
            // is a scope set — the one that removes them all. Its absence is refused below.
            Field::list('scopes', Field::string('scope')->max(128))->max(200)->describe('Required. The COMPLETE scope set afterwards; an empty list removes every scope.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        if (! $context->has('scopes')) {
            throw ValidationException::withMessages(['scopes' => 'The scopes field is required.']);
        }

        $client = AppFields::find($context, $context->string('id'));

        $next = array_values(array_unique(array_values(array_filter(
            $context->array('scopes'),
            static fn (mixed $scope): bool => is_string($scope) && trim($scope) !== '',
        ))));

        AppFields::refuseReserved($context, array_values(array_diff($next, $client->scopes)));

        try {
            $updated = $this->clients->update($client, $this->clients->blueprint($client)->withScopes($next), $context->actor());
        } catch (ScopeNotGrantable $refused) {
            throw AppFields::notGrantable($refused, ScopeHolder::of($client));
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'scopes');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
