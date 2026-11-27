<?php

declare(strict_types=1);

use App\Mail\ApprovalRequestMail;
use App\Platform\Agents\ApprovalMail;
use App\Platform\Agents\MailingBackchannelAuthentication;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Decorators\PushNotifyingBackchannelAuthentication;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Exceptions\CibaAccessDenied;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\BackchannelAuthenticationResult;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| An approval is mailed to the person no phone will tell.
|--------------------------------------------------------------------------
|
| A CIBA request and a held action are pushed to an enrolled Cbox Authenticator. Without
| one, the person heard nothing from Cbox ID. `cbox-id.approvals.email` mails them a link
| to their Approvals page: by default only when no phone can take the push.
*/

beforeEach(function (): void {
    Mail::fake();
    app()->instance(PushTransport::class, new FakePushTransport);
});

function approvalMailAgent(string $name = 'Release agent'): Client
{
    return app(ClientRegistry::class)->register(new NewClient($name, ClientType::Confidential, scopes: ['openid']))->client;
}

it('notifies a bound request only after its organization is accepted', function (): void {
    $subjectId = approvalMailPerson();
    $org = app(Organizations::class)->create(new NewOrganization('Sandbox', 'sandbox'));
    app(Memberships::class)->add($org->id, $subjectId, MembershipRole::Member);
    $client = app(ClientRegistry::class)->register(new NewClient('Infra', ClientType::Confidential, scopes: ['openid'], grantTypes: ['urn:openid:params:grant-type:ciba']))->client;
    config()->set('ciba.infra', ['client_id' => $client->client_id, 'environment_id' => $client->environment_id, 'resource' => 'https://infra.test/approval']);
    $ciba = app(BackchannelAuthentication::class);
    $pending = $ciba->requestForOrganization($client, ['openid'], $subjectId, $org->id, resource: 'https://infra.test/approval');
    Mail::assertQueued(ApprovalRequestMail::class, 1);
    expect($pending->organizationId)->toBe($org->id);

    Mail::fake();
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);
    approvalMailPhone($subjectId);
    $ciba->requestForOrganization($client, ['openid'], $subjectId, $org->id, resource: 'https://infra.test/approval');
    expect($transport->count())->toBe(1);
    Mail::assertNothingOutgoing();

    app(Memberships::class)->remove($org->id, $subjectId);
    expect(fn () => $ciba->requestForOrganization($client, ['openid'], $subjectId, $org->id, resource: 'https://infra.test/approval'))
        ->toThrow(CibaAccessDenied::class);
    expect($transport->count())->toBe(1);
    Mail::assertNothingOutgoing();
});

it('keeps a legacy decorator usable but refuses to discard an organization binding', function (string $decorator): void {
    $inner = Mockery::mock(BackchannelAuthentication::class);
    $client = approvalMailAgent();
    $result = new BackchannelAuthenticationResult('request', 'secret', approvalMailPerson(), 120, 5);
    $inner->shouldReceive('request')->once()->andReturn($result);
    $wrapped = app()->makeWith($decorator, ['inner' => $inner]);
    expect($wrapped->request($client, ['openid'], $result->subjectId))->toBe($result);
    expect(fn () => $wrapped->requestForOrganization($client, ['openid'], $result->subjectId, strtolower((string) Str::ulid())))
        ->toThrow(CibaAccessDenied::class);
})->with([MailingBackchannelAuthentication::class, PushNotifyingBackchannelAuthentication::class]);

function approvalMailPerson(string $email = 'alex@acme.test'): string
{
    return app(Subjects::class)->create($email, 'Alex', 'supersecret123')->id;
}

function approvalMailPhone(string $subjectId): Device
{
    config()->set('id-devices.enabled', true);

    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Alex iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();
    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    return $device;
}

it('mails a person with no phone a link to their Approvals page, without what the request is for', function (): void {
    $subjectId = approvalMailPerson();

    app(BackchannelAuthentication::class)->request(approvalMailAgent(), ['openid'], $subjectId, 'Transfer DKK 4,200 to ACME ApS');

    Mail::assertQueued(ApprovalRequestMail::class, function (ApprovalRequestMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('alex@acme.test')
            && $mail->app === 'Release agent'
            && $mail->url === route('approvals')
            && str_contains($html, 'Release agent')
            // The binding message is read signed in, never in an inbox preview.
            && ! str_contains($html, 'Transfer DKK');
    });
});

it('leaves the mail to the phone when one is enrolled', function (): void {
    $subjectId = approvalMailPerson();
    approvalMailPhone($subjectId);

    app(BackchannelAuthentication::class)->request(approvalMailAgent(), ['openid'], $subjectId, 'Deploy');

    Mail::assertNothingOutgoing();
});

it('mails anyway when the devices module would refuse this client a push', function (): void {
    $subjectId = approvalMailPerson();
    approvalMailPhone($subjectId);
    config()->set('id-devices.ciba.client_allowlist', ['some-other-client']);

    app(BackchannelAuthentication::class)->request(approvalMailAgent(), ['openid'], $subjectId, 'Deploy');

    Mail::assertQueued(ApprovalRequestMail::class, 1);
});

it('mails every request when set to always, and none when off', function (): void {
    $subjectId = approvalMailPerson();
    approvalMailPhone($subjectId);
    $client = approvalMailAgent();

    config()->set('cbox-id.approvals.email', 'always');
    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId, 'Deploy');
    Mail::assertQueued(ApprovalRequestMail::class, 1);

    config()->set('cbox-id.approvals.email', 'off');
    app(BackchannelAuthentication::class)->request(approvalMailAgent('Other agent'), ['openid'], approvalMailPerson('sam@acme.test'), 'Deploy');
    Mail::assertQueued(ApprovalRequestMail::class, 1);
});

it('withholds the mail past the per-person limit but still files the request', function (): void {
    $subjectId = approvalMailPerson();
    $client = approvalMailAgent();

    foreach (range(1, ApprovalMail::ATTEMPTS + 2) as $i) {
        app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId, 'Request '.$i);
    }

    Mail::assertQueued(ApprovalRequestMail::class, ApprovalMail::ATTEMPTS);
    expect(BackchannelAuthRequest::query()->where('user_id', $subjectId)->count())->toBe(ApprovalMail::ATTEMPTS + 2);
})->group('security');

it('never fails the request because the mail could not be sent', function (): void {
    $subjectId = approvalMailPerson();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP is down'));

    $result = app(BackchannelAuthentication::class)->request(approvalMailAgent(), ['openid'], $subjectId, 'Deploy');

    expect($result->subjectId)->toBe($subjectId);
});
