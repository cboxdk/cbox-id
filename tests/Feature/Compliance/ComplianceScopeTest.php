<?php

declare(strict_types=1);

use Cbox\Id\Compliance\Dsr\SubjectDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithAuditTrail;

uses(RefreshDatabase::class, InteractsWithAuditTrail::class);

/*
 * The module's routes only exist where its feature is switched on, so a test that drives
 * them by REQUEST has to say so — the pages were driven at the component before, which
 * needed no route at all.
 */
beforeEach(fn () => config(['compliance.enabled' => true]));

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

    $actions = fn (array $query = []): array => collect(
        (array) test()->get(route('compliance.audit', $query))->assertOk()->inertiaProps('entries')
    )->pluck('action')->all();

    // Setting up an organization audits itself, so assert on what must NOT be there rather
    // than on an exact list — the guarantee is "nothing from another chain".
    expect($actions())->toContain('mine.event')
        ->and($actions())->not->toContain('theirs.event')
        ->and($actions())->not->toContain('system.event');

    /*
     * WHATEVER THEY SEND. The organization used to be a bound property, and the page is a
     * request now — so the shape of the attempt changed and the property did not: a query
     * parameter naming somebody else's chain must be read as nothing at all.
     *
     * Asserted on the OUTPUT rather than on a refusal, because the guarantee is "you see
     * your own trail", not "this particular parameter is rejected" — a page that started
     * honouring it would be caught here whatever it called the field.
     *
     * NO MESSAGE ARGUMENT on the negated check. `toContain` is variadic and takes no
     * message, so a string passed second is read as a SECOND NEEDLE — and `not->toContain`
     * passes the moment any needle is absent. That is how this half of the test once proved
     * nothing at all.
     *
     * A positive control beside it, because "does not contain another tenant's event" is
     * also satisfied by a page that rendered nothing — which is how a broken read path looks
     * identical to a correctly-scoped one.
     */
    $crafted = $actions(['organizationId' => 'org_someone_else', 'organization' => 'org_someone_else']);

    expect($crafted)->not->toContain('theirs.event')
        ->and($crafted)->toContain('mine.event');
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

    /*
     * ASKED FOR, not grepped. "The method does not exist" was a claim about one class; the
     * claim that matters is that no ROUTE performs either — a controller action added under
     * any name would satisfy the old test and still let one tenant's admin move every other
     * tenant's export cursor.
     */
    foreach (['run-export', 'export', 'retention', 'apply-retention'] as $path) {
        test()->post('/compliance/exports/'.$path)->assertNotFound();
    }

    // And the page offers nothing to click: the only write it carries is the data-subject
    // export, which IS bounded by an organization.
    $props = (array) test()->get(route('compliance.data-exports'))->assertOk()->inertiaProps();

    $urls = array_keys(array_filter(
        $props,
        static fn (mixed $value, string $key): bool => str_ends_with($key, 'Href'),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($urls)->toBe(['downloadHref'])
        ->and($props['downloadHref'])->toBe(route('compliance.data-exports.download'));
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
