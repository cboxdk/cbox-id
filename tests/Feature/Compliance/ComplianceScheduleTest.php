<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Audit\Models\AuditCheckpoint;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAuditTrail;

uses(RefreshDatabase::class, InteractsWithAuditTrail::class);

/**
 * The compliance engine could not run in ANY deployment.
 *
 * `id-compliance:export` was registered as a command and scheduled nowhere; there was no
 * retention command at all, so `RetentionPolicy::apply()`'s only callers repo-wide were
 * two tests. An operator could wire a SIEM, watch the module report itself active and the
 * dashboard show a growing pending backlog, and never have one entry shipped — which is
 * the module's entire value proposition. These hold the wiring, not the mechanics: the
 * export and retention behaviour itself is covered by their own tests.
 */
function scheduledComplianceCommands(): array
{
    // Rebuild: the schedule is a singleton, and `callAfterResolving` fires on the build,
    // so the config each test sets has to be in place before it is resolved.
    app()->forgetInstance(Schedule::class);

    $commands = [];

    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'id-compliance:')) {
            $commands[$event->description ?? ''] = $event->expression;
        }
    }

    return $commands;
}

it('schedules BOTH the export and retention once compliance is active', function (): void {
    config(['compliance.enabled' => true]);

    expect(scheduledComplianceCommands())
        ->toHaveKey('cbox-id:compliance:export')
        ->toHaveKey('cbox-id:compliance:retention');
});

it('runs the export often enough to be a pipeline and retention daily', function (): void {
    config(['compliance.enabled' => true]);

    $scheduled = scheduledComplianceCommands();

    // A SIEM pipeline that lags an hour is one an incident responder cannot use.
    expect($scheduled['cbox-id:compliance:export'])->toBe('*/5 * * * *')
        ->and($scheduled['cbox-id:compliance:retention'])->toBe('0 0 * * *');
});

it('schedules nothing on an install that never wired compliance', function (): void {
    // Default: the inert null sink and the module switched off. Scheduling the export
    // anyway would write a run row per environment every five minutes for work nobody
    // asked for.
    config(['compliance.enabled' => false, 'compliance.export.sink' => 'null']);

    expect(scheduledComplianceCommands())->toBe([]);
});

it('honours the per-job schedule switches', function (): void {
    config(['compliance.enabled' => true, 'compliance.schedule.export' => false]);

    expect(scheduledComplianceCommands())
        ->not->toHaveKey('cbox-id:compliance:export')
        ->toHaveKey('cbox-id:compliance:retention');
});

it('checkpoints every chain of every environment through the retention command', function (): void {
    $context = app(EnvironmentContext::class);

    foreach (['alpha', 'beta'] as $slug) {
        Environment::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => false,
            'settings' => [],
        ]);
    }

    $alpha = Environment::query()->where('slug', 'alpha')->firstOrFail();
    $beta = Environment::query()->where('slug', 'beta')->firstOrFail();

    // One organization chain plus the system chain in alpha; system only in beta.
    $context->runAs(GenericEnvironment::of($alpha->id), function (): void {
        $this->recordAudit('auth.login');
        $this->recordAudit('org.updated', 'org_alpha');
    });
    $context->runAs(GenericEnvironment::of($beta->id), fn () => $this->recordAudit('auth.login'));

    // Nothing establishes an environment on the command line, which is why the command
    // enumerates them itself — an unscoped run would anchor nothing and exit 0.
    $this->artisan('id-compliance:retention')->assertExitCode(0);

    $alphaChains = $context->runAs(GenericEnvironment::of($alpha->id), fn (): int => AuditCheckpoint::query()->count());
    $betaChains = $context->runAs(GenericEnvironment::of($beta->id), fn (): int => AuditCheckpoint::query()->count());

    expect($alphaChains)->toBe(2)
        ->and($betaChains)->toBe(1);
});

it('fails rather than reporting a silent success for an environment that does not exist', function (): void {
    $this->artisan('id-compliance:retention', ['--environment' => 'gone'])->assertExitCode(1);
});
