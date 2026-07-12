<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The social identity providers the platform can offer. A provider is only
 * available once the operator has configured its OAuth credentials, so the login
 * screen never shows a dead button.
 */
final class SocialProviders
{
    /** @var array<string, string> key => display label */
    public const PROVIDERS = [
        'google' => 'Google',
        'github' => 'GitHub',
        'microsoft' => 'Microsoft',
    ];

    /**
     * @return array<string, string> configured provider key => label
     */
    public static function configured(): array
    {
        return array_filter(
            self::PROVIDERS,
            static fn (string $label, string $key): bool => filled(config("services.{$key}.client_id")),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public static function isConfigured(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS)
            && filled(config("services.{$provider}.client_id"));
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? ucfirst($provider);
    }
}
