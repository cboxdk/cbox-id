<?php

declare(strict_types=1);

/**
 * The icons crawlers fetch without reading any HTML.
 *
 * Password managers, link unfurlers and browsers restoring a tab all probe the
 * conventional root paths directly — they never parse the `<link rel="icon">` tags. The
 * project shipped Laravel's skeleton `favicon.ico`, which is a ZERO-BYTE file, so that
 * probe returned a perfectly valid 200 with nothing in it for the life of the project.
 * A 404 would at least have been noticed; an empty 200 looks like success to everyone.
 */
const ROOT_ICONS = [
    'favicon.ico' => 'image/x-icon',
    'apple-touch-icon.png' => 'image/png',
    'icon.png' => 'image/png',
];

it('serves a real icon at every path a crawler probes', function (): void {
    foreach (array_keys(ROOT_ICONS) as $file) {
        $path = public_path($file);

        expect(file_exists($path))->toBeTrue("public/{$file} is missing")
            // The whole bug in one assertion: existence is not the property that
            // matters, and it was the only one anything checked.
            ->and(filesize($path))->toBeGreaterThan(0, "public/{$file} is an empty file");
    }
});

it('serves icons that are actually images', function (): void {
    // A file with bytes in it is not necessarily an icon — a 4kb HTML error page has
    // bytes too, which is exactly what the missing paths were returning before.
    foreach (ROOT_ICONS as $file => $expected) {
        $mime = (string) mime_content_type(public_path($file));

        expect($mime)->toStartWith('image/', "public/{$file} is {$mime}, not an image");
    }
});

it('offers apple-touch-icon at the size Apple actually asks for', function (): void {
    // 180×180 is the current convention. The layouts previously pointed at a 128, which
    // renders soft on every device made in the last decade.
    $size = getimagesize(public_path('apple-touch-icon.png'));

    expect($size)->toBeArray()
        ->and($size[0])->toBe(180)
        ->and($size[1])->toBe(180);
});

it('points every layout at the root paths rather than at /brand', function (): void {
    // Five layouts, and before this they disagreed: two declared no apple-touch-icon at
    // all, and the ones that did pointed into /brand — which a crawler that never reads
    // the HTML will not find.
    $layouts = glob(resource_path('views/components/layouts/*.blade.php')) ?: [];
    $missing = [];

    foreach ($layouts as $layout) {
        $source = (string) file_get_contents($layout);

        // Only layouts that render a document head are in scope.
        if (! str_contains($source, 'rel="icon"')) {
            continue;
        }

        if (! str_contains($source, 'href="/favicon.ico"')
            || ! str_contains($source, 'href="/apple-touch-icon.png"')) {
            $missing[] = basename($layout);
        }
    }

    expect($missing)->toBe([]);
});
