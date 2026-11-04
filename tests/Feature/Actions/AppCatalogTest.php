<?php

declare(strict_types=1);

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppCatalog\CatalogEntryData;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\ValueObjects\NewFeatureFlag;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDelegatedTokens;

function catalogInput(array $overrides = []): array
{
    return [...['version' => 0, 'name' => 'Cbox · Billing', 'launch_url' => 'https://billing.example.com/start', 'enabled' => true, 'position' => 10, 'audience' => 'everyone'], ...$overrides];
}

function catalogKey(): string
{
    return app(EnvironmentApiKeys::class)->issue('env_test', 'Catalog publisher', ['app_catalog:read', 'app_catalog:write'])->plaintext;
}

it('publishes versioned entries and refuses stale writes and deletes', function (): void {
    $token = catalogKey();
    $this->withToken($token)->putJson('/api/v1/app-catalog/billing', catalogInput())->assertOk()->assertJsonPath('data.version', 1);
    $this->withToken($token)->putJson('/api/v1/app-catalog/billing', catalogInput(['version' => 1]))->assertOk()->assertJsonPath('data.version', 1);
    $this->withToken($token)->putJson('/api/v1/app-catalog/billing', catalogInput(['version' => 1, 'enabled' => false]))->assertOk()->assertJsonPath('data.version', 2);
    $this->withToken($token)->putJson('/api/v1/app-catalog/billing', catalogInput(['version' => 1]))->assertConflict();
    $this->withToken($token)->deleteJson('/api/v1/app-catalog/billing', ['version' => 1])->assertConflict();
    $this->withToken($token)->getJson('/api/v1/app-catalog')->assertOk()->assertJsonPath('data.0.enabled', false);
    $this->withToken($token)->deleteJson('/api/v1/app-catalog/billing', ['version' => 2])->assertNoContent();
    expect(AuditEntry::query()->where('action', 'app_catalog.saved')->count())->toBe(2);
    $audit = AuditEntry::query()->where('action', 'app_catalog.deleted')->sole();
    expect($audit->actor_type)->toBe(ActorType::Service);
    expect($audit->actor_id)->not->toBeNull();
});

it('returns only the persons published apps and no administration metadata', function (): void {
    $ada = app(Subjects::class)->create('ada@catalog.example', 'Ada')->id;
    $other = app(Subjects::class)->create('other@catalog.example', 'Other')->id;
    $catalog = app(AppCatalog::class);
    foreach (['public' => [], 'private' => ['audience' => 'users', 'user_ids' => [$other]], 'mine' => ['audience' => 'users', 'user_ids' => [$ada], 'position' => 0], 'draft' => ['enabled' => false]] as $key => $input) {
        $catalog->save($key, CatalogEntryData::fromArray(catalogInput($input)));
    }
    FakeDelegatedTokens::install()->person('ada-catalog', $ada, ['account:apps:read']);
    $response = $this->withToken('ada-catalog')->getJson('/api/v1/me/apps')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.key', 'mine')->assertJsonPath('data.1.key', 'public')->assertJsonPath('meta.refresh_after', 60)->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('data.0'))->not->toHaveKeys(['user_ids', 'organization_ids', 'client_ids', 'required_feature', 'icon_data']);
    $this->withToken(catalogKey())->getJson('/api/v1/me/apps')->assertUnauthorized();
    FakeDelegatedTokens::install()->person('wrong-scope', $ada, ['account:profile:write']);
    $this->withToken('wrong-scope')->getJson('/api/v1/me/apps')->assertForbidden();
});

it('requires active membership in the selected organization', function (): void {
    $ada = app(Subjects::class)->create('ada@catalog.example', 'Ada')->id;
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    $catalog = app(AppCatalog::class);
    $catalog->save('acme', CatalogEntryData::fromArray(catalogInput(['audience' => 'organizations', 'organization_ids' => [$org->id]])));
    expect($catalog->forUser($ada))->toBe([]);
    app(Memberships::class)->add($org->id, $ada, MembershipRole::Member);
    expect($catalog->forUser($ada, $org->id))->toHaveCount(1);
    FakeDelegatedTokens::install()->person('ada-catalog', $ada, ['account:apps:read']);
    $this->withToken('ada-catalog')->getJson('/api/v1/me/apps?organization_id=foreign')->assertForbidden();
    app(Memberships::class)->remove($org->id, $ada);
    expect($catalog->forUser($ada))->toBe([]);
});

it('applies feature targeting and environment isolation', function (): void {
    $ada = app(Subjects::class)->create('ada@catalog.example', 'Ada')->id;
    app(FeatureFlags::class)->create(new NewFeatureFlag(key: 'beta', defaultValue: false));
    $catalog = app(AppCatalog::class);
    $catalog->save('beta', CatalogEntryData::fromArray(catalogInput(['required_feature' => 'beta'])));
    expect($catalog->forUser($ada))->toBe([]);
    app(EnvironmentContext::class)->set(new GenericEnvironment('elsewhere'));
    expect(CatalogEntry::query()->count())->toBe(0);
});

it('validates launch destinations, grants and image formats', function (array $invalid): void {
    $this->withToken(catalogKey())->putJson('/api/v1/app-catalog/billing', catalogInput($invalid))->assertUnprocessable();
})->with([
    [['launch_url' => 'javascript:alert(1)']], [['launch_url' => 'http://example.com']],
    [['launch_url' => 'https://user:pass@example.com']], [['launch_url' => 'https://example.com/#fragment']],
    [['icon' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=']], [['icon' => 'https://example.com/logo.png']],
    [['organization_ids' => ['foreign']]], [['user_ids' => ['foreign']]], [['client_ids' => ['foreign']]],
]);

it('serves content-addressed icons and rotates their URL when removed', function (): void {
    installedDeployment();
    $icon = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=';
    $token = catalogKey();
    $response = $this->withToken($token)->putJson('/api/v1/app-catalog/id', catalogInput(['icon' => $icon]))->assertOk();
    $url = $response->json('data.icon_url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->withToken($token)->putJson('/api/v1/app-catalog/id', catalogInput(['version' => 1, 'icon' => null]))->assertOk()->assertJsonPath('data.icon_url', null);
    $this->get($url)->assertNotFound();
});

it('reads a populated catalog without per-organization membership queries', function (): void {
    $ada = app(Subjects::class)->create('ada@catalog.example', 'Ada')->id;
    $catalog = app(AppCatalog::class);
    $catalog->save('billing', CatalogEntryData::fromArray(catalogInput()));
    $counts = [];
    foreach (range(1, 9) as $number) {
        $organization = app(Organizations::class)->create(new NewOrganization('Org '.$number, 'catalog-org-'.$number));
        app(Memberships::class)->add($organization->id, $ada, MembershipRole::Member);
        if (! in_array($number, [1, 9], true)) {
            continue;
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            expect($catalog->forUser($ada))->toHaveCount(1);
            $counts[] = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
    expect($counts[1])->toBe($counts[0]);
});
