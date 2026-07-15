<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function streamsAdmin(string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create('siem@acme.test', 'SIEM Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-siem'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $org->id;
}

it('creates a SIEM stream and reveals its generated signing key once', function (): void {
    streamsAdmin();
    config(['siem.http.verify_url' => false]); // don't SSRF-block the test endpoint

    $component = Volt::test('audit-streams')
        ->set('name', 'Prod Splunk')
        ->set('destination', 'splunk_hec')
        ->set('endpointUrl', 'https://splunk.example.test/services/collector')
        ->set('auth', 'hmac')
        ->set('secret', '')
        ->call('create')
        ->assertHasNoErrors();

    // A generated HMAC key was revealed exactly once, and a stream row exists.
    expect($component->get('revealedSecret'))->toBeString()->not->toBe('');
    expect(AuditStream::query()->where('name', 'Prod Splunk')->exists())->toBeTrue();
});

it('disables a stream', function (): void {
    streamsAdmin();
    config(['siem.http.verify_url' => false]);

    $component = Volt::test('audit-streams')
        ->set('name', 'S')->set('destination', 'generic_json')
        ->set('endpointUrl', 'https://sink.example.test/in')->set('auth', 'none')
        ->call('create');

    $stream = AuditStream::query()->where('name', 'S')->firstOrFail();
    expect($stream->enabled)->toBeTrue();

    $component->call('disable', $stream->id)->assertHasNoErrors();

    expect(AuditStream::query()->whereKey($stream->id)->firstOrFail()->enabled)->toBeFalse();
});

it('forbids a non-admin member', function (): void {
    streamsAdmin('member');

    Volt::test('audit-streams')->assertForbidden();
});
