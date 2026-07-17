<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\AccessControl\Exceptions\InvalidManifest;
use Cbox\Id\AccessControl\Manifest\ManifestParser;
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
            return new JsonResponse(['error' => 'invalid_token'], 401);
        }

        try {
            $manifest = $parser->parse($request->json()->all());
        } catch (InvalidManifest $e) {
            return new JsonResponse(['error' => 'invalid_manifest', 'detail' => $e->getMessage()], 422);
        }

        $result = $manifests->sync($token->clientId, $manifest);

        return new JsonResponse([
            'unchanged' => $result->unchanged,
            'roles_declared' => $result->rolesDeclared,
            'permissions_declared' => $result->permissionsDeclared,
            'orphaned_roles' => $result->orphanedRoleKeys,
            'orphaned_permissions' => $result->orphanedPermissionKeys,
        ]);
    }
}
