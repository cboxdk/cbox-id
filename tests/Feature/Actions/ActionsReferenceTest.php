<?php

declare(strict_types=1);

use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\Reference\ActionsReference;

/*
| The committed actions reference — docs/reference/actions-<plane>.md — is exactly what the
| action registry builds. It is the page a developer wiring an agent reads to find the tool,
| the route and the command for a change; a new action is documented there by building,
| never by hand, and this is what fails when nobody did.
*/

it('commits the actions reference the registry builds', function (): void {
    $this->artisan('docs:actions', ['--check' => true])->assertSuccessful();
});

it('documents every action of a plane with its four doors', function (ActionPlane $plane): void {
    $page = (string) file_get_contents(base_path(ActionsReference::path($plane)));

    foreach (app(ActionRegistry::class)->forPlane($plane) as $action) {
        expect($page)
            ->toContain('### '.$action->name."\n")
            ->toContain('`'.$action->method.' /api/v1'.$action->documentedPath().'`')
            ->toContain('| MCP tool | `'.$action->toolName().'` |')
            ->toContain('| CLI | `cbox id '.str_replace('.', ' ', $action->name));
    }
})->with(ActionPlane::cases());

it('fails the check when a page has drifted, and names it', function (): void {
    $path = base_path(ActionsReference::path(ActionPlane::Platform));
    $committed = (string) file_get_contents($path);

    try {
        file_put_contents($path, $committed."\nA line nobody generated.\n");

        $this->artisan('docs:actions', ['--check' => true])
            ->expectsOutputToContain('docs/reference/actions-platform.md')
            ->assertFailed();
    } finally {
        file_put_contents($path, $committed);
    }
});

it('anchors a heading the way a Markdown renderer does', function (): void {
    expect(ActionsReference::slug('apps.secrets.rotate'))->toBe('appssecretsrotate')
        ->and(ActionsReference::slug('App audit logs'))->toBe('app-audit-logs')
        ->and(ActionsReference::slug('team.transfer_ownership'))->toBe('teamtransfer_ownership');
});
