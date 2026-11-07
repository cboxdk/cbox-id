<?php

declare(strict_types=1);

use Cbox\Billing\Client\Contracts\ManagementTransport;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\Invoice;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Id\Identity\Contracts\Subjects;

it('renders account cards and tax-inclusive invoices on desktop and a phone without project data', function (): void {
    installedDeployment();
    centralBillingFixture();
    ['subjectId' => $owner, 'organization' => $organization] = provisionAccount();
    app(Subjects::class)->markEmailVerified($owner, 'owner@acme.example');
    signInAsMember($owner);
    $payments = new FakeManagementTransport;
    app()->instance(ManagementTransport::class, $payments);
    $payments->withPaymentMethod($organization->id, new PaymentMethod('pm_browser', 'visa', '4242', 12, 2030, true));
    $payments->withInvoice($organization->id, new Invoice('INV-2026-1', new DateTimeImmutable('2026-11-01'), 12500, 'DKK', 'paid', 10000, 2500));

    $page = visit('/billing?organization='.$organization->id);
    $page->assertSee('Billing details')->assertSee('Payment cards')->assertSee('4242')
        ->assertSee('INV-2026-1')->assertSee('Subtotal')->assertSee('Tax')->assertSee('Download PDF')
        ->assertDontSee('Early access — free')->assertDontSee('Per-project billing arrives')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'billing-account-desktop');
    $payments->takeDown();
    $page->click('button:has-text("Add card")')->assertSee('Payment setup could not be completed. Try again.')
        ->assertSee('Try again')->click('button:has-text("Cancel")');
    $payments->bringUp();
    $page->click('button:has-text("Remove")')->assertSee('Remove this card?')->click('button:has-text("Cancel")')
        ->resize(390, 844)->assertSee('Billing details')->assertSee('INV-2026-1')
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()->screenshot(filename: 'billing-account-phone');
});
