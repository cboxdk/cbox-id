<?php

declare(strict_types=1);

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Enums\TaxBasis;
use Cbox\Billing\Client\Enums\TaxStatus;
use Cbox\Billing\Client\Testing\FakeManagementTransport;
use Cbox\Billing\Client\ValueObjects\PaymentMethod;
use Cbox\Billing\Client\ValueObjects\Plan;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\PlatformRoot;

beforeEach(function (): void {
    centralBillingFixture();
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->andReturnNull();
    app()->instance(ProjectPayments::class, $payments);
});

it('renders billing details and catalog accessibly at phone width', function (): void {
    installedDeployment();
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $fake = (new FakeManagementTransport)->withPlan(new Plan('id-test', 'Identity Standard', 10000, 'DKK', 'month', [], ['cbox-id.environments' => 3], 'cbox-id'));
    app()->instance(BillingManagement::class, new BillingManagement($fake));
    $account = provisionAccount('billing-browser@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'billing-browser@example.test'));
    signInAsMember($account['subjectId']);
    $page = visit('/billing')->on()->mobile();
    $page->assertSee('Billing details')->assertSee('Payment cards')->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()->screenshot(filename: 'billing-ready-mobile');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('button:has-text("Choose a plan")')->assertSee('Identity Standard')
        ->assertSee('Tax has not been calculated yet.')->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()->screenshot(filename: 'billing-ready-plans');
});

it('shows charges and lost credits before a plan change can be confirmed', function (): void {
    installedDeployment();
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $fake = (new FakeManagementTransport)->withPlan(new Plan('id-test', 'Identity Standard', 10000, 'DKK', 'month', [], ['cbox-id.environments' => 3], 'cbox-id'))
        ->withTax(new PriceTax(TaxStatus::Calculated, TaxBasis::Customer, 'DK', rate: '25'));
    app()->instance(BillingManagement::class, new BillingManagement($fake));
    $account = provisionAccount('billing-confirm-browser@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'billing-confirm-browser@example.test'));
    signInAsMember($account['subjectId']);
    $payments = Mockery::mock(ProjectPayments::class);
    $payments->shouldReceive('subscription')->andReturn(['plan' => 'id-test', 'status' => 'active', 'recurring' => ['currency' => 'DKK']]);
    $payments->shouldReceive('perform')->times(3)->with('preview', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->andReturn([
        'due_now_minor' => 2500, 'new_recurring_minor' => 10000, 'new_recurring_tax' => ['total_minor' => 12500],
        'credit_delta' => ['forfeited' => 40, 'granted' => 100, 'carried' => 0],
        'consequences' => [['subscription' => 'other-product', 'message' => 'Your other product loses its bundle discount.']],
    ]);
    $payments->shouldReceive('perform')->once()->with('change', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())->andReturn(['ok' => true]);
    app()->instance(ProjectPayments::class, $payments);
    $page = visit('/billing')->on()->mobile();
    $page->click('button:has-text("Choose a plan")')->click('button:has-text("Review plan")')
        ->assertSee('Due now, including tax')->assertSee('Included credits removed: 40')
        ->assertSee('Your other product loses its bundle discount.')->assertNoJavaScriptErrors()
        ->assertNoAccessibilityIssues()->screenshot(filename: 'billing-confirm-mobile');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('button:has-text("Back")')->assertSee('Identity Standard')
        ->click('button:has-text("Review plan")')->assertSee('Due now, including tax');
    $page->click('button:has-text("Confirm")')->assertSee('Saved')->assertNoJavaScriptErrors();
    expect($account['project']->fresh()->environment_limit)->toBe($account['project']->environment_limit);
});

it('keeps a billing address draft when a card changes and guards navigation', function (): void {
    installedDeployment();
    config(['billing-client.base_url' => 'https://payments.test', 'billing-client.api_token' => 'test']);
    $account = provisionAccount('billing-draft@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'billing-draft@example.test'));
    $fake = (new FakeManagementTransport)->withPaymentMethod($account['organization']->id,
        new PaymentMethod('card-test', 'visa', '4242', 12, 2030, false));
    app()->instance(BillingManagement::class, new BillingManagement($fake));
    signInAsMember($account['subjectId']);
    $page = visit('/billing')->resize(1440, 1000);
    $page->assertSee('Billing details')->screenshot(filename: 'polish-billing-overview-desktop')
        ->fill('invoice_name', 'Unsaved invoice name')
        ->click('button:has-text("Make default")')->assertSee('Default')
        ->assertScript('document.querySelector("input[name=invoice_name]").value', 'Unsaved invoice name')
        ->screenshot(false, 'polish-billing-desktop'); // viewport only: a full-page shot remounts the page and drops the draft
    $page->click('a.cbx-rail-link[aria-label="Organization"]')
        ->assertSee('Leave without saving?')->click('button:has-text("Keep editing")')
        ->assertScript('document.querySelector("input[name=invoice_name]").value', 'Unsaved invoice name');
    $page->resize(375, 812)->click('form button:has-text("Save")')
        ->assertVisible('input[name=billing_email][aria-invalid=true]')
        ->assertScript('document.activeElement.name', 'billing_email')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
        ->screenshot(filename: 'polish-billing-validation-mobile');
});

it('keeps project settings usable at phone and desktop widths with long names', function (): void {
    installedDeployment();
    $account = provisionAccount('project-polish@example.test');
    $account['project']->update(['name' => str_repeat('LongProjectName', 10)]);
    signInAsMember($account['subjectId']);
    $page = visit('/projects/'.$account['project']->id);
    foreach ([375, 768, 1440] as $width) {
        $page->resize($width, 900)->assertSee('Add environment')
            ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
            ->assertNoJavaScriptErrors()->assertNoAccessibilityIssues()
            ->screenshot(filename: 'polish-project-'.$width);
    }
});

it('does not offer new environments on a suspended project', function (): void {
    installedDeployment();
    $account = provisionAccount('project-suspended@example.test');
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($account['subjectId'], 'project-suspended@example.test'));
    $account['project']->update(['status' => 'suspended']);
    signInAsMember($account['subjectId']);
    visit('/projects/'.$account['project']->id)->resize(375, 812)
        ->assertScript('document.querySelector("button[type=submit]").disabled', true)
        ->assertSee('Existing environments stay live')->assertNoJavaScriptErrors();
    visit('/projects')->assertDontSee('New environment')->assertSee('Existing environments stay live');
});
