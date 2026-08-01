<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Support\InteractsWithAuditTrail;

uses(RefreshDatabase::class, InteractsWithAuditTrail::class);

/**
 * The compliance module's own pages, tested for the thing they got wrong: they guard on
 * "is an admin" — of THEIR organization — and then took the scope from the user.
 *
 * The audit page had a text input bound to a public property with no #[Locked], passed
 * straight into the reader. The console's own audit page one directory over has always
 * bound the same filter to CurrentUser. So the difference between reading your own trail
 * and reading every other tenant's was a ULID typed into a box.
 */
it('shows an admin only their own organization\'s trail, whatever they send', function (): void {
    $orgId = gateAdmin('scope-mine');
    grantFeature($orgId, 'compliance');

    $this->recordAudit('mine.event', $orgId, 'user_1');
    $this->recordAudit('theirs.event', 'org_someone_else', 'user_1');
    $this->recordAudit('system.event', null, 'user_1');

    $component = Volt::test('compliance.audit');
    $actions = fn (): array => array_map(fn ($entry): string => $entry->action, $component->viewData('entries'));

    // Setting up an organization audits itself, so assert on what must NOT be there
    // rather than on an exact list — the guarantee is "nothing from another chain".
    expect($actions())->toContain('mine.event')
        ->and($actions())->not->toContain('theirs.event')
        ->and($actions())->not->toContain('system.event');

    // The property is gone, so Livewire refuses to set it at all — but assert on the
    // OUTPUT rather than the exception, because the guarantee is "you see your own
    // trail", not "this particular property does not exist".
    try {
        $component->set('organizationId', 'org_someone_else');
    } catch (Throwable) {
        // Expected: there is nothing to set.
    }

    expect($actions())->not->toContain('theirs.event', 'the compliance audit page read another tenant\'s chain');
});

/**
 * Running an export advances EVERY tenant's cursor, so the next export for a tenant that
 * did not ask for it skips its own entries. Applying retention checkpoints every scope.
 * Neither belongs to one organization's admin.
 */
it('refuses environment-wide export and retention from an org-scoped page', function (): void {
    $orgId = gateAdmin('scope-ops');
    grantFeature($orgId, 'compliance');

    Volt::test('compliance.exports')->call('runExport')->assertForbidden();
    Volt::test('compliance.exports')->call('applyRetention')->assertForbidden();
});

/**
 * The data-subject export is the other half of the same page and had the same shape:
 * it swept every chain in the environment.
 */
it('builds a data-subject bundle from the acting organization only', function (): void {
    $orgId = gateAdmin('scope-dsr');
    grantFeature($orgId, 'compliance');

    $this->recordAudit('mine.event', $orgId, 'user_1');
    $this->recordAudit('theirs.event', 'org_someone_else', 'user_1');

    $bundle = app(SubjectDataExport::class)->forSubject('user_1', $orgId);

    $actions = array_map(fn ($record): string => $record->action, $bundle->auditTrail);

    expect($actions)->toContain('mine.event')->and($actions)->not->toContain('theirs.event');
});
