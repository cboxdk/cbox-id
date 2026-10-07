<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Http\Resources\Environment\Timestamp;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/**
 * A pending agent request (OIDC CIBA) as the management API returns it — `AgentRequest`
 * in the spec. A helper, not an action.
 *
 * WHOSE request it is travels with it: one agent platform behind every row makes the app
 * name the same on all of them, and the subject is what tells "an agent asking to act as
 * Dana" from "an agent asking".
 */
final class AgentRequestFields
{
    /**
     * Pending and unexpired — the only requests anybody can still decide — in THIS
     * environment: the query is environment-scoped, so another plane's request is not
     * found.
     *
     * @return Builder<BackchannelAuthRequest>
     */
    public static function pending(): Builder
    {
        return BackchannelAuthRequest::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(BackchannelAuthRequest $request): array
    {
        $client = Client::query()->where('client_id', $request->client_id)->first(['client_id', 'name']);
        $subject = User::query()->whereKey($request->user_id)->first(['id', 'email']);

        return [
            'id' => $request->id,
            'client_id' => $request->client_id,
            'app' => $client === null ? $request->client_id : (string) $client->name,
            // Null rather than invented for a subject who has gone: the request can still be denied.
            'subject' => ['id' => $request->user_id, 'email' => $subject?->email],
            'binding_message' => $request->binding_message,
            'scopes' => array_values(array_filter($request->scopes, 'is_string')),
            'expires_at' => Timestamp::of($request->expires_at),
            'created_at' => Timestamp::of($request->getAttribute('created_at')),
        ];
    }
}
