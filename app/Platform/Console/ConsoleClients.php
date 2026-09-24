<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * WHICH APP a console request is about, and whether the person acting may change it.
 *
 * The app page is several pages now — its overview, its scopes, its secrets, its settings
 * — each with a controller of its own, and every one of them takes an id straight out of
 * the URL. That is where an IDOR lives, so the answer to "may this person see this app,
 * and may they change it" is written here once rather than once per controller, where a
 * fifth page would be one forgotten call away from handing a tenant administrator another
 * tenant's app by id.
 *
 * Resolved INSIDE the query, not checked afterwards: the organization predicate is part of
 * the WHERE clause, so an app outside the acting scope is never loaded at all.
 */
final readonly class ConsoleClients
{
    public function __construct(private ConsoleScope $scope) {}

    /**
     * The app, re-resolved and re-scoped on every read and write.
     *
     * An organization sees its own apps and the platform's first-party ones — those appear
     * in its launcher and skip its consent screen, so it must be able to read them — and
     * nothing else. 404 rather than 403, because the caller was not entitled to learn the
     * app exists.
     */
    public function visible(string $id): Client
    {
        $organizationId = $this->scope->plane() === ConsolePlane::Environment
            ? $this->scope->organizationId()
            : $this->scope->requireOrganizationId();

        $client = Client::query()
            ->whereKey($id)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped
                    ->where('organization_id', $organizationId)
                    ->orWhere(fn (Builder $platform): Builder => $platform
                        ->whereNull('organization_id')
                        ->where('first_party', true)),
            ))
            ->first();

        abort_if($client === null, 404);

        return $client;
    }

    /**
     * The app, refused unless this administrator may CHANGE it.
     *
     * Resolved inside the gate rather than checked afterwards, so every mutation shares one
     * answer instead of each remembering to ask.
     */
    public function manageable(string $id): Client
    {
        $this->scope->assertMayAdminister();

        $client = $this->visible($id);

        abort_unless($this->mayManage($client), 403);

        return $client;
    }

    /**
     * Whether this administrator may change this app, as opposed to look at it.
     *
     * On the ENVIRONMENT plane the administrator is the operator above every organization
     * in it, so every app this console can see is theirs to manage; the environment scope
     * is the real boundary and an app in another environment is not visible here at all.
     * On the ORGANIZATION plane it is the organization's own apps and nothing else — which
     * is what makes the platform's first-party apps visible to a tenant administrator and
     * not rotatable or deletable by one.
     */
    public function mayManage(Client $client): bool
    {
        if ($this->scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $client->organization_id !== null
            && $client->organization_id === $this->scope->organizationId();
    }
}
