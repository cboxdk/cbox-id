<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Exceptions\InvalidManifest;
use Cbox\Id\AccessControl\Manifest\ManifestParser;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The PUSH transport for an app's authorization manifest. An app (or its CI) POSTs
 * its roles/permissions with its own client-credentials token (scope
 * `apps.manifest`); the token's client_id identifies the app, so an app can only
 * ever declare its OWN catalog. Same {@see AppManifests::sync()} as the pull/SDK/
 * manual paths — this is just a different way in.
 */
final class AppManifestController
{
    public function push(Request $request, ManifestParser $parser, AppManifests $manifests): JsonResponse
    {
        $token = $request->attributes->get('cbox_token');

        // RequireScope guarantees an active token with a client_id; narrow for types.
        if (! $token instanceof Introspection || $token->clientId === null) {
            return new JsonResponse(
                ['error' => 'invalid_token', 'message' => 'The access token is invalid or expired.'],
                401,
                ['WWW-Authenticate' => 'Bearer error="invalid_token"'],
            );
        }

        // A login client may also hold this machine scope. A person (including a
        // support actor) must never publish its application catalog through that client.
        $jti = $token->claims['jti'] ?? null;
        $machine = is_string($jti) && $jti !== '' && AccessToken::query()
            ->where('jti', $jti)->where('client_id', $token->clientId)
            ->whereNull('user_id')->whereNull('support_session_id')
            ->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
        if (! $machine || $token->subject !== $token->clientId || array_key_exists('act', $token->claims)) {
            return new JsonResponse(['error' => 'machine_token_required', 'message' => 'Publishing an app manifest requires its own client-credentials token.'], 403);
        }

        try {
            $manifest = $parser->parse($request->json()->all());
        } catch (InvalidManifest $e) {
            // `message`, not `detail`: the API has ONE error envelope and this was the
            // only endpoint spelling the human half differently.
            return new JsonResponse(['error' => 'invalid_manifest', 'message' => $e->getMessage()], 422);
        }

        $result = $manifests->sync($token->clientId, $manifest);

        return new JsonResponse([
            'client_id' => $token->clientId,
            'version' => $manifest->version,
            'checksum' => $manifest->checksum(),
            'unchanged' => $result->unchanged,
            'roles_declared' => $result->rolesDeclared,
            'permissions_declared' => $result->permissionsDeclared,
            'orphaned_roles' => $result->orphanedRoleKeys,
            'orphaned_permissions' => $result->orphanedPermissionKeys,
        ]);
    }
}
