<?php

declare(strict_types=1);

namespace App\Platform\Connect;

use App\Platform\AppKind;

/**
 * What the developer is building, as the quickstart's first question asks it — the one
 * answer the app's kind, its localhost redirect and every snippet after it follow from.
 *
 * Each redirect is the framework's own dev-server port, so the app created for it works the
 * first time `npm run dev` (or its equivalent) does — no editing the redirect URI before the
 * first sign-in, which is where a quickstart usually loses people.
 */
enum QuickstartFramework: string
{
    case NextJs = 'nextjs';
    case React = 'react';
    case Laravel = 'laravel';
    case Nuxt = 'nuxt';
    case Go = 'go';
    case Python = 'python';

    public function label(): string
    {
        return match ($this) {
            self::NextJs => 'Next.js',
            self::React => 'React',
            self::Laravel => 'Laravel',
            self::Nuxt => 'Nuxt',
            self::Go => 'Go',
            self::Python => 'Python',
        };
    }

    /** A React app runs in the browser and cannot keep a secret; everything else has a server. */
    public function kind(): AppKind
    {
        return $this === self::React ? AppKind::SpaOrMobile : AppKind::WebApp;
    }

    /** Where the framework's dev server answers the callback. */
    public function redirectUri(): string
    {
        return match ($this) {
            self::NextJs, self::Nuxt => 'http://localhost:3000/auth/callback',
            self::React => 'http://localhost:5173/callback',
            self::Laravel => 'http://localhost:8000/auth/callback',
            self::Go => 'http://localhost:8080/auth/callback',
            self::Python => 'http://localhost:5000/auth/callback',
        };
    }

    /** How to run it once the snippet is in place — the moment the page starts waiting. */
    public function run(): string
    {
        return match ($this) {
            self::NextJs, self::Nuxt => 'npm run dev',
            self::React => 'npm run dev',
            self::Laravel => 'php artisan serve',
            self::Go => 'go run .',
            self::Python => 'flask --app app run',
        };
    }
}
