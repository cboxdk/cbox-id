<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
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

    // NO MESSAGE ARGUMENT. `toContain` is variadic and takes no message, so a string
    // passed second is read as a SECOND NEEDLE — and `not->toContain` passes the moment
    // any needle is absent. The message never appears in the haystack, so the assertion
    // was unconditionally green and this half of the test proved nothing. Verified:
    // `expect('contains the needle')->not->toContain('needle', 'a message')` passes.
    //
    // A positive control beside it, because "does not contain another tenant's event" is
    // also satisfied by a page that rendered nothing at all — which is how a broken read
    // path would look identical to a correctly-scoped one.
    expect($actions())->not->toContain('theirs.event')
        ->and($actions())->toContain('mine.event');
});

/**
 * Running an export advances EVERY tenant's cursor, so the next export for a tenant that
 * did not ask for it skips its own entries. Applying retention checkpoints every scope.
 * Neither belongs to one organization's admin — so the page offers neither.
 *
 * This used to assert a 403 from two refusal methods kept "so the buttons that used to
 * call them fail loudly if one is missed in the markup". Both buttons WERE missed: they
 * were still wired, still rendered, and every click was a guaranteed 403. The refusals
 * and the buttons are gone together, and the work runs on the schedule — asserting the
 * absence is what keeps a future edit from re-adding a control nothing may authorize.
 */
it('offers no environment-wide export or retention action on an org-scoped page', function (): void {
    $orgId = gateAdmin('scope-ops');
    grantFeature($orgId, 'compliance');

    $component = Volt::test('compliance.exports');

    foreach (['runExport', 'applyRetention'] as $method) {
        expect(fn () => $component->call($method))->toThrow(MethodNotFoundException::class);
    }

    // And nothing in the markup invites the click either.
    $component->assertDontSee('wire:click="runExport"', escape: false)
        ->assertDontSee('wire:click="applyRetention"', escape: false);
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
