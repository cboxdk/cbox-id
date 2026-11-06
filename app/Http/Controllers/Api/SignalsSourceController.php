<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\SignalsData\SourceLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SignalsSourceController
{
    public function erasures(Request $request, SourceLedger $ledger): JsonResponse
    {
        $request->validate(['after' => 'sometimes|string|max:1024', 'limit' => 'sometimes|integer|min:1|max:1000',
            'environment_id' => 'prohibited', 'organization_id' => 'prohibited', 'project_id' => 'prohibited']);

        return response()->json($ledger->erasuresPage($request->filled('after') ? $request->string('after')->toString() : null, $request->integer('limit', 500)))
            ->header('Cache-Control', 'no-store');
    }

    public function __invoke(Request $request, SourceLedger $ledger): JsonResponse
    {
        $request->validate(['after' => 'sometimes|string|max:1024', 'limit' => 'sometimes|integer|min:1|max:1000',
            'environment_id' => 'prohibited', 'organization_id' => 'prohibited', 'project_id' => 'prohibited']);

        return response()->json($ledger->page($request->filled('after') ? $request->string('after')->toString() : null, $request->integer('limit', 500)))
            ->header('Cache-Control', 'no-store');
    }
}
