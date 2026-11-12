<?php

declare(strict_types=1);

use App\Mail\ApprovalRequestMail;
use App\Mail\OneTimeCodeMail;
use App\Platform\Agents\ApprovalMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Otp\Contracts\OtpChannels;
use Cbox\Id\Otp\ValueObjects\OtpDelivery;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| One process, two environments: the second job does not send in the first one's name
|--------------------------------------------------------------------------
|
| A queue worker and the scheduler serve one environment's job and then another's in the
| same process. Laravel drops what is `scoped` between jobs (the environment context, the
| hosted languages), but a SINGLETON that was handed one of those keeps the first job's.
| The one-time-code channel is cached by the framework's channel registry for the life of
| the process, and it was built with the brand and language services of whichever
| environment asked first: the second environment's sign-in code would have gone out with
| the first one's name, colour and language. The approval mail kept the first
| environment's language the same way.
|
| Today both are sent from a web request under php-fpm, where a process serves one request,
| so nobody received such a mail. These tests are for the day one is sent from a worker.
*/

/** @return array{northwind: Environment, globex: Environment} */
function twoBrandedEnvironments(): array
{
    multiTenantDeployment();
    platformRootEnvironment();
    $environments = [];

    foreach (['northwind' => ['Northwind', 'da', '#0f766e'], 'globex' => ['Globex', 'sv', '#7c3aed']] as $key => [$name, $locale, $colour]) {
        $environment = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: $name.' Traders',
            ownerEmail: "owner@{$key}.test",
            ownerName: $name.' Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ))->environment;
        // The environment's own name is its brand until it sets another.
        $environment->forceFill(['name' => $name, 'settings' => ['brand_color' => $colour, 'default_locale' => $locale]])->save();

        $environments[$key] = $environment;
    }

    return $environments;
}

/**
 * Run $callback the way a worker runs a job for $environment: what the last job left in
 * scoped instances is gone, and the environment is this job's.
 */
function asJobIn(Environment $environment, Closure $callback): mixed
{
    app()->forgetScopedInstances();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    return $callback();
}

function codeDelivery(string $recipient): OtpDelivery
{
    return new OtpDelivery('challenge-'.$recipient, $recipient, '123456', 'login_step_up', 'email', new DateTimeImmutable('+10 minutes'), 600);
}

it('sends each environment\'s sign-in code in its own brand and language from one process', function (): void {
    Mail::fake();
    $environments = twoBrandedEnvironments();

    asJobIn($environments['northwind'], fn () => app(OtpChannels::class)->channel('email')->deliver(codeDelivery('erik@northwind.test')));
    asJobIn($environments['globex'], fn () => app(OtpChannels::class)->channel('email')->deliver(codeDelivery('gunnar@globex.test')));

    $first = Mail::queued(OneTimeCodeMail::class, fn (OneTimeCodeMail $mail): bool => $mail->hasTo('erik@northwind.test'))->last();
    $second = Mail::queued(OneTimeCodeMail::class, fn (OneTimeCodeMail $mail): bool => $mail->hasTo('gunnar@globex.test'))->last();

    expect($first?->brand()->name)->toBe('Northwind')
        ->and($first?->locale)->toBe('da')
        ->and($second?->brand()->name)->toBe('Globex')
        ->and($second?->brand()->accent)->toBe('#7c3aed')
        ->and($second?->locale)->toBe('sv');
});

it('sends each environment\'s approval mail in its own language from one process', function (): void {
    Mail::fake();
    config(['cbox-id.approvals.email' => 'always']);
    $environments = twoBrandedEnvironments();
    $erik = asJobIn($environments['northwind'], fn (): string => app(Subjects::class)->create('erik@northwind.test', 'Erik', 'a-strong-password-1234')->id);

    // Built in the first job and held across both, as the back-channel authentication
    // singleton holds it.
    $approvals = asJobIn($environments['northwind'], fn (): ApprovalMail => app(ApprovalMail::class));
    asJobIn($environments['northwind'], fn () => $approvals->notify($erik, 'Agent', 'https://northwind.test/approve', new DateTimeImmutable('+5 minutes')));

    $gunnar = asJobIn($environments['globex'], fn (): string => app(Subjects::class)->create('gunnar@globex.test', 'Gunnar', 'a-strong-password-1234')->id);
    asJobIn($environments['globex'], fn () => $approvals->notify($gunnar, 'Agent', 'https://globex.test/approve', new DateTimeImmutable('+5 minutes')));

    $sent = Mail::queued(ApprovalRequestMail::class)
        ->mapWithKeys(fn (ApprovalRequestMail $mail): array => [$mail->to[0]['address'] => $mail->locale])
        ->all();

    expect($sent)->toBe(['erik@northwind.test' => 'da', 'gunnar@globex.test' => 'sv']);
});
