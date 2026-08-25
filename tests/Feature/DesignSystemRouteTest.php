<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * The design-system gallery is a development surface, and it must not exist anywhere
 * else.
 *
 * There is nothing secret on the page — it renders sample data through the console's own
 * components and reads nothing. The reason to hold this is not disclosure, it is that a
 * deployment should serve exactly the surfaces it was reviewed for, and "harmless" is how
 * a route ends up outliving the reason it was added.
 *
 * The suite runs as `testing`, so this is a real assertion rather than a tautology:
 * deleting the `app()->environment('local')` guard in routes/web.php makes both cases
 * below fail.
 */
it('does not register the gallery outside a local install', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(Route::has('dev.design-system'))->toBeFalse();
});

it('answers 404 at the gallery path outside a local install', function (): void {
    $this->get('/dev/design-system')->assertNotFound();
})->group('security');
