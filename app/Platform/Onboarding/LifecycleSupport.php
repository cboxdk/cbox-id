<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use Illuminate\Support\Facades\Http;
use Throwable;

/** No cached "no open case" result may authorize a reminder. */
final class LifecycleSupport
{
    public function canRemind(string $workspace): bool
    {
        $url = config('ai-assistant-connector.platform.url');
        $key = config('ai-assistant-connector.api_key');
        $assistant = config('ai-assistant-connector.support.assistant');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! is_string($key) || $key === '' || ! is_string($assistant) || $assistant === '') {
            return false;
        }
        try {
            $response = Http::withToken($key)->acceptJson()->withoutRedirecting()->connectTimeout(1)->timeout(2)
                ->post(rtrim($url, '/').'/api/v1/assistants/'.rawurlencode($assistant).'/support-activity', [
                    'account_id' => $workspace, 'identity_namespace' => config()->string('onboarding.identity_namespace'),
                ]);

            return $response->successful() && $response->json('data.paused') === false;
        } catch (Throwable) {
            return false;
        }
    }
}
