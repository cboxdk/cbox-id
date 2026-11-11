<?php

declare(strict_types=1);

namespace App\Platform\Environments;

use Cbox\Id\Organization\Models\Environment;
use Illuminate\Validation\ValidationException;

/**
 * NO ENVIRONMENT ANSWERS FOR THE PLATFORM'S OWN HOSTS.
 *
 * An environment is served at `{slug}.cboxid.com`, so one with the slug `api` would be
 * resolved for `api.cboxid.com` — the management API's host — and `app` for the
 * application's. The list is `cbox-id.reserved_environment_slugs`.
 *
 * No create path takes a slug from its caller: every one (provisioning, the operator's
 * create, the installer) derives it from a project or environment name, so a slug that
 * lands on the list gets a suffix rather than refusing a perfectly good name ("API" is a
 * fine project name). Changing an existing environment's slug to a reserved one is the
 * only place a person chooses one, and it is refused outright.
 */
final class ReservedEnvironmentSlugs
{
    /** @return list<string> */
    public static function all(): array
    {
        $configured = config('cbox-id.reserved_environment_slugs', []);

        return array_values(array_filter(array_map(
            static fn (mixed $slug): string => is_string($slug) ? mb_strtolower(trim($slug)) : '',
            is_array($configured) ? $configured : [],
        ), static fn (string $slug): bool => $slug !== ''));
    }

    public static function reserved(?string $slug): bool
    {
        return is_string($slug) && in_array(mb_strtolower(trim($slug)), self::all(), true);
    }

    /** The first free slug that is not reserved: `api` → `api-env`, then `api-env-2`, … */
    public static function avoid(string $slug): string
    {
        if (! self::reserved($slug)) {
            return $slug;
        }

        $base = $slug.'-env';
        $candidate = $base;
        $n = 2;

        while (Environment::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$n++;
        }

        return $candidate;
    }

    /** Wire both rules onto the model: every way an environment is created or renamed. */
    public static function guard(): void
    {
        Environment::creating(static function (Environment $environment): void {
            $slug = $environment->getAttribute('slug');

            if (is_string($slug) && self::reserved($slug)) {
                $environment->setAttribute('slug', self::avoid($slug));
            }
        });

        Environment::updating(static function (Environment $environment): void {
            $slug = $environment->getAttribute('slug');

            if ($environment->isDirty('slug') && is_string($slug) && self::reserved($slug)) {
                throw ValidationException::withMessages([
                    'slug' => __('validation.not_in', ['attribute' => 'slug']),
                ]);
            }
        });
    }
}
