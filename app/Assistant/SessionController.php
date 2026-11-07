<?php

declare(strict_types=1);

namespace App\Assistant;

use Cbox\AiAssistant\Connector\Support\SupportSession;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController
{
    public function __invoke(Request $request, SupportContext $context, SupportSession $support, PlatformRoot $root, Subjects $subjects): JsonResponse
    {
        abort_unless(config('ai-assistant-connector.support.enabled') === true, 404);
        $identity = $context->sessionIdentity($request);
        abort_unless($identity !== null, 403);
        $subject = $root->run(fn () => $subjects->find($identity['subject']));

        return response()->json($support->forUser($identity['subject'], $identity['account'], 'cbox-id',
            ['tenant' => $identity['account'], 'environment' => $identity['environment']], $subject?->name, $subject?->email))
            ->header('Cache-Control', 'no-store');
    }
}
