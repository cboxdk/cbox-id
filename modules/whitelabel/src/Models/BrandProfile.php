<?php

declare(strict_types=1);

namespace Cbox\Id\Whitelabel\Models;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\Environment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's branding: palette, logo/favicon, app name and email sender. Hard-scoped
 * to its {@see Environment} via BelongsToEnvironment,
 * so a profile in one environment is never visible or writable from another. A row
 * with a null `organization_id` is the environment-wide default; a set one overrides
 * it for a single organization.
 *
 * @property int $id
 * @property string $environment_id
 * @property string|null $organization_id
 * @property string|null $name
 * @property string|null $logo_url
 * @property string|null $favicon_url
 * @property array<string, string> $palette
 * @property string|null $app_name
 * @property string|null $email_from_name
 * @property array<string, mixed> $email_templates
 */
class BrandProfile extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;

    protected $table = 'whitelabel_brand_profiles';

    protected $guarded = [];

    protected $attributes = [
        'palette' => '{}',
        'email_templates' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'palette' => 'array',
            'email_templates' => 'array',
        ];
    }
}
