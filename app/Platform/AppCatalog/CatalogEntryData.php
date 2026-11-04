<?php

declare(strict_types=1);

namespace App\Platform\AppCatalog;

use App\Platform\Appearance\BrandImage;
use App\Platform\Appearance\BrandImageUpload;
use App\Platform\Appearance\InvalidBrandImage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class CatalogEntryData
{
    /**
     * @param  list<string>  $organizations
     * @param  list<string>  $users
     * @param  list<string>  $clients
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
        public int $version,
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
            'version' => ['required', 'integer', 'min:0'],
        ])->validate();
        $url = self::string($input, 'launch_url');
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['launch_url' => 'Use an HTTPS launch URL without credentials or a fragment.']);
        }
        $icon = self::string($input, 'icon');
        if ($icon !== '') {
            try {
                $image = BrandImageUpload::fromDataUri(BrandImage::Logo, $icon);
                $size = getimagesizefromstring($image->bytes);
                if (strlen($image->bytes) > 131072 || $size === false || max($size[0], $size[1]) > 512) {
                    throw ValidationException::withMessages(['icon' => 'Use an icon up to 128 KiB and 512 × 512 pixels.']);
                }
                $icon = $image->dataUri();
            } catch (InvalidBrandImage $e) {
                throw ValidationException::withMessages(['icon' => $e->getMessage()]);
            }
        }

        return new self(trim(self::string($input, 'name')), self::string($input, 'description'), $url,
            filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN), (int) self::string($input, 'position'), self::string($input, 'audience'),
            self::strings($input, 'organization_ids'), self::strings($input, 'user_ids'), self::strings($input, 'client_ids'),
            self::string($input, 'required_feature') ?: null, array_key_exists('icon', $input), $icon ?: null, (int) self::string($input, 'version'));
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
