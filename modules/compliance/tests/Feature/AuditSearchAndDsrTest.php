<?php

declare(strict_types=1);

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('searches the audit trail filtered by action within a scope', function (): void {
    $this->recordAudit('auth.login', 'org_alpha', 'user_1');
    $this->recordAudit('auth.logout', 'org_alpha', 'user_1');
    $this->recordAudit('auth.login', 'org_alpha', 'user_2');

    $page = app(AuditReader::class)->query(new AuditQueryFilter(
        organizationId: 'org_alpha',
        action: 'auth.login',
    ));

    expect($page->items)->toHaveCount(2);
    foreach ($page->items as $entry) {
        expect($entry->action)->toBe('auth.login');
    }
});

it('aggregates a data subject\'s audit trail across chains for a DSR access export', function (): void {
    $this->recordAudit('auth.login', null, 'user_1');          // system chain
    $this->recordAudit('org.viewed', 'org_alpha', 'user_1');   // org chain
    $this->recordAudit('org.viewed', 'org_alpha', 'user_2');   // a different subject

    $bundle = app(SubjectDataExport::class)->forSubject('user_1');

    expect($bundle->subjectId)->toBe('user_1')
        ->and($bundle->auditEntryCount())->toBe(2);

    foreach ($bundle->auditTrail as $record) {
        expect($record->actorId)->toBe('user_1');
    }

    // The bundle is portable JSON.
    expect($bundle->toArray())->toHaveKeys(['subject_id', 'generated_at', 'audit_trail']);
});
