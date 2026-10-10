<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImages;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Appearance\InvalidBrandImage;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| The checker every door shares — console, management API, MCP — and the one place an
| uploaded image is judged by its BYTES rather than by what it calls itself.
*/

it('accepts a real PNG and keeps its sniffed type', function (): void {
    $upload = BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri());

    expect($upload->mime)->toBe('image/png')
        ->and($upload->extension)->toBe('png');
});

it('judges the bytes, not the declared type', function (): void {
    // Declared as JPEG, actually PNG: stored as what it is.
    $png = substr(pngDataUri(), strlen('data:image/png;base64,'));

    expect(BrandImageUpload::fromDataUri(BrandImage::Logo, 'data:image/jpeg;base64,'.$png)->mime)->toBe('image/png');
});

it('refuses what a sign-in page must never draw', function (string $uri, string $says): void {
    expect(fn () => BrandImageUpload::fromDataUri(BrandImage::Favicon, $uri))
        ->toThrow(InvalidBrandImage::class, $says);
})->with([
    'a remote URL' => ['https://tracker.example/pixel.png', 'Remote image URLs are not accepted'],
    'an SVG' => ['data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'SVG is not accepted'],
    'XML smuggled as PNG' => ['data:image/png;base64,'.base64_encode('<?xml version="1.0"?><svg/>'), 'SVG is not accepted'],
    'not base64' => ['data:image/png;base64,%%%', 'not valid base64'],
    'too large' => ['data:image/png;base64,'.base64_encode(str_repeat('a', 300 * 1024)), 'larger than 256 KB'],
    'a JPEG favicon' => ['data:image/jpeg;base64,'.base64_encode((function (): string {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    })()), 'Use a PNG, ICO or WebP'],
])->group('security');

/*
| Mail carries the uploaded logo as an absolute URL on this application — a remote one
| would be a read-receipt pixel in every inbox.
*/

it('draws the environment\'s uploaded logo in mail, from this application', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));

    $tenant['environment']->forceFill(['settings' => ['brand_logo_url' => 'https://tracker.example/logo.png']])->save();

    $html = (new MagicLinkMail('https://id.test/magic/abc'))->render();

    // Before an upload: the monogram, and never the remote URL.
    expect($html)->not->toContain('tracker.example')->not->toContain('<img');

    app(BrandImages::class)->store(BrandImageUpload::fromDataUri(BrandImage::Logo, pngDataUri()), null);

    $logo = (string) app(BrandImages::class)->absoluteUrl(BrandImage::Logo, null);

    expect($logo)->toStartWith('http')->toContain('/brand-assets/brand/')
        ->and((new MagicLinkMail('https://id.test/magic/abc'))->render())->toContain('<img src="'.$logo.'"');
});
