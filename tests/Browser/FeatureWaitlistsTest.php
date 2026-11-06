<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('lets a customer follow a feature, withdraw, and apply for its beta', function (): void {
    installedDeployment();
    $environment = platformRootEnvironment();
    [$person, $team] = app(PlatformRoot::class)->run(function (): array {
        $person = app(Subjects::class)->create('feature-browser@example.test', 'Ada', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($person->id, $person->email);
        $team = app(Organizations::class)->create(new NewOrganization('Studio team', 'feature-browser'));
        app(Memberships::class)->add($team->id, $person->id, MembershipRole::Member);

        return [$person, $team];
    });
    signInAsMember($person->id);
    config(['service-access.enabled' => true, 'service-access.identity_environment' => $environment->id, 'service-access.billing_url' => 'https://billing.example.test', 'service-access.billing_token' => 'browser-fixture']);
    $status = null;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$status) {
        if (str_ends_with($request->url(), '/join')) {
            $status = $request['interest'] === 'notify' ? 'following' : 'waiting';

            return Http::response(['registration' => ['status' => $status]]);
        }
        if (str_ends_with($request->url(), '/leave')) {
            $status = null;

            return Http::response(['status' => 'withdrawn']);
        }
        if (str_ends_with($request->url(), '/registrations')) {
            return Http::response(['data' => [['program_id' => 42, 'registration' => $status === null ? null : ['status' => $status]]]]);
        }

        return Http::response(['data' => [['id' => 42, 'key' => 'studio', 'name' => 'New editor', 'description' => 'Try collaborative editing before the general release.', 'mode' => 'waitlist', 'kind' => 'feature', 'scope' => 'organization', 'service_name' => 'Studio', 'feature_key' => 'studio.editor', 'public_url' => null, 'questions' => []]], 'current_page' => 1, 'last_page' => 1]);
    });
    $page = visit('/account/services?kind=feature&organization='.$team->id);
    $page->assertSee('New editor')->assertSee('Studio team')->assertSee('Notify me')->assertSee('Join the test group')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'feature-waitlist-choices');
    $page->click('Join waitlist')->assertSee('Following updates')->click('Withdraw signup')
        ->assertSee('Notify me')->click('Join the test group')->click('Join waitlist')->assertSee('On the waitlist')
        ->assertNoJavaScriptErrors()->screenshot(filename: 'feature-waitlist-joined');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/join') && $request['subject'] === $person->id && $request['organization'] === $team->id && $request['interest'] === 'test');
})->group('a11y');
