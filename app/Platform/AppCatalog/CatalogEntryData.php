<?php

declare(strict_types=1);

namespace App\Platform\AppCatalog;

use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Appearance\InvalidBrandImage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CatalogEntryData
{
    /**
     * @param  list<string>  $organizations
     * @param  list<string>  $users
     * @param  list<string>  $clients
     * @param  array{purpose?: string|null, hue?: string|null, admission_url?: string|null, promote_when_unavailable?: bool}  $shell  Only the switcher fields the caller sent: an omitted one is kept, so a client that predates them never clears them.
     */
    private function __construct(
        public string $name,
        public string $description,
        public string $launchUrl,
        public bool $enabled,
        public int $position,
        public string $audience,
        public array $organizations,
        public array $users,
        public array $clients,
        public ?string $requiredFeature,
        public bool $iconProvided,
        public ?string $icon,
        public bool $iconDarkProvided,
        public ?string $iconDark,
        public int $version,
        public array $shell = [],
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $input['name'] = trim(self::string($input, 'name'));
        Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:300'],
            'launch_url' => ['required', 'string', 'max:2048', 'url:https'],
            'enabled' => ['required', 'boolean'],
            'position' => ['required', 'integer', 'min:0', 'max:10000'],
            'audience' => ['required', 'in:everyone,organizations,users'],
            'organization_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'organization_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'user_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'user_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'client_ids' => ['sometimes', 'array', 'list', 'max:100'],
            'client_ids.*' => ['required', 'string', 'max:190', 'distinct'],
            'required_feature' => ['nullable', 'string', 'max:64'],
            'icon' => ['nullable', 'string', 'max:180000'],
            'icon_dark' => ['nullable', 'string', 'max:180000'],
            'version' => ['required', 'integer', 'min:0'],
            'purpose' => ['nullable', 'string', 'max:60'],
            'hue' => ['nullable', 'string', Rule::in(CatalogHue::values())],
            'admission_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'promote_when_unavailable' => ['sometimes', 'boolean'],
        ])->validate();
        $url = self::string($input, 'launch_url');
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['launch_url' => __('console.catalog_invalid_launch_url')]);
        }
        $shell = [];
        if (array_key_exists('purpose', $input)) {
            $shell['purpose'] = trim(self::string($input, 'purpose')) ?: null;
        }
        if (array_key_exists('hue', $input)) {
            $shell['hue'] = self::string($input, 'hue') ?: null;
        }
        if (array_key_exists('admission_url', $input)) {
            $shell['admission_url'] = self::admissionUrl(self::string($input, 'admission_url'));
        }
        if (array_key_exists('promote_when_unavailable', $input)) {
            $shell['promote_when_unavailable'] = filter_var($input['promote_when_unavailable'], FILTER_VALIDATE_BOOLEAN);
        }

        return new self(trim(self::string($input, 'name')), self::string($input, 'description'), $url,
            filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN), (int) self::string($input, 'position'), self::string($input, 'audience'),
            self::strings($input, 'organization_ids'), self::strings($input, 'user_ids'), self::strings($input, 'client_ids'),
            self::string($input, 'required_feature') ?: null, array_key_exists('icon', $input), self::icon($input, 'icon'),
            array_key_exists('icon_dark', $input), self::icon($input, 'icon_dark'), (int) self::string($input, 'version'), $shell);
    }

    /**
     * Where a person asks for an app they do not have yet. The browser follows it, never
     * ID's server, but it is still held to the public-web shape: HTTPS, a registrable host,
     * no credentials or fragment, and never a loopback, private, link-local or reserved
     * address that would point a person's browser into their own network.
     */
    private static function admissionUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        $internal = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            : ! str_contains($host, '.') || preg_match('/(\A|\.)(localhost|local|internal)\z/', $host) === 1;
        if (! is_array($parts) || $internal || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['admission_url' => __('console.catalog_invalid_admission_url')]);
        }

        return $url;
    }

    /**
     * A raster data URI, normalised, or null. SVG is refused on purpose: the icon is
     * served from ID's own origin and an SVG is a document that can carry script.
     *
     * @param  array<string, mixed>  $input
     */
    private static function icon(array $input, string $key): ?string
    {
        $icon = self::string($input, $key);
        if ($icon === '') {
            return null;
        }
        try {
            $image = BrandImageUpload::fromDataUri(BrandImage::Logo, $icon);
        } catch (InvalidBrandImage) {
            throw ValidationException::withMessages([$key => __('console.catalog_invalid_icon')]);
        }
        $size = getimagesizefromstring($image->bytes);
        if (strlen($image->bytes) > 131072 || $size === false || max($size[0], $size[1]) > 512) {
            throw ValidationException::withMessages([$key => __('console.catalog_invalid_icon')]);
        }

        return $image->dataUri();
    }

    /** @param array<string, mixed> $input */
    private static function string(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private static function strings(array $input, string $key): array
    {
        $value = $input[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
