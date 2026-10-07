<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Apps\AppScopes;
use App\Platform\Console\ConsoleClients;
use App\Platform\Console\ConsolePlane;
use App\Platform\ScopeCatalog;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Exceptions\ScopeNotGrantable;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ScopeHolder;
use Cbox\Id\Organization\Models\Organization;

/**
 * What every app action asks the same way: which app an id names for THIS principal, whose
 * an app may be, which scopes a machine may not hand out, and how the registry's refusals
 * read. A helper, not an action.
 *
 * WHICH APP. A management key holds the environment's authority, so it reaches every app in
 * the environment — by row id or by `client_id`, the one an app's backend has to hand — and
 * the model's environment scope is the boundary. A person on the console reaches what
 * {@see ConsoleClients} says they may CHANGE, and nothing more: the console resolves the app
 * before it runs the action, and the action asks again rather than trust that it did, so an
 * organization's administrator never reaches another organization's app through it however
 * the action was called.
 */
final class AppFields
{
    public static function id(): Field
    {
        return Field::string('id')->inPath()->describe('The app\'s id, or its `client_id`.');
    }

    /**
     * @param  string  $name  The input name: `redirect_uris`, `post_logout_redirect_uris`.
     */
    public static function uris(string $name): Field
    {
        return Field::list($name, Field::string('uri')->max(2048))->max(50);
    }

    /**
     * The app $id names, within what this principal may change — or a 404.
     *
     * @throws ActionRefused
     */
    public static function find(ActionContext $context, string $id): Client
    {
        $principal = $context->principal;

        if ($principal instanceof ConsoleSessionPrincipal) {
            return (new ConsoleClients($principal->scope()))->manageable($id);
        }

        return Client::query()
            ->where(static fn ($query) => $query->whereKey($id)->orWhere('client_id', $id))
            ->first() ?? throw ActionRefused::notFound('app');
    }

    /**
     * Refuse an owner this principal may not register an app for, or one that does not
     * exist here.
     *
     * A person on an organization's console registers for that organization only; an app the
     * ENVIRONMENT owns is the platform's own — marked first-party it skips every
     * organization's consent screen — and only the environment's console mints one. The
     * console refuses that before it gets here; this is the same rule, asked again.
     *
     * @throws ActionRefused
     */
    public static function assertMayOwn(ActionContext $context, ?string $organizationId): void
    {
        $principal = $context->principal;

        if ($principal instanceof ConsoleSessionPrincipal && $principal->scope()->plane() === ConsolePlane::Organization) {
            abort_unless($organizationId !== null && $organizationId === $principal->scope()->organizationId(), 403);
        }

        if ($organizationId !== null && Organization::query()->whereKey($organizationId)->doesntExist()) {
            throw ActionRefused::because('organization_not_found', 'No organization with that organization_id exists in this environment.', 'organization_id');
        }
    }

    /**
     * Refuse the platform scopes only the console grants ({@see ScopeCatalog::RESERVED_FOR_CONSOLE})
     * when anybody but a person on the console is GIVING them to an app.
     *
     * Each is authority over data a management key was never given — every stored secret,
     * every person's permissions — so a key that may only manage apps must not mint itself
     * that authority by putting one on an app and asking for a token. Deny by default: any
     * principal that is not a console session is refused, so the next door (MCP, the CLI)
     * starts refused too. `$given` is what is being ADDED; a scope an administrator already
     * granted in the console is not given again by an edit that keeps it.
     *
     * @param  list<string>  $given
     *
     * @throws ActionRefused
     */
    public static function refuseReserved(ActionContext $context, array $given, ?string $field = 'scopes'): void
    {
        if ($context->principal instanceof ConsoleSessionPrincipal) {
            return;
        }

        $reserved = ScopeCatalog::reservedAmong($given);

        if ($reserved !== []) {
            throw ActionRefused::because(
                'scope_not_grantable',
                'A management key cannot give an app '.implode(', ', $reserved).'. Grant it in the console, on the app\'s Scopes page.',
                $field,
            );
        }
    }

    /**
     * A registered API's scope this app may not hold, said with the API it belongs to and
     * what to do about it ({@see AppScopes::explain()}) — the same sentence on every door.
     */
    public static function notGrantable(ScopeNotGrantable $refused, ScopeHolder $holder, string $field = 'scopes'): ActionRefused
    {
        return ActionRefused::because('scope_not_grantable', app(AppScopes::class)->explain($refused, $holder), $field);
    }

    /** The registry's refusal, under its own code (`invalid_client_metadata`, `invalid_redirect_uri`). */
    public static function invalid(InvalidClientMetadata $refused, ?string $field = null): ActionRefused
    {
        return ActionRefused::because($refused->error, $refused->getMessage(), $field);
    }
}
