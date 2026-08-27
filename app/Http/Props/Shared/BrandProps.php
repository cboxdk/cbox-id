<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Appearance\BrandContext;

/**
 * The organization whose brand this page is painted in, for the parts React draws — the
 * name on the sign-in card and the logo above it.
 *
 * The COLOURS are not here on purpose. They are already in the document as a token
 * override emitted by the root view, because a branded page that waits for React to
 * recolour it has already painted the wrong colour once.
 * {@see BrandContext}
 */
final readonly class BrandProps implements Prop
{
    public function __construct(
        public string $name,
        public ?string $logo,
    ) {}

    /**
     * @return array{name: string, logo: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'logo' => $this->logo,
        ];
    }
}
