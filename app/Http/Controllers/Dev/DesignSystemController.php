<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * THE DESIGN SYSTEM GALLERY — every primitive on one page, in both themes.
 *
 * It exists because the test suite cannot see whether a control is DRAWN. A component
 * test asserts that a switch has `role="switch"` and toggles; it cannot tell you the
 * thumb is invisible against the track in dark mode, or that a panel header collapses to
 * three words at 375px. Those are the failures this product has actually shipped, and the
 * only thing that catches them is looking.
 *
 * LOCAL ONLY, and gated in the ROUTE rather than here — see routes/web.php. A page that
 * enumerates the console's controls is not dangerous, but it is not part of the product
 * either, and a surface that exists in production "because it is harmless" is how a
 * deployment ends up with doors nobody reviewed. `local` is a claim only a developer's
 * own machine makes; a built image never does.
 */
final readonly class DesignSystemController
{
    public function __construct(private ResponseFactory $inertia) {}

    public function __invoke(): Response
    {
        return $this->inertia->render('dev/design-system');
    }
}
