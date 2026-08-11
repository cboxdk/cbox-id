<?php

declare(strict_types=1);

use App\Platform\Migration\LegacyLoginApprovals;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\ManifestSyncService;
use Cbox\Id\Migration\Contracts\LegacyCredentialSource;
use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;
use Cbox\Id\Migration\ValueObjects\LegacyLoginDeclaration;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cbox-id.migration.verify_url', false);
});

function declareLegacy(string $url = 'https://legacy.acme.test/verify'): void
{
    app(ManifestSyncService::class)->sync('client-a', new Manifest(
        version: 'v'.mt_rand(),
        permissions: [],
        roles: [],
        legacyLogin: new LegacyLoginDeclaration($url, str_repeat('s', 40)),
    ));
}

it('shows an operator what was declared and who declared it', function (): void {
    actingAsRole(MembershipRole::Owner);
    declareLegacy();

    Volt::test('console.legacy-login')
        ->assertSee('legacy.acme.test')
        ->assertSee('Awaiting approval');
});

/**
 * THE BINDING IS THE WHOLE FEATURE. Without it an operator could approve a declaration
 * and nothing would ever consult it — the screen would work and the migration would not.
 */
it('actually consults the endpoint once approved, and not before', function (): void {
    Http::fake(['*' => Http::response(['email' => 'ada@legacy.test', 'name' => 'Ada'], 200)]);

    declareLegacy();

    $source = app(LegacyCredentialSource::class);

    expect($source->verify('ada@legacy.test', 'pw'))->toBeNull();
    Http::assertNothingSent();

    actingAsRole(MembershipRole::Owner);
    Volt::test('console.legacy-login')->call('approve');

    expect($source->verify('ada@legacy.test', 'pw')?->email)->toBe('ada@legacy.test');
});

it('withdraws it again, leaving the declaration visible', function (): void {
    actingAsRole(MembershipRole::Owner);
    declareLegacy();

    Volt::test('console.legacy-login')->call('approve');
    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeTrue();

    Volt::test('console.legacy-login')->call('revoke');

    // Withdrawn, not deleted: an operator who revokes during an incident should still be
    // able to see what they revoked.
    $record = LegacyLoginDeclarationRecord::query()->first();
    expect($record?->isApproved())->toBeFalse()
        ->and($record?->url)->toBe('https://legacy.acme.test/verify');
});

/**
 * "Who agreed to send passwords there" is the first question anybody asks afterwards.
 */
it('records who approved it, and keeps the original moment', function (): void {
    // `actingAsRole` returns [subjectId, org] — a list, not a keyed array.
    [$subjectId] = actingAsRole(MembershipRole::Owner);
    declareLegacy();

    Volt::test('console.legacy-login')->call('approve');
    $first = LegacyLoginDeclarationRecord::query()->first();

    expect($first?->approved_by)->toBe($subjectId);

    // A second click must not overwrite when the URL joined the login path.
    app(LegacyLoginApprovals::class)->approve('somebody-else');

    expect(LegacyLoginDeclarationRecord::query()->first()?->approved_at?->toString())
        ->toBe($first?->approved_at?->toString());
});

it('refuses a member who may not administer', function (): void {
    actingAsRole(MembershipRole::Member);
    declareLegacy();

    try {
        Volt::test('console.legacy-login')->call('approve');
    } catch (Throwable) {
        // Either the authorization exception or Livewire's report of it.
    }

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse();
});
