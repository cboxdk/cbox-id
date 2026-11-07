<?php

declare(strict_types=1);

namespace App\Assistant;

use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Illuminate\Support\ServiceProvider as Provider;

final class ServiceProvider extends Provider
{
    public function boot(): void
    {
        if (config('ai-assistant-connector.support.enabled') !== true) {
            return;
        }
        foreach ([
            'id_workspace_status' => ['Read the current Cbox ID workspace and the caller’s membership role. No credentials or private profiles.', false],
            'id_environment_status' => ['Read this workspace’s project and environment setup. Includes only environments the current member can access.', true],
        ] as $name => [$description, $withEnvironments]) {
            AiAssistant::tool($name)->readOnly()->description($description)->parameters([])
                ->guard(fn (ToolContext $context): bool => app(SupportContext::class)->allowed($context, $withEnvironments))
                ->handle(fn (array $arguments, ToolContext $context): array => app(SupportContext::class)->read($context, $withEnvironments));
        }
    }
}
