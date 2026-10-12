<?php

declare(strict_types=1);

use App\Mail\AdminAssignedPasswordMail;
use App\Mail\BrandedMailable;
use App\Mail\CertificateExpiringMail;
use App\Mail\EmailVerificationMail;
use App\Mail\InvitationMail;
use App\Mail\MagicLinkMail;
use App\Mail\MailBrands;
use App\Mail\OneTimeCodeMail;
use App\Mail\OrganizationInviteMail;
use App\Mail\PasswordResetMail;
use App\Mail\PortalLinkMail;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Appearance\Color;
use App\Platform\Locale\HostedLocale;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\Whitelabel\Contracts\BrandProfiles;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Every mail a person gets is the brand's, in their language, in two parts
|--------------------------------------------------------------------------
| The bar for every message Cbox ID sends (see App\Mail\BrandedMailable): drawn in the
| brand of whoever the person was dealing with — its product name, its uploaded logo (on
| this application's own origin, never a remote image), its colour, its sender name and
| footer — in the recipient's language, as HTML WITH a plain-text alternative.
|
| Each mail is SENT here, through a real mailer onto the array transport, rather than
| rendered piecemeal: what is asserted is the message that would leave — its From line, its
| subject and both of its parts — in all six hosted languages.
*/

const MAIL_ACCENT = '#0f766e';

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    config(['mail.default' => 'array', 'mail.from.address' => 'no-reply@id.example', 'mail.from.name' => 'Cbox ID']);
});

/**
 * A vendor's environment as its administrators set it up: a sign-in theme in its own
 * colour, an uploaded logo, and the white-label product name and sender.
 */
function brandedMailEnvironment(): Environment
{
    multiTenantDeployment();
    platformRootEnvironment();

    $vendor = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Northwind Traders',
        ownerEmail: 'owner@northwind.test',
        ownerName: 'Northwind Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    $environment = serveOnTestHost($vendor->environment);
    $environment->forceFill(['name' => 'northwind-prod', 'settings' => ['brand_color' => MAIL_ACCENT]])->save();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));
    app(BrandImages::class)->store(BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri()), null);

    $profile = app(BrandProfiles::class)->forEnvironment();
    expect($profile)->not->toBeNull();
    $profile?->forceFill(['app_name' => 'Northwind', 'email_from_name' => 'Northwind Accounts'])->save();

    return $environment;
}

/** @return array<string, Closure(): BrandedMailable> */
function everyBrandedMail(): array
{
    return [
        'magic link' => fn (): BrandedMailable => new MagicLinkMail('https://id.example/magic/abc'),
        'password reset' => fn (): BrandedMailable => new PasswordResetMail('https://id.example/reset/abc'),
        'email verification' => fn (): BrandedMailable => new EmailVerificationMail('https://id.example/verify/abc'),
        'admin-assigned password' => fn (): BrandedMailable => new AdminAssignedPasswordMail('correct-horse', true, Carbon::parse('2026-10-08 15:00:00')),
        'invitation' => fn (): BrandedMailable => new InvitationMail('Acme', 'Ada Lovelace', 'https://id.example/i/abc', 'Member', 'Acme Dashboard'),
        'organization invite' => fn (): BrandedMailable => new OrganizationInviteMail('Acme', 'Ada Lovelace', 'https://id.example/o/abc', 'Admin'),
        'portal link' => fn (): BrandedMailable => new PortalLinkMail('Acme', 'https://id.example/portal/abc', ['sso', 'dsync'], Carbon::parse('2026-10-08 15:00:00')),
        'certificate expiring' => fn (): BrandedMailable => new CertificateExpiringMail('Acme', 'Acme Okta', Carbon::parse('2026-10-20'), 7),
        'certificate expired' => fn (): BrandedMailable => new CertificateExpiringMail('Acme', 'Acme Okta', Carbon::parse('2026-10-01'), -2),
        'sign-in code' => fn (): BrandedMailable => new OneTimeCodeMail('482913', 'login_step_up', 5),
        'device code' => fn (): BrandedMailable => new OneTimeCodeMail('482913', 'device_step_up', 5),
        'link code' => fn (): BrandedMailable => new OneTimeCodeMail('482913', 'link_identity', 5),
        'other code' => fn (): BrandedMailable => new OneTimeCodeMail('482913', 'something_else', 1),
    ];
}

/** Send through the real mailer and hand back the message that would have left. */
function sentMail(BrandedMailable $mail, string $locale): Email
{
    Mail::to('reader@example.test')->locale($locale)->send($mail);

    /** @var SentMessage|null $sent */
    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last();
    $email = $sent?->getOriginalMessage();

    expect($email)->toBeInstanceOf(Email::class);

    /** @var Email $email */
    return $email;
}

it('sends every mail in the brand, in every language, as HTML with a plain-text part', function (Closure $make, string $locale): void {
    $environment = brandedMailEnvironment();

    /** @var BrandedMailable $mail */
    $mail = $make();
    $email = sentMail($mail, $locale);

    $html = (string) $email->getHtmlBody();
    $text = (string) $email->getTextBody();
    $subject = (string) $email->getSubject();

    // Both parts, in the reader's language, with no raw catalogue key anywhere.
    expect($html)->toContain('<html lang="'.$locale.'"')
        ->and($text)->not->toBe('')
        ->and($subject)->not->toBe('');

    foreach ([$html, $text, $subject] as $part) {
        expect($part)->not->toMatch('/\bmail\.[a-z_]+\.[a-z_]+/');
    }

    // The brand: its name (never ours, never the environment's internal name) in both parts…
    expect($html)->toContain('Northwind')->not->toContain('Cbox ID')
        ->and($text)->toContain('Northwind')->not->toContain('Cbox ID')
        // …its colour, with readable text on it…
        ->and($html)->toContain(MAIL_ACCENT)
        // …its uploaded logo, named for a screen reader, on THIS application's origin…
        ->and($html)->toContain('alt="Northwind"');

    preg_match_all('/<img[^>]+src="([^"]+)"/', $html, $images);
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

    expect($images[1])->toHaveCount(1);

    foreach ($images[1] as $src) {
        expect(parse_url($src, PHP_URL_HOST))->toBe($appHost)
            ->and((string) parse_url($src, PHP_URL_PATH))->toStartWith('/brand-assets/');
    }

    // …and its sender name on the deployment's own address.
    expect($email->getFrom())->toHaveCount(1)
        ->and($email->getFrom()[0]->getName())->toBe('Northwind Accounts')
        ->and($email->getFrom()[0]->getAddress())->toBe('no-reply@id.example');

    // The text part is TEXT: no markup, nothing HTML-escaped.
    expect($text)->not->toMatch('/<[a-z][^>]*>/i')
        ->and($text)->not->toContain('&#039;')
        ->and($text)->not->toContain('&amp;');

    expect($environment->name)->toBe('northwind-prod');
})->with(everyBrandedMail())->with(HostedLocale::codes());

it('puts the code in the body, never in the subject or the preview line', function (string $purpose, string $locale): void {
    brandedMailEnvironment();

    $email = sentMail(new OneTimeCodeMail('482913', $purpose, 5), $locale);
    $html = (string) $email->getHtmlBody();

    expect((string) $email->getSubject())->not->toContain('482913')
        ->and($html)->toContain('482913')
        ->and((string) $email->getTextBody())->toContain('482913');

    // Big, monospaced, selectable in one go — and alone on its line.
    expect($html)->toMatch('#font-size:30px[^"]*user-select:all[^"]*">482913</p>#');

    // The preview line is the purpose sentence, and the code is not in it.
    preg_match('#<div style="display:none[^"]*">([^<]*)#', $html, $preheader);
    expect($preheader[1] ?? '')->not->toBe('')->not->toContain('482913');

    // It says how long the code lasts.
    expect((string) $email->getTextBody())->toContain('5');
})->with(['login_step_up', 'device_step_up', 'link_identity'])->with(HostedLocale::codes());

it('says what the code is for and what to do if it was not you, per flow', function (): void {
    brandedMailEnvironment();

    $signIn = sentMail(new OneTimeCodeMail('111111', 'login_step_up', 5), 'en');
    $device = sentMail(new OneTimeCodeMail('222222', 'device_step_up', 10), 'en');
    $link = sentMail(new OneTimeCodeMail('333333', 'link_identity', 5), 'en');

    expect($signIn->getSubject())->toBe('Your Northwind sign-in code')
        ->and((string) $signIn->getTextBody())->toContain('finish signing in to Northwind')->toContain('change its password')
        ->and($device->getSubject())->toBe('Your Northwind code to approve a device')
        ->and((string) $device->getTextBody())->toContain('approve their device')->toContain('10 minutes')
        ->and($link->getSubject())->toBe('Your Northwind code to link a sign-in method')
        ->and((string) $link->getTextBody())->toContain('attach their sign-in to your account');
});

it('draws an organization’s mail in that organization’s own brand', function (): void {
    brandedMailEnvironment();

    $organization = app(Organizations::class)->create(new NewOrganization('Contoso', 'contoso-mail-brand'));
    Organization::query()->whereKey($organization->id)->sole()->forceFill(['settings' => ['brand_color' => '#b91c1c']])->save();
    app(BrandImages::class)->store(BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri(60, 20)), $organization->id);

    $email = sentMail((new InvitationMail('Contoso', 'Ada', 'https://id.example/i/abc'))->forOrganization($organization->id), 'en');
    $html = (string) $email->getHtmlBody();

    $organizationLogo = (string) app(BrandImages::class)->url(BrandImage::Logo, $organization->id);
    $environmentLogo = (string) app(BrandImages::class)->url(BrandImage::Logo, null);

    expect($organizationLogo)->not->toBe($environmentLogo)
        ->and($html)->toContain($organizationLogo)
        ->and($html)->not->toContain($environmentLogo)
        ->and($html)->toContain('#b91c1c')
        ->and($html)->not->toContain(MAIL_ACCENT);
});

it('keeps text readable on whatever colour the brand chose', function (): void {
    brandedMailEnvironment();
    Environment::query()->whereKey(app(EnvironmentContext::class)->current()?->environmentKey())->sole()
        ->forceFill(['settings' => ['brand_color' => '#fde047']])->save();

    $brand = app(MailBrands::class)->current();

    // A pale yellow button gets dark text, and links drawn in the brand are darkened to 4.5:1.
    expect($brand->accent)->toBe('#fde047')
        ->and($brand->onAccent)->toBe('#151515')
        ->and(Color::contrastRatio($brand->onAccent, $brand->accent))->toBeGreaterThanOrEqual(4.5)
        ->and(Color::contrastRatio($brand->accentText, '#ffffff'))->toBeGreaterThanOrEqual(4.5);

    expect((string) sentMail(new MagicLinkMail('https://id.example/m'), 'en')->getHtmlBody())
        ->toContain('color:#151515');
});

it('falls back to the deployment’s own name, colour, monogram and sender without a brand', function (): void {
    installedDeployment();
    config(['cbox-id.branding.name' => 'Northwind Identity', 'mail.from.name' => 'Northwind Identity Mail']);

    $email = sentMail(new PasswordResetMail('https://id.example/reset/abc'), 'da');
    $html = (string) $email->getHtmlBody();

    expect($html)->toContain('Northwind Identity')
        ->and($html)->toContain('#2f62ea')
        ->and($html)->not->toContain('<img')
        // The monogram: the brand's initial on the brand's colour, hidden from a screen
        // reader because the name is written out beside it.
        ->and($html)->toMatch('#aria-hidden="true"[^>]*>N</td>#')
        ->and($email->getFrom()[0]->getName())->toBe('Northwind Identity Mail');
});

it('mints the logo on the deployment’s own origin, never the Host header', function (): void {
    brandedMailEnvironment();

    // A request on a host this deployment does not answer on — the poisoning case.
    app('request')->headers->set('Host', 'evil.example');
    app('url')->setRequest(app('request'));

    $logo = (string) app(MailBrands::class)->current()->logo;

    expect(parse_url($logo, PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));
});
