<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\AppearanceCss;
use App\Platform\Appearance\Color;
use App\Platform\Appearance\ThemeFont;
use App\Platform\Appearance\ThemeMode;
use App\Platform\Appearance\ThemePreset;
use App\Platform\Appearance\ThemePresets;
use App\Platform\Appearance\ThemeRadius;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// ─────────────────────────── pure: presets ───────────────────────────

it('ships 5 complete, well-formed presets', function (): void {
    $presets = ThemePresets::all();
    expect($presets)->toHaveCount(5)->toHaveKeys(['cbox', 'midnight', 'minimal', 'warm', 'contrast']);

    foreach ($presets as $id => $p) {
        expect($p)->toBeInstanceOf(ThemePreset::class);
        expect($p->light)->toBeInstanceOf(ThemeMode::class);
        foreach ([$p->light, $p->dark] as $mode) {
            foreach ([$mode->primary, $mode->background, $mode->foreground, $mode->muted] as $hex) {
                expect(Appearance::hex($hex))->not->toBeNull("$id colours must be valid hex");
            }
        }
    }
});

// ─────────────────────────── pure: sanitization ───────────────────────────

it('coerces malformed appearance input to safe defaults (deny-by-default)', function (): void {
    $a = Appearance::fromArray([
        'preset' => 'totally-bogus',
        'radius' => '999px',
        'font' => 'comic-sans',
        'light' => ['primary' => 'javascript:alert(1)', 'background' => '#ffffff'],
        'dark' => [],
    ]);

    // Bad preset/radius/font fall back to the default preset's values…
    expect($a->preset)->toBe('cbox');
    expect($a->radius)->toBeInstanceOf(ThemeRadius::class);
    expect($a->font)->toBeInstanceOf(ThemeFont::class);
    // …a bad hex is replaced by the preset fallback, a good one is kept & lowercased.
    expect($a->light->primary)->toBe(ThemePresets::get('cbox')->light->primary);
    expect($a->light->background)->toBe('#ffffff');
    // Every mode is always a complete, typed ThemeMode.
    expect($a->dark)->toBeInstanceOf(ThemeMode::class);
});

it('honours a legacy brand_color as the primary when no appearance block exists', function (): void {
    $a = Appearance::fromSettings(['brand_color' => '#AB12CD']);
    expect($a->light->primary)->toBe('#ab12cd')
        ->and($a->dark->primary)->toBe('#ab12cd');
});

it('resolves the effective theme with org overriding the environment default', function (): void {
    $orgTheme = ['appearance' => Appearance::fromPreset('warm')->toArray()];
    $envTheme = ['appearance' => Appearance::fromPreset('midnight')->toArray()];

    // Org wins when it has one; else env; else null (platform default).
    expect(Appearance::effective($orgTheme, $envTheme)?->preset)->toBe('warm');
    expect(Appearance::effective(['brand_color' => null], $envTheme)?->preset)->toBe('midnight');
    expect(Appearance::effective(null, $envTheme)?->preset)->toBe('midnight');
    expect(Appearance::effective(null, null))->toBeNull();
});

it('round-trips through toArray/fromArray', function (): void {
    $a = Appearance::fromPreset('warm');
    expect(Appearance::fromArray($a->toArray()))->toEqual($a);
});

// ─────────────────────────── pure: resolver + colour ───────────────────────────

it('renders a coherent, safe CSS block for both modes', function (): void {
    $css = (string) AppearanceCss::render(Appearance::fromPreset('minimal'));

    expect($css)->toContain('<style id="cbox-appearance">')
        ->toContain(':root{')
        ->toContain("@media(prefers-color-scheme:dark){:root:not([data-theme='light'])")
        ->toContain(":root[data-theme='dark']")
        ->toContain('--accent:#111111')                          // light primary
        ->toContain('color-mix(in srgb,#111111 12%,transparent)') // derived soft
        ->toContain('--radius:0.25rem')
        ->toContain('--font-sans:');
    // Nothing that could break out of the <style> context.
    expect($css)->not->toContain('</style><')->not->toContain('javascript:');
});

it('computes WCAG contrast and picks a readable accent foreground', function (): void {
    expect(round(Color::contrastRatio('#ffffff', '#000000'), 1))->toBe(21.0);
    expect(Color::readableForeground('#111111'))->toBe('#ffffff');
    expect(Color::readableForeground('#f5f5f5'))->toBe('#151515');
});

// ─────────────────────────── feature: editor ───────────────────────────

if (! function_exists('signInOrg')) {
    function signInOrg(string $role = 'admin'): object
    {
        $subject = app(Subjects::class)->create('user@acme.test', 'User', 'supersecret123');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-appear'));
        app(Memberships::class)->add($org->id, $subject->id, $role);
        $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
        session([PlatformAuth::SESSION_KEY => $session->id]);
        app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::from($role));

        return $org;
    }
}

it('renders the editor for an admin', function (): void {
    signInOrg('admin');
    $this->get(route('appearance'))->assertOk()->assertSee('Live preview');
});

it('refuses the editor for a non-admin member', function (): void {
    signInOrg('member');
    $this->get(route('appearance'))->assertForbidden();
});

it('persists a saved theme to org settings and keeps brand_color in sync', function (): void {
    $org = signInOrg('admin');

    $theme = Appearance::fromPreset('warm')->toArray();
    $theme['light']['primary'] = '#123456';
    $theme['logo'] = 'https://acme.com/logo.svg';

    Volt::test('appearance')->call('save', $theme);

    $settings = app(Organizations::class)->find($org->id)->settings;
    expect($settings['appearance']['preset'])->toBe('warm')
        ->and($settings['appearance']['light']['primary'])->toBe('#123456')
        ->and($settings['brand_color'])->toBe('#123456')          // legacy mirror
        ->and($settings['brand_logo_url'])->toBe('https://acme.com/logo.svg');
});

it('rejects a non-https logo on save', function (): void {
    $org = signInOrg('admin');

    $theme = Appearance::fromPreset('cbox')->toArray();
    $theme['logo'] = 'http://insecure.example/logo.png';
    Volt::test('appearance')->call('save', $theme);

    expect(app(Organizations::class)->find($org->id)->settings['brand_logo_url'])->toBeNull();
});

// ─────────────────────────── feature: the hosted page applies it ───────────────────────────

it('injects the org theme into its branded sign-in page', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Themed', 'themed-co'));
    $appearance = Appearance::fromPreset('midnight')->toArray();
    $appearance['light']['primary'] = '#0055ff';
    app(Organizations::class)->updateSettings($org->id, ['appearance' => $appearance]);

    $this->get(route('login.branded', $org->slug))
        ->assertOk()
        ->assertSee('cbox-appearance')     // the injected style block
        ->assertSee('--accent:#0055ff', false);
});

it('leaves the platform default when an org has no custom appearance', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Plain', 'plain-co'));

    $this->get(route('login.branded', $org->slug))
        ->assertOk()
        ->assertDontSee('cbox-appearance');
});

// ─────────────────────────── feature: environment-level theme ───────────────────────────

if (! function_exists('appearanceEnvSetup')) {
    function appearanceEnvSetup(): string
    {
        $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
            accountName: 'Acme',
            ownerEmail: 'owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        config(['cbox-id.environments.default' => $r->environment->id]);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
        session()->put(EnvironmentAdminAuth::SESSION_KEY, $r->member->id);
        session()->put(EnvironmentAdminAuth::ENV_KEY, $r->environment->id);

        return $r->environment->id;
    }
}

it('saves an environment-level theme and applies it to the hosted sign-in', function (): void {
    $envId = appearanceEnvSetup();

    $theme = Appearance::fromPreset('midnight')->toArray();
    $theme['light']['primary'] = '#00aa88';

    Volt::test('environment.appearance')->call('save', $theme);

    // Persisted onto the environment…
    expect(Environment::find($envId)->settings['appearance']['light']['primary'])->toBe('#00aa88');

    // …and a plain env sign-in (no org) now carries the env default theme.
    $this->get('/login')->assertOk()->assertSee('--accent:#00aa88', false);
});

it('lets an organization override the environment default theme', function (): void {
    $envId = appearanceEnvSetup();

    // Environment default: a green primary.
    $env = Environment::find($envId);
    $envTheme = Appearance::fromPreset('midnight')->toArray();
    $envTheme['light']['primary'] = '#00aa88';
    $env->settings = array_merge(is_array($env->settings) ? $env->settings : [], ['appearance' => $envTheme]);
    $env->save();

    // Organization override: a different primary.
    $org = app(Organizations::class)->create(new NewOrganization('Acme Org', 'acme-ovr'));
    $orgTheme = Appearance::fromPreset('warm')->toArray();
    $orgTheme['light']['primary'] = '#ff3366';
    app(Organizations::class)->updateSettings($org->id, ['appearance' => $orgTheme]);

    // The org's branded sign-in shows the ORG colour, and NOT the environment's.
    $this->get(route('login.branded', $org->slug))
        ->assertOk()
        ->assertSee('--accent:#ff3366', false)
        ->assertDontSee('--accent:#00aa88', false);
});
