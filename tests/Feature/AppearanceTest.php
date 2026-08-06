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
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
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
    function signInOrg(MembershipRole $role = MembershipRole::Admin): object
    {
        $subject = app(Subjects::class)->create('user@acme.test', 'User', 'supersecret123');
        $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-appear'));
        app(Memberships::class)->add($org->id, $subject->id, $role);
        $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
        session([PlatformAuth::SESSION_KEY => $session->id]);
        app(CurrentUser::class)->set($subject, $session, $org, $role);

        return $org;
    }
}

it('renders the editor for an admin', function (): void {
    signInOrg(MembershipRole::Admin);
    $this->get(route('appearance'))->assertOk()->assertSee('Live preview');
});

it('refuses the editor for a non-admin member', function (): void {
    signInOrg(MembershipRole::Member);
    $this->get(route('appearance'))->assertForbidden();
});

it('persists a saved theme to org settings and keeps brand_color in sync', function (): void {
    $org = signInOrg(MembershipRole::Admin);

    $theme = Appearance::fromPreset('warm')->toArray();
    $theme['light']['primary'] = '#123456';
    $theme['logo'] = 'https://acme.com/logo.svg';

    Volt::test('console.appearance')->call('save', $theme);

    $settings = app(Organizations::class)->find($org->id)->settings;
    expect($settings['appearance']['preset'])->toBe('warm')
        ->and($settings['appearance']['light']['primary'])->toBe('#123456')
        ->and($settings['brand_color'])->toBe('#123456')          // legacy mirror
        ->and($settings['brand_logo_url'])->toBe('https://acme.com/logo.svg');
});

it('rejects a non-https logo on save', function (): void {
    $org = signInOrg(MembershipRole::Admin);

    $theme = Appearance::fromPreset('cbox')->toArray();
    $theme['logo'] = 'http://insecure.example/logo.png';
    Volt::test('console.appearance')->call('save', $theme);

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
        platformRootEnvironment();
        $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: 'owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        ));

        serveOnTestHost($r->environment);
        app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
        actAsEnvironmentAdmin($r->owner->id, $r->environment->id);

        return $r->environment->id;
    }
}

it('saves an environment-level theme and applies it to the hosted sign-in', function (): void {
    $envId = appearanceEnvSetup();

    $theme = Appearance::fromPreset('midnight')->toArray();
    $theme['light']['primary'] = '#00aa88';

    Volt::test('console.appearance')->call('save', $theme);

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

/**
 * A brand colour is chosen to look right as a button fill, where white or near-black
 * sits on top of it. Used the other way round — as link and icon TEXT on the page
 * ground — the same colour can land near 3:1 and fail AA for body text. The platform's
 * own dark accent measured 3.08:1 on the card and 2.82:1 on its soft fill, and every
 * coloured link in dark mode was rendered in it.
 *
 * `readableOn` walks the SAME hue toward the ground's opposite so a tenant's blue stays
 * their blue, just a legible one.
 */
it('finds a legible shade of a brand colour without changing its hue', function (): void {
    // A mid blue on a dark ground: too dim to read, and it must be brightened.
    $dim = Color::readableOn('#3b6fd4', '#1e1e21');

    expect(Color::contrastRatio('#3b6fd4', '#1e1e21'))->toBeLessThan(4.5)
        ->and(Color::contrastRatio($dim, '#1e1e21'))->toBeGreaterThanOrEqual(4.5);

    // Still blue: the blue channel stays the largest of the three.
    [$r, $g, $b] = [hexdec(substr($dim, 1, 2)), hexdec(substr($dim, 3, 2)), hexdec(substr($dim, 5, 2))];
    expect($b)->toBeGreaterThan($r)->and($b)->toBeGreaterThan($g);

    // Already legible: returned untouched, so a tenant whose colour is fine keeps it
    // exactly.
    expect(Color::readableOn('#1e40af', '#ffffff'))->toBe('#1e40af');

    // Nowhere left to go — white on white. Honest best effort rather than an exception
    // or a substituted colour the tenant never picked.
    expect(Color::readableOn('#ffffff', '#ffffff'))->toBeString();
});

/**
 * The token has to be EMITTED per tenant, not merely used. It is not in the base
 * override set, so on a white-labeled page it would otherwise fall through to the
 * platform's own blue — readable, but not the customer's colour, which is a worse bug
 * than the contrast one it fixes.
 */
it('emits a legible accent for the tenant colour in both modes', function (): void {
    $appearance = new Appearance(
        'contrast',
        ThemeRadius::Medium,
        ThemeFont::System,
        new ThemeMode('#3b6fd4', '#ffffff', '#111111', '#666666'),
        new ThemeMode('#3b6fd4', '#1e1e21', '#f5f5f5', '#a0a0a0'),
    );

    $css = (string) AppearanceCss::render($appearance);

    expect($css)->toContain('--accent-strong:');

    // Pull each mode's value out of the emitted block and check it against that mode's
    // own background — the whole point is that the two modes get different answers.
    // Three occurrences, not two: dark is emitted for both the media query and the
    // explicit data-theme override.
    preg_match_all('/--accent-strong:(#[0-9a-f]{6});/i', $css, $matches);
    [$lightAccent, $darkAccent] = [$matches[1][0], $matches[1][1]];

    expect($matches[1])->toHaveCount(3)
        ->and($matches[1][2])->toBe($darkAccent);

    expect(Color::contrastRatio($lightAccent, '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(Color::contrastRatio($darkAccent, '#1e1e21'))->toBeGreaterThanOrEqual(4.5)
        ->and($lightAccent)->not->toBe($darkAccent);
});

/**
 * A theme nobody can read is refused, not warned about.
 *
 * `hex()` validated the FORMAT, so a "light" mode with a near-black background was
 * perfectly acceptable — and combined with the tokens that were not emitted per tenant,
 * a customer could end up with near-white text on the platform's beige at about 1.02:1.
 * The people who then cannot read the sign-in page are not the admin picking colours;
 * they are that organization's users, which is exactly why a dismissible warning is the
 * wrong shape for this.
 */
it('refuses to save a theme whose text cannot be read on its background', function (): void {
    gateAdmin('contrast-gate');

    $unreadable = [
        'radius' => '0.5rem',
        'font' => 'system',
        'light' => ['primary' => '#3b6fd4', 'background' => '#101014', 'foreground' => '#141418', 'muted' => '#16161a'],
        'dark' => ['primary' => '#3b6fd4', 'background' => '#1e1e21', 'foreground' => '#f5f5f5', 'muted' => '#a0a0a0'],
    ];

    Volt::test('console.appearance')->call('save', $unreadable);

    $settings = app(Organizations::class)->find(app(CurrentUser::class)->organizationId() ?? '')?->settings ?? [];

    expect($settings['appearance'] ?? null)->toBeNull('an unreadable palette was saved');
});

it('saves a legible one', function (): void {
    gateAdmin('contrast-ok');

    Volt::test('console.appearance')->call('save', [
        'radius' => '0.5rem',
        'font' => 'system',
        'light' => ['primary' => '#1e40af', 'background' => '#ffffff', 'foreground' => '#111111', 'muted' => '#5a5a5a'],
        'dark' => ['primary' => '#8fa8e0', 'background' => '#1e1e21', 'foreground' => '#f5f5f5', 'muted' => '#a8a8a8'],
    ]);

    $settings = app(Organizations::class)->find(app(CurrentUser::class)->organizationId() ?? '')?->settings ?? [];

    expect($settings['appearance'] ?? null)->not->toBeNull();
});
