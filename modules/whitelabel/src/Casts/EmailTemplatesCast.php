<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Casts;

use Cbox\Id\Whitelabel\ValueObjects\EmailTemplates;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts the `email_templates` JSON column to {@see EmailTemplates}.
 *
 * @implements CastsAttributes<EmailTemplates, EmailTemplates|array<array-key, mixed>|null>
 */
class EmailTemplatesCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): EmailTemplates
    {
        $decoded = is_string($value)
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : $value;

        return is_array($decoded) ? EmailTemplates::fromArray($decoded) : EmailTemplates::empty();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $templates = match (true) {
            $value instanceof EmailTemplates => $value,
            is_array($value) => EmailTemplates::fromArray($value),
            default => EmailTemplates::empty(),
        };

        return [$key => json_encode($templates->toArray(), JSON_THROW_ON_ERROR)];
    }
}
