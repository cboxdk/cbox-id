<?php

declare(strict_types=1);

use Cbox\Id\AuditQuery\Contracts\AuditReader;
use Cbox\Id\AuditQuery\ValueObjects\AuditQueryFilter;
use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAuditTrail;

uses(RefreshDatabase::class, InteractsWithAuditTrail::class);

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

/**
 * An access request is about the person, not about their keystrokes.
 *
 * This used to sweep every chain in the environment and match `actor_id` only, so it was
 * wrong in both directions at once: it returned records from organizations the acting
 * admin has no relationship with, and it omitted everything done TO the subject — an
 * administrator resetting their second factor, changing their role, removing them from
 * an organization. Article 15 is about personal data concerning the subject, and the
 * things done to them concern them considerably more than the things they did.
 *
 * The stated reason for the omission was that the reader exposed no target filter. It
 * has since v0.19.0.
 */
it('returns what the subject did AND what was done to them, from one organization', function (): void {
    $this->recordAudit('auth.login', 'org_alpha', 'user_1');                       // they acted
    $this->recordAudit('user.mfa_disabled', 'org_alpha', 'admin_9', 'user_1');     // done to them
    $this->recordAudit('org.viewed', 'org_alpha', 'user_2');                       // someone else
    $this->recordAudit('auth.login', 'org_beta', 'user_1');                        // another tenant
    $this->recordAudit('auth.login', null, 'user_1');                              // the system chain

    $bundle = app(SubjectDataExport::class)->forSubject('user_1', 'org_alpha');

    $actions = array_map(fn ($record): string => $record->action, $bundle->auditTrail);

    expect($bundle->subjectId)->toBe('user_1')
        ->and($actions)->toContain('auth.login')
        // The one that used to be missing: an admin stripping their second factor.
        ->and($actions)->toContain('user.mfa_disabled')
        ->and($bundle->auditEntryCount())->toBe(2);

    // The bundle is portable JSON.
    expect($bundle->toArray())->toHaveKeys(['subject_id', 'generated_at', 'audit_trail']);
});

/**
 * The scope is the caller's own organization. Nothing from another tenant's chain, and
 * nothing from the system chain, reaches a bundle built for one organization.
 */
it('does not reach another organization\'s chain', function (): void {
    $this->recordAudit('auth.login', 'org_alpha', 'user_1');
    $this->recordAudit('secret.thing', 'org_beta', 'user_1');

    $bundle = app(SubjectDataExport::class)->forSubject('user_1', 'org_alpha');
    $actions = array_map(fn ($record): string => $record->action, $bundle->auditTrail);

    expect($actions)->toBe(['auth.login'], 'the export crossed into another tenant\'s trail');
});

/**
 * One event where the subject is both actor and target — changing their own password —
 * is one record, not two. The two-pass read has to deduplicate or every self-service
 * action would appear twice in a document handed to a regulator.
 */
it('counts an event where the subject is both actor and target once', function (): void {
    $this->recordAudit('user.password_changed', 'org_alpha', 'user_1', 'user_1');

    expect(app(SubjectDataExport::class)->forSubject('user_1', 'org_alpha')->auditEntryCount())->toBe(1);
});
