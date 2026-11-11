<?php

declare(strict_types=1);

namespace App\Assistant;

use Cbox\AiAssistant\Connector\Events\SupportAccessUsed;
use Cbox\AiAssistant\Connector\Facades\AiAssistant;
use Cbox\AiAssistant\Connector\Profile\Signals;
use Cbox\AiAssistant\Connector\Support\ToolContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as Provider;

final class ServiceProvider extends Provider
{
    public function boot(): void
    {
        if (config('ai-assistant-connector.support.enabled') !== true) {
            return;
        }
        Event::listen(SupportAccessUsed::class, RecordSupportAccess::class);
        AiAssistant::tool('id_onboarding_status')->readOnly()
            ->description('Read current onboarding evidence for authorized ID environments, including a guide. Token issuance does not prove production readiness. No credentials or customer profiles.')
            ->parameters([])
            ->guard(fn (ToolContext $context): bool => app(SupportContext::class)->allowed($context, true))
            ->handle(fn (array $arguments, ToolContext $context): array => app(SupportContext::class)->onboarding($context));

        foreach ([
            'id_workspace_status' => ['Read the current Cbox ID organization and the caller’s membership role. No credentials or private profiles.', false],
            'id_environment_status' => ['Read this organization’s project and environment setup. Includes only environments the current member can access.', true],
        ] as $name => [$description, $withEnvironments]) {
            AiAssistant::tool($name)->readOnly()->description($description)->parameters([])
                ->guard(fn (ToolContext $context): bool => app(SupportContext::class)->allowed($context, $withEnvironments))
                ->handle(fn (array $arguments, ToolContext $context): array => app(SupportContext::class)->read($context, $withEnvironments));
        }

        AiAssistant::inboxCard('id_workspace')->title('Cbox ID organization')
            ->schema(['fields' => [
                ['key' => 'organization', 'label' => 'Organization'],
                ['key' => 'role', 'label' => 'Member role'],
                ['key' => 'observed_at', 'label' => 'Checked'],
            ]])
            ->guard(fn (ToolContext $context): bool => app(SupportContext::class)->allowed($context))
            ->handle(fn (array $arguments, ToolContext $context): array => app(SupportContext::class)->read($context, false));

        // The customer profile: what this workspace has in ID, as the signed-in member sees it.
        AiAssistant::resource(CustomerProfile::PROJECTS)->label('Projects')->singular('Project')->pickable()
            ->guard(fn (ToolContext $context): bool => app(CustomerProfile::class)->allowed($context))
            ->list(fn (ToolContext $context, array $filters): array => app(CustomerProfile::class)->projects($context, $filters));
        AiAssistant::resource(CustomerProfile::ENVIRONMENTS)->label('Environments')->singular('Environment')->pickable()
            ->guard(fn (ToolContext $context): bool => app(CustomerProfile::class)->allowed($context))
            ->list(fn (ToolContext $context, array $filters): array => app(CustomerProfile::class)->environments($context, $filters));
        AiAssistant::signals(fn (ToolContext $context): Signals => app(CustomerProfile::class)->signals($context));
    }
}
