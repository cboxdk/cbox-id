<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Platform\EnvironmentOwner;
use App\Platform\Help\DocsLinks;

/** Shared facts for the console, support and lifecycle integrations. No customer identities. */
final readonly class IntegrationProgress
{
    public function __construct(private EnvironmentChecklist $checklist, private EnvironmentOwner $workspace, private DocsLinks $docs) {}

    /** @return array{version: int, organization_id: string, workspace_id: string, project_id: string, environment_id: string, environment_type: string, observed_at: string, goals: array<string, bool>, first_sign_in_evidence: string, production_ready: null, guide_url: string|null} */
    public function read(): array
    {
        $environment = $this->workspace->environment();
        abort_unless($environment !== null && $environment->project_id !== null, 404);
        $workspace = $this->workspace->organizationOf($environment->id);
        abort_unless($workspace !== null, 404);
        $progress = $this->checklist->progress($workspace);

        return [
            'version' => 1,
            'organization_id' => $workspace,
            // The v2 name of the same id, for readers that have not moved (Engage, Assistant).
            // Removed in v3.1.
            'workspace_id' => $workspace,
            'project_id' => $environment->project_id,
            'environment_id' => $environment->id,
            'environment_type' => $environment->type->value,
            'observed_at' => now()->toIso8601String(),
            'goals' => $progress,
            // Token issuance is evidence of this milestone only, never proof of app-side
            // account linking, logout, tenant isolation or production readiness.
            'first_sign_in_evidence' => 'person_token_or_session_participant',
            'production_ready' => null,
            'guide_url' => $this->docs->page('getting-started/self-service-onboarding'),
        ];
    }
}
