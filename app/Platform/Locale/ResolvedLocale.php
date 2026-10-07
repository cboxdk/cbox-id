<?php

declare(strict_types=1);

namespace App\Platform\Locale;

/** A language, and which of the sources {@see LocaleResolver} asks supplied it. */
final readonly class ResolvedLocale
{
    public function __construct(
        public HostedLocale $locale,
        public LocaleSource $source,
    ) {}
}
