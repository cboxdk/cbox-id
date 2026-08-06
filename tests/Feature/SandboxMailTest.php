<?php

declare(strict_types=1);

use App\Listeners\SuppressSandboxMail;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

function messageSending(): MessageSending
{
    $email = (new Email)->to('user@example.com')->subject('Hello')->text('hi');

    return new MessageSending($email);
}

it('cancels real mail from a sandbox environment but sends it from production', function (): void {
    $sandbox = Environment::query()->create(['name' => 'Sandbox', 'slug' => 'sbx', 'type' => 'sandbox', 'status' => 'active']);
    $prod = Environment::query()->create(['name' => 'Prod', 'slug' => 'prod', 'type' => 'production', 'status' => 'active']);
    $listener = app(SuppressSandboxMail::class);

    app(EnvironmentContext::class)->set($sandbox);
    expect($listener->handle(messageSending()))->toBeFalse();   // suppressed

    app(EnvironmentContext::class)->set($prod);
    expect($listener->handle(messageSending()))->toBeTrue();     // delivered
});

it('allows context-less mail (platform/operator sends are not env-scoped)', function (): void {
    // A send with no resolvable environment is platform mail (operator invites,
    // etc.) and must go out — suppression is a sandbox-only behaviour.
    app(EnvironmentContext::class)->set(GenericEnvironment::of('platform'));

    expect(app(SuppressSandboxMail::class)->handle(messageSending()))->toBeTrue();
});
