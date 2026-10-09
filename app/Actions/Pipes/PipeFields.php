<?php

declare(strict_types=1);

namespace App\Actions\Pipes;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Pipes\Exceptions\InvalidPipeConfiguration;
use Cbox\Id\Pipes\Models\Pipe;
use Cbox\Id\Pipes\Models\PipeConnection;
use Cbox\Id\Pipes\Models\PipeGrant;
use Cbox\Id\Pipes\PipeProviderCatalog;

/**
 * What the Pipes actions share: finding a pipe or a connection IN THIS ENVIRONMENT (a
 * foreign id is a 404, never a cross-tenant write), turning the framework's refusals into
 * field errors, and the one payload every surface returns.
 *
 * The payload never carries the client secret or a token — not even a hint of one. A pipe
 * says whether a secret is set by existing; a connection says when its token expires.
 */
final class PipeFields
{
    public static function id(): Field
    {
        return Field::string('id')->inPath()->describe('The pipe\'s id.');
    }

    /** @return list<Field> */
    public static function parameterFields(): array
    {
        return [
            Field::object('parameters', [
                Field::string('tenant')->max(253)->describe('Microsoft 365 only: `common` (default), `organizations`, or one directory\'s tenant id or domain.'),
                Field::string('domain')->max(253)->describe('Salesforce only: `login.salesforce.com` (default), `test.salesforce.com`, or your My Domain host.'),
            ])->nullable()->describe('Per-installation values some providers need. Left out: the provider\'s defaults.'),
        ];
    }

    public static function scopesField(): Field
    {
        return Field::list('scopes', Field::string('scope')->max(190))->distinct()
            ->describe('The OAuth scopes to ask people for. Left out: the provider\'s defaults.');
    }

    public static function pipe(ActionContext $context): Pipe
    {
        return Pipe::query()->whereKey($context->string('id'))->first()
            ?? throw ActionRefused::notFound('pipe');
    }

    public static function connection(ActionContext $context, Pipe $pipe): PipeConnection
    {
        return PipeConnection::query()
            ->where('pipe_id', $pipe->id)
            ->whereKey($context->string('connection_id'))
            ->first()
            ?? throw ActionRefused::notFound('connection');
    }

    /**
     * An app of THIS environment — granting a client id nobody registered here would read
     * as access for an app that does not exist, and would quietly start working the day
     * somebody registered one with that id.
     */
    public static function assertApp(string $clientId): void
    {
        if (! Client::query()->where('client_id', $clientId)->exists()) {
            throw ActionRefused::because('unknown_app', 'There is no app with that client id in this environment.', 'client_id');
        }
    }

    /**
     * The framework's refusal, as a field error the form and the API both understand.
     */
    public static function refusal(InvalidPipeConfiguration $e): ActionRefused
    {
        return ActionRefused::because('invalid_pipe', $e->getMessage(), $e->field);
    }

    /**
     * @param  array<mixed>  $parameters
     * @return array<string, string>
     */
    public static function parameters(array $parameters): array
    {
        $clean = [];

        foreach ($parameters as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $clean[$key] = trim($value);
            }
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $scopes
     * @return list<string>
     */
    public static function scopes(array $scopes): array
    {
        return array_values(array_filter($scopes, 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Pipe $pipe): array
    {
        $entry = PipeProviderCatalog::find($pipe->provider);

        return [
            'id' => $pipe->id,
            'provider' => $pipe->provider,
            'name' => $entry->name ?? $pipe->provider,
            'client_id' => $pipe->client_id,
            'scopes' => $pipe->scopes,
            'parameters' => (object) $pipe->parameterValues(),
            'enabled' => $pipe->enabled,
            'redirect_uri' => route('account.pipes.callback', $pipe->provider),
            'grants' => array_values(PipeGrant::query()
                ->where('pipe_id', $pipe->id)
                ->orderBy('client_id')
                ->get()
                ->map(static fn (PipeGrant $grant): string => $grant->client_id)
                ->all()),
            'connections' => PipeConnection::query()->where('pipe_id', $pipe->id)->count(),
            'created_at' => Timestamp::of($pipe->created_at),
            'updated_at' => Timestamp::of($pipe->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentConnection(PipeConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'pipe_id' => $connection->pipe_id,
            'provider' => $connection->provider,
            'user_id' => $connection->user_id,
            'status' => $connection->status->value,
            'account' => $connection->account_label,
            'scopes' => $connection->scopes ?? [],
            'metadata' => (object) ($connection->metadata ?? []),
            'expires_at' => Timestamp::of($connection->access_expires_at),
            'connected_at' => Timestamp::of($connection->connected_at),
            'last_refreshed_at' => Timestamp::of($connection->last_refreshed_at),
            'refresh_failures' => $connection->refresh_failures,
            'last_error' => $connection->last_error,
            'reauth_reason' => $connection->reauth_reason,
        ];
    }
}
