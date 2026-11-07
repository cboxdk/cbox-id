<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

use App\Platform\Actions\ActionMessage;
use RuntimeException;

/**
 * An upload that is not an image this platform will serve on a sign-in page — the wrong
 * format, too large, an SVG, or bytes that do not decode as what they claim to be. The
 * message is written for the administrator who chose the file.
 */
final class InvalidBrandImage extends RuntimeException
{
    public function __construct(public readonly BrandImage $kind, public readonly string|ActionMessage $sourceMessage)
    {
        parent::__construct($sourceMessage instanceof ActionMessage ? $sourceMessage->render('en') : $sourceMessage);
    }
}
