<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\ServiceConnections\Contracts\ReadsConnectionEvents;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionEventsController
{
    public function __invoke(Request $request, string $id, ReadsConnectionEvents $events): JsonResponse
    {
        $request->validate(['after' => 'sometimes|string|max:64', 'limit' => 'sometimes|integer|min:1|max:100',
            'types' => 'sometimes|array|max:50', 'types.*' => 'required|string|max:190', 'organization_id' => 'prohibited']);
        $caller = $request->attributes->get('cbox_token');
        abort_unless($caller instanceof Introspection, 401);
        $types = array_values(array_filter($request->array('types'), is_string(...)));

        return response()->json($events->page($caller, $id, $types, $request->filled('after') ? $request->string('after')->toString() : null, $request->integer('limit', 100)))
            ->header('Cache-Control', 'no-store');
    }
}
