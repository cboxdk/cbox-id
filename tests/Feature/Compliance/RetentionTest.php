<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Retention\RetentionPolicy;
use Cbox\Id\Kernel\Audit\Models\AuditCheckpoint;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAuditTrail;

uses(RefreshDatabase::class, InteractsWithAuditTrail::class);

it('never deletes audit entries: retention archives and anchors, it does not erase', function (): void {
    $this->recordAudit('auth.login');
    $this->recordAudit('auth.logout');
    $this->recordAudit('org.updated', 'org_alpha');

    $before = AuditEntry::query()->count();

    $report = app(RetentionPolicy::class)->apply();

    expect(AuditEntry::query()->count())->toBe($before)
        ->and($report->entriesDeleted)->toBe(0);
});

it('signs a checkpoint per chain so retained history stays externally verifiable', function (): void {
    $this->recordAudit('auth.login');                 // system chain
    $this->recordAudit('org.updated', 'org_alpha');   // org chain

    $report = app(RetentionPolicy::class)->apply();

    expect($report->checkpointCount())->toBe(2)
        ->and($report->checkpointedScopes)->toContain('__system__', 'org_alpha')
        ->and(AuditCheckpoint::query()->count())->toBe(2);
});
